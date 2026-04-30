<?php

namespace App\Services\LLM\TaskAssistant;

use App\Support\LLM\TaskAssistantPrioritizeOutputDefaults;
use Carbon\CarbonImmutable;

final class TaskAssistantPrioritizeTemplateService
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $seedContext
     */
    public function buildFraming(
        array $items,
        bool $hasDoingContext,
        bool $ambiguous,
        array $seedContext = [],
    ): string {
        $count = count($items);
        if ($count === 0) {
            return TaskAssistantPrioritizeOutputDefaults::clampFraming(
                $this->selectTemplate('framing.empty', $seedContext, [
                    'Nothing matched this request yet, so there is no ranked slice to show right now.',
                    'I could not pull a ranked slice for this request yet, so nothing is listed right now.',
                    'No ranked matches surfaced for this request yet, so the slice is currently empty.',
                ])
            );
        }

        if ($hasDoingContext) {
            return TaskAssistantPrioritizeOutputDefaults::clampFraming(
                $this->selectTemplate('framing.doing', $seedContext, [
                    'You already have work in motion, so I will keep the next ranked step focused and realistic.',
                    'Because you have active work underway, I narrowed this to the most realistic next ranked step.',
                    'With tasks already in progress, this keeps your next ranked step focused instead of overloaded.',
                ])
            );
        }

        if ($ambiguous) {
            return TaskAssistantPrioritizeOutputDefaults::clampFraming(
                $count === 1
                    ? $this->selectTemplate('framing.single.ambiguous', $seedContext, [
                        'Here is the strongest next step from what is currently visible in your list.',
                        'From the tasks visible right now, this is the clearest next step to tackle first.',
                        'Based on the slice visible right now, this is the most actionable next step.',
                    ])
                    : $this->selectTemplate('framing.multi.ambiguous', $seedContext, [
                        'Here are the strongest next steps from what is currently visible in your list.',
                        'From the tasks visible right now, these are the clearest next steps to tackle first.',
                        'Based on the slice visible right now, these are the most actionable next steps.',
                    ])
            );
        }

        return TaskAssistantPrioritizeOutputDefaults::clampFraming(
            $count === 1
                ? $this->selectTemplate('framing.single', $seedContext, [
                    'Here is the one step I would put at the front right now based on urgency and deadlines.',
                    'This is the strongest next step to start with right now, based on urgency and due timing.',
                    'I would start with this step first right now, using urgency and deadline pressure as the guide.',
                ])
                : $this->selectTemplate('framing.multi', $seedContext, [
                    'Here are the steps I would line up first right now, ordered by urgency and deadlines.',
                    'These are the strongest next steps to tackle first right now, based on urgency and due timing.',
                    'I would work through these next steps in order right now, guided by urgency and deadline pressure.',
                ])
        );
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildRankingMethodSummary(array $seedContext = []): string
    {
        return $this->selectTemplate('ranking_method_summary', $seedContext, [
            'I prioritize urgency first, then priority and effort, so your next move stays both important and realistic.',
            'I rank by urgency first, then priority and effort, so the next step is high-impact but still doable.',
            'I sort urgent items first, then weigh priority and effort, so the plan stays meaningful and manageable.',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $seedContext
     * @return list<string>
     */
    public function buildOrderingRationale(array $items, array $seedContext = []): array
    {
        $lines = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $rank = $index + 1;
            $reason = $this->buildOrderingReasonBody($item, array_merge($seedContext, ['rank' => $rank]));
            $lines[] = TaskAssistantPrioritizeOutputDefaults::buildPrioritizeOrderingLine($rank, $title, $reason);
        }

        return array_slice($lines, 0, 10);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $seedContext
     */
    public function buildReasoning(array $items, bool $hasDoingContext, array $seedContext = []): string
    {
        if ($items === []) {
            return TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning(
                $this->selectTemplate('reasoning.empty', $seedContext, [
                    'Once a matching task appears, I can explain why it rises to the top and help you schedule it.',
                    'When a matching task shows up, I can justify the top pick and help place it on your calendar.',
                    'As soon as something matches, I can explain the top rank clearly and help you plan it.',
                ])
            );
        }

        $first = is_array($items[0] ?? null) ? $items[0] : [];
        $title = trim((string) ($first['title'] ?? 'this top task'));
        $duePhrase = trim((string) ($first['due_phrase'] ?? ''));
        $priority = strtolower(trim((string) ($first['priority'] ?? '')));
        $complexity = strtolower(trim((string) ($first['complexity_label'] ?? '')));

        $facts = [];
        if ($duePhrase !== '' && $duePhrase !== 'no due date') {
            $facts[] = $duePhrase;
        }
        if ($priority !== '') {
            $facts[] = $priority.' priority';
        }
        if ($complexity !== '' && $complexity !== 'not set') {
            $facts[] = match ($complexity) {
                'simple' => 'quick effort',
                'moderate' => 'manageable effort',
                'complex' => 'higher effort',
                default => $complexity.' effort',
            };
        }
        $factPhrase = $facts === [] ? 'its overall urgency and effort balance' : implode(', ', $facts);
        $coachTail = $hasDoingContext
            ? 'Finish a focused chunk first, then reassess what to pick up next.'
            : 'Start with a focused chunk so progress feels lighter and easier to sustain.';

        $template = $this->selectTemplate('reasoning.primary', $seedContext, [
            'I would start with "{title}" first because of {facts}. {coach_tail}',
            'Start with "{title}" first since it is {facts}. {coach_tail}',
            'I put "{title}" first because it reflects {facts}. {coach_tail}',
        ]);

        return TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning(strtr($template, [
            '{title}' => $title,
            '{facts}' => $factPhrase,
            '{coach_tail}' => $coachTail,
        ]));
    }

    /**
     * @param  array<string, mixed>  $seedContext
     * @return array{next_options:string,next_options_chip_texts:list<string>}
     */
    public function buildNextOptions(
        int $itemsCount,
        bool $hasMoreUnseen,
        array $seedContext = [],
    ): array {
        if ($itemsCount <= 1) {
            $line = $this->selectTemplate('next.single', $seedContext, [
                'If you want, I can place this top task later today, tomorrow, or later this week.',
                'If you want, I can schedule this top task for later today, tomorrow, or later this week.',
                'If you want, I can slot this top task into later today, tomorrow, or later this week.',
            ]);

            return [
                'next_options' => TaskAssistantPrioritizeOutputDefaults::clampNextField($line),
                'next_options_chip_texts' => [
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule that task for later today'),
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule that task for tomorrow'),
                ],
            ];
        }

        $line = $hasMoreUnseen
            ? $this->selectTemplate('next.multi.more', $seedContext, [
                'If you want, I can place these ranked tasks later today, tomorrow, or later this week.',
                'If you want, I can schedule these ranked tasks for later today, tomorrow, or later this week.',
                'If you want, I can slot these ranked tasks into later today, tomorrow, or later this week.',
            ])
            : $this->selectTemplate('next.multi.complete', $seedContext, [
                'This covers the key items for your request. If you want, I can place them later today, tomorrow, or later this week.',
                'This captures the key items for your request. If you want, I can schedule them for later today, tomorrow, or later this week.',
                'This includes the key items for your request. If you want, I can slot them for later today, tomorrow, or later this week.',
            ]);

        return [
            'next_options' => TaskAssistantPrioritizeOutputDefaults::clampNextField($line),
            'next_options_chip_texts' => [
                TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule those tasks for later today'),
                TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule those tasks for tomorrow'),
                TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule only the top task for later'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $seedContext
     */
    private function buildOrderingReasonBody(array $item, array $seedContext): string
    {
        $priority = strtolower(trim((string) ($item['priority'] ?? '')));
        $duePhrase = trim((string) ($item['due_phrase'] ?? ''));
        $complexity = strtolower(trim((string) ($item['complexity_label'] ?? '')));

        $priorityText = $priority !== '' ? $priority.' priority' : 'its current priority';
        $dueText = $duePhrase !== '' ? $duePhrase : 'no due date pressure';
        $effortText = match ($complexity) {
            'simple' => 'quick effort',
            'moderate' => 'manageable effort',
            'complex' => 'higher effort',
            default => 'current effort level',
        };

        $template = $this->selectTemplate('ordering_rationale_line', $seedContext, [
            'This rises because it is {priority}, {due}, and {effort}.',
            'I kept this high because it is {priority}, {due}, and {effort}.',
            'This stays near the top due to {priority}, {due}, and {effort}.',
        ]);

        return strtr($template, [
            '{priority}' => $priorityText,
            '{due}' => $dueText,
            '{effort}' => $effortText,
        ]);
    }

    /**
     * @param  array<string, mixed>  $seedContext
     * @param  list<string>  $templates
     */
    private function selectTemplate(string $section, array $seedContext, array $templates): string
    {
        if ($templates === []) {
            return '';
        }

        $seedBase = implode('|', [
            'flow:prioritize',
            'section:'.$section,
            'thread:'.(string) ($seedContext['thread_id'] ?? '0'),
            'top:'.(string) ($seedContext['top_key'] ?? 'none'),
            'count:'.(string) ($seedContext['items_count'] ?? '0'),
            'doing:'.((bool) ($seedContext['has_doing_context'] ?? false) ? '1' : '0'),
            'day:'.$this->resolveDayBucket($seedContext),
            'prompt:'.(string) ($seedContext['prompt_key'] ?? 'no_prompt'),
            'req:'.(string) ($seedContext['request_bucket'] ?? 'default'),
        ]);
        $hash = crc32($seedBase);
        $index = abs((int) $hash) % count($templates);

        return (string) ($templates[$index] ?? $templates[0]);
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    private function resolveDayBucket(array $seedContext): string
    {
        $fromSeed = trim((string) ($seedContext['day_bucket'] ?? ''));
        if ($fromSeed !== '') {
            return $fromSeed;
        }

        return CarbonImmutable::now((string) config('app.timezone', 'UTC'))->toDateString();
    }
}
