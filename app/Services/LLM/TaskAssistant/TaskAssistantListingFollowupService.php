<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\Prioritization\AssistantCandidateProvider;
use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * Structured follow-up answers for multiturn questions about a recent listing or schedule
 * (e.g. "are those the most urgent?").
 */
final class TaskAssistantListingFollowupService
{
    public function __construct(
        private readonly AssistantCandidateProvider $candidateProvider,
        private readonly TaskAssistantTaskChoiceConstraintsExtractor $constraintsExtractor,
        private readonly TaskPrioritizationService $prioritizationService,
    ) {}

    /**
     * @param  list<array{entity_type: string, entity_id: int, title: string}>  $comparedEntities
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    public function generate(
        User $user,
        TaskAssistantThread $thread,
        string $userMessage,
        array $comparedEntities,
    ): array {
        $comparedEntities = $this->normalizeComparedEntities($comparedEntities);
        if ($comparedEntities === []) {
            return [
                'valid' => false,
                'data' => [],
                'errors' => ['no_compared_entities'],
            ];
        }

        $snapshot = $this->candidateProvider->candidatesForUser($user, taskLimit: 200);
        $context = $this->constraintsExtractor->extract($userMessage);
        $ranked = $this->prioritizationService->prioritizeFocus($snapshot, $context);

        $analysis = $this->analyzeAgainstRanking($comparedEntities, $ranked);
        $factsJson = json_encode([
            'verdict' => $analysis['verdict'],
            'compared_items' => $comparedEntities,
            'more_urgent_alternatives' => $analysis['more_urgent_alternatives'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $narrative = $this->inferNarrativeWithOptionalLlm($userMessage, $factsJson, $analysis);

        $data = [
            'verdict' => $analysis['verdict'],
            'compared_items' => $comparedEntities,
            'more_urgent_alternatives' => $analysis['more_urgent_alternatives'],
            'framing' => $narrative['framing'],
            'rationale' => $narrative['rationale'],
            'caveats' => $narrative['caveats'],
            'next_options' => $narrative['next_options'],
            'next_options_chip_texts' => $narrative['next_options_chip_texts'],
        ];

        return [
            'valid' => true,
            'data' => $data,
            'errors' => [],
        ];
    }

    /**
     * @param  list<array{entity_type: string, entity_id: int, title: string}>  $compared
     * @param  list<mixed>  $ranked
     * @return array{verdict: string, more_urgent_alternatives: list<array{entity_type: string, entity_id: int, title: string, reason_short: string}>}
     */
    private function analyzeAgainstRanking(array $compared, array $ranked): array
    {
        $positions = [];
        foreach ($ranked as $i => $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $type = (string) ($candidate['type'] ?? 'task');
            $id = (int) ($candidate['id'] ?? 0);
            if ($id <= 0 || $type === '') {
                continue;
            }
            $key = $type.':'.$id;
            if (! array_key_exists($key, $positions)) {
                $positions[$key] = $i;
            }
        }

        $comparedKeys = [];
        foreach ($compared as $entity) {
            $type = (string) ($entity['entity_type'] ?? '');
            $id = (int) ($entity['entity_id'] ?? 0);
            if ($type === '' || $id <= 0) {
                continue;
            }
            $comparedKeys[] = $type.':'.$id;
        }

        $indices = [];
        foreach ($comparedKeys as $key) {
            $indices[] = $positions[$key] ?? null;
        }

        $finite = array_values(array_filter($indices, static fn (?int $x): bool => $x !== null));
        $moreUrgent = $this->findMoreUrgentAlternatives($ranked, $comparedKeys, $finite);

        $n = count($comparedKeys);
        if ($n === 0 || count($finite) < $n) {
            return [
                'verdict' => 'partial',
                'more_urgent_alternatives' => $moreUrgent,
            ];
        }

        $bandSize = max(8, $n * 3);
        $maxI = max($finite);
        $minI = min($finite);

        $orderPreserved = true;
        $last = -1;
        foreach ($finite as $idx) {
            if ($idx < $last) {
                $orderPreserved = false;
                break;
            }
            $last = $idx;
        }

        $allInBand = $maxI < $bandSize;
        $anyInBand = $minI < $bandSize;

        if ($allInBand && $orderPreserved) {
            $verdict = 'yes';
        } elseif (! $anyInBand) {
            $verdict = 'no';
        } else {
            $verdict = 'partial';
        }

        return [
            'verdict' => $verdict,
            'more_urgent_alternatives' => $moreUrgent,
        ];
    }

    /**
     * @param  list<mixed>  $ranked
     * @param  list<string>  $comparedKeys
     * @param  list<int>  $finiteIndices
     * @return list<array{entity_type: string, entity_id: int, title: string, reason_short: string}>
     */
    private function findMoreUrgentAlternatives(array $ranked, array $comparedKeys, array $finiteIndices): array
    {
        if ($finiteIndices === []) {
            return [];
        }

        $worst = max($finiteIndices);
        if ($worst <= 0) {
            return [];
        }

        $out = [];
        foreach (array_slice($ranked, 0, $worst) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $type = (string) ($candidate['type'] ?? 'task');
            $id = (int) ($candidate['id'] ?? 0);
            $title = trim((string) ($candidate['title'] ?? ''));
            if ($id <= 0 || $title === '') {
                continue;
            }
            $key = $type.':'.$id;
            if (in_array($key, $comparedKeys, true)) {
                continue;
            }
            $out[] = [
                'entity_type' => $type,
                'entity_id' => $id,
                'title' => $title,
                'reason_short' => 'Ranked ahead in your workspace snapshot.',
            ];
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array{verdict: string, more_urgent_alternatives: list<array<string, mixed>>}  $analysis
     * @return array{framing: string, rationale: string, caveats: ?string, next_options: string, next_options_chip_texts: list<string>}
     */
    private function inferNarrativeWithOptionalLlm(string $userMessage, string $factsJson, array $analysis): array
    {
        $fallback = $this->deterministicNarrative($userMessage, $analysis);

        try {
            $response = Prism::structured()
                ->using($this->resolveProvider(), $this->resolveModel())
                ->withPrompt($this->buildNarrativePrompt($userMessage, $factsJson))
                ->withSchema($this->narrativeSchema())
                ->withClientOptions($this->resolveClientOptions())
                ->asStructured();

            $structured = $response->structured ?? [];
            $structured = is_array($structured) ? $structured : [];

            $framing = trim((string) ($structured['framing'] ?? ''));
            $rationale = trim((string) ($structured['rationale'] ?? ''));
            $caveatsRaw = $structured['caveats'] ?? null;
            $caveats = is_string($caveatsRaw) ? trim($caveatsRaw) : null;
            if ($caveats === '') {
                $caveats = null;
            }
            $next = trim((string) ($structured['next_options'] ?? ''));
            $chips = $structured['next_options_chip_texts'] ?? [];
            $chips = is_array($chips) ? array_values(array_filter(array_map(
                static fn (mixed $c): string => trim((string) $c),
                $chips
            ), static fn (string $s): bool => $s !== '')) : [];

            if ($framing === '' || $rationale === '' || $next === '' || count($chips) < 2) {
                return $fallback;
            }

            return [
                'framing' => $framing,
                'rationale' => $rationale,
                'caveats' => $caveats,
                'next_options' => $next,
                'next_options_chip_texts' => array_slice($chips, 0, 2),
            ];
        } catch (\Throwable $e) {
            Log::warning('task-assistant.listing_followup.narrative_failed', [
                'layer' => 'listing_followup',
                'thread_id' => app()->bound('task_assistant.thread_id') ? app('task_assistant.thread_id') : null,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * @param  array{verdict: string, more_urgent_alternatives: list<array<string, mixed>>}  $analysis
     * @return array{framing: string, rationale: string, caveats: ?string, next_options: string, next_options_chip_texts: list<string>}
     */
    private function deterministicNarrative(string $userMessage, array $analysis): array
    {
        $verdict = $analysis['verdict'];
        $alts = $analysis['more_urgent_alternatives'];

        $framing = match ($verdict) {
            'yes' => 'Based on how your workspace is ranked right now, those items sit in a strong urgency band together.',
            'no' => 'Based on the current ranking snapshot, there are other items that score higher for urgency than everything in that set.',
            default => 'It is partially aligned: some of what you pointed at matches the top urgency band, but the picture is mixed.',
        };

        $rationale = match ($verdict) {
            'yes' => 'I compared them to the same ordering your assistant uses when it prioritizes or schedules tasks, and their relative order matches that ordering.',
            'no' => 'I compared them to that same ordering. At least one item you asked about sits lower than other work that would usually surface first.',
            default => 'Either something is outside the slice we ranked here, or the ordering does not fully match that snapshot for this message.',
        };

        if ($alts !== [] && $verdict !== 'yes') {
            $titles = array_map(static fn (array $a): string => (string) ($a['title'] ?? ''), $alts);
            $titles = array_values(array_filter($titles, static fn (string $t): bool => $t !== ''));
            if ($titles !== []) {
                $rationale .= ' Examples ranked ahead: '.implode('; ', array_slice($titles, 0, 3)).'.';
            }
        }

        $caveats = 'Rankings use due dates, priority, and complexity—your gut might still disagree, and events use a different shape than tasks.';

        return [
            'framing' => $framing,
            'rationale' => $rationale,
            'caveats' => $caveats,
            'next_options' => 'If you want, we can reschedule those items, refresh a prioritized list, or dig into one task.',
            'next_options_chip_texts' => [
                'Schedule them differently',
                'Show me my top tasks again',
            ],
        ];
    }

    private function buildNarrativePrompt(string $userMessage, string $factsJson): string
    {
        return <<<PROMPT
You help a student task assistant answer a follow-up about a RECENT list or schedule.

FACTS (authoritative; do not contradict the verdict):
{$factsJson}

USER FOLLOW-UP:
"{$userMessage}"

Write short, supportive student-facing prose. Use "you/your". Do not mention JSON, schemas, databases, or "the ranker"—say "your list" or "how things are ordered right now".

- framing: one short paragraph acknowledging their question.
- rationale: one paragraph explaining the verdict in plain language, using only the facts (you may name task titles from compared_items or more_urgent_alternatives).
- caveats: one sentence on limitations (ranking is heuristic; events vs tasks), or null if nothing extra is needed.
- next_options: one sentence offering next steps.
- next_options_chip_texts: exactly 2 short button labels (max 90 chars each), actionable for scheduling or re-prioritizing.
PROMPT;
    }

    private function narrativeSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'task_assistant_listing_followup_narrative',
            description: 'Student-visible narrative for a listing follow-up answer.',
            properties: [
                new StringSchema(name: 'framing', description: 'One short paragraph.'),
                new StringSchema(name: 'rationale', description: 'One paragraph explaining the verdict.'),
                new StringSchema(name: 'caveats', description: 'One sentence or empty.'),
                new StringSchema(name: 'next_options', description: 'One sentence; offers next steps.'),
                new ArraySchema(
                    name: 'next_options_chip_texts',
                    description: 'Exactly 2 chip labels.',
                    items: new StringSchema(name: 'chip', description: 'Short label.'),
                    nullable: false,
                    minItems: 2,
                    maxItems: 2,
                ),
            ],
            requiredFields: ['framing', 'rationale', 'next_options', 'next_options_chip_texts'],
        );
    }

    /**
     * @return array<string, int|float>
     */
    private function resolveClientOptions(): array
    {
        $temperature = config('task-assistant.generation.listing_followup.temperature');
        $maxTokens = config('task-assistant.generation.listing_followup.max_tokens');
        $topP = config('task-assistant.generation.listing_followup.top_p');

        return [
            'timeout' => (int) config('prism.request_timeout', 120),
            'temperature' => is_numeric($temperature) ? (float) $temperature : 0.35,
            'max_tokens' => is_numeric($maxTokens) ? (int) $maxTokens : 450,
            'top_p' => is_numeric($topP) ? (float) $topP : 0.88,
        ];
    }

    private function resolveProvider(): Provider
    {
        $provider = strtolower((string) config('task-assistant.provider', 'ollama'));

        return match ($provider) {
            'ollama' => Provider::Ollama,
            default => Provider::Ollama,
        };
    }

    private function resolveModel(): string
    {
        return (string) config('task-assistant.model', 'hermes3:3b');
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<array{entity_type: string, entity_id: int, title: string}>
     */
    private function normalizeComparedEntities(array $raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = trim((string) ($row['entity_type'] ?? ''));
            $id = (int) ($row['entity_id'] ?? 0);
            $title = trim((string) ($row['title'] ?? ''));
            if ($type === '' || $id <= 0 || $title === '') {
                continue;
            }
            $out[] = [
                'entity_type' => $type,
                'entity_id' => $id,
                'title' => $title,
            ];
        }

        return $out;
    }
}
