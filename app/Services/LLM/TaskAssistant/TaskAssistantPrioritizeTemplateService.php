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
                    'Nothing in your list matched those filters yet, so the ranked slice is empty for now.',
                    'I did not find ranked matches for this ask yet, so there is nothing to list right now.',
                    'Your filters look tight right now, so no ranked rows showed up yet.',
                ])
            );
        }

        if ($hasDoingContext) {
            return TaskAssistantPrioritizeOutputDefaults::clampFraming(
                $this->selectTemplate('framing.doing', $seedContext, [
                    'You already have work in motion, so I will keep the next ranked step focused and realistic.',
                    'Because you have active work underway, I narrowed this to the most realistic next ranked step.',
                    'With tasks already in progress, this keeps your next ranked step focused instead of overloaded.',
                    'Since you have momentum on in-progress work, I kept the ranked slice lean and practical.',
                    'With Doing tasks on your plate, I aimed this ranked slice at what fits next without overload.',
                    'You are already moving on something, so I kept the ranked next step tight and doable.',
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
                        'From what is on screen right now, this looks like the sharpest next move.',
                        'Given what is visible in your list today, this is the best next step to start with.',
                        'From the current visible slice, this is the most sensible first move.',
                    ])
                    : $this->selectTemplate('framing.multi.ambiguous', $seedContext, [
                        'Here are the strongest next steps from what is currently visible in your list.',
                        'From the tasks visible right now, these are the clearest next steps to tackle first.',
                        'Based on the slice visible right now, these are the most actionable next steps.',
                        'From what is on screen right now, these look like the best next moves in order.',
                        'Given what is visible in your list today, these are the strongest steps to line up first.',
                        'From the current visible slice, these are the most sensible next moves in order.',
                    ])
            );
        }

        return TaskAssistantPrioritizeOutputDefaults::clampFraming(
            $count === 1
                ? $this->selectTemplate('framing.single', $seedContext, [
                    'Here is the step I would put first right now based on urgency and deadlines.',
                    'This is the strongest next step to start with right now, based on urgency and due timing.',
                    'I would start with this step now, using urgency and deadline pressure as the guide.',
                    'If you want one clear move, this is the best first step from urgency and due dates.',
                    'This is the highest-leverage first step right now when you weigh deadlines and priority.',
                    'I would anchor on this step first while urgency and due timing are calling the shots.',
                ])
                : $this->selectTemplate('framing.multi', $seedContext, [
                    'Here are the steps I would line up first right now, ordered by urgency and deadlines.',
                    'These are the strongest next steps to tackle first right now, based on urgency and due timing.',
                    'I would work through these next steps in order right now, guided by urgency and deadline pressure.',
                    'If you want a clean sequence, these are the best first moves ordered by urgency and due dates.',
                    'These are the highest-leverage steps to line up first when deadlines and priority matter most.',
                    'I would move through these in order while urgency and due timing steer what comes first.',
                ])
        );
    }

    /**
     * Short listing intro for hybrid narrative prompts (deterministic seed text).
     *
     * @param  array<string, mixed>  $seedContext
     */
    public function buildHybridPromptListingFraming(int $count, bool $ambiguous, array $seedContext = []): string
    {
        if ($count === 0) {
            return TaskAssistantPrioritizeOutputDefaults::clampFraming(
                $this->selectTemplate('hybrid_prompt.framing.empty', $seedContext, [
                    'Nothing matched that request yet—try widening filters or adding a task.',
                    'No matches for that ask yet—loosen a filter or add a task so I can rank something.',
                    'That slice is empty for now—widen filters or add a task and we can prioritize again.',
                    'I did not find ranked rows for that request yet—try a broader filter or add an item.',
                    'Nothing surfaced to rank yet—adjust filters or add a task to get a fresh slice.',
                    'No ranked rows yet for this ask—open filters a bit or add something concrete to rank.',
                ])
            );
        }

        if ($ambiguous) {
            return TaskAssistantPrioritizeOutputDefaults::clampFraming(
                $this->selectTemplate('hybrid_prompt.framing.ambiguous', $seedContext, [
                    'Here are your strongest next steps from what is currently visible in your list.',
                    'From what is visible right now, these are your clearest next steps in order.',
                    'Based on the visible slice, these are the strongest moves to line up first.',
                    'From your on-screen list, these are the best next steps to tackle in order.',
                    'Here is a tight ordered slice from what is visible in your list right now.',
                    'From the current view, these are the most actionable next steps in sequence.',
                ])
            );
        }

        return TaskAssistantPrioritizeOutputDefaults::clampFraming(
            $count === 1
                ? $this->selectTemplate('hybrid_prompt.framing.single', $seedContext, [
                    'Here is the strongest next step for this request.',
                    'This is the clearest first move for this request.',
                    'Here is the best single next step for this ask.',
                    'This is the strongest one-step focus for this request.',
                    'Here is the top next step that fits this request.',
                    'This is the sharpest first move for this request.',
                ])
                : $this->selectTemplate('hybrid_prompt.framing.multi', $seedContext, [
                    'Here are the strongest next steps for this request.',
                    'These are the clearest next steps for this ask in order.',
                    'Here are the best moves to line up first for this request.',
                    'These are the strongest ordered steps for this request.',
                    'Here is a focused sequence of next steps for this ask.',
                    'These are the top next steps to tackle for this request.',
                ])
        );
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildRankingMethodSummary(array $seedContext = []): string
    {
        return $this->selectTemplate('ranking_method_summary', $seedContext, [
            'I set this order using due timing first, then priority and effort, so the next move stays realistic.',
            'I rank by due pressure first, then priority and effort, so the next step is high-impact but still doable.',
            'I sort by urgency and deadlines, then use priority and effort as tie-breakers.',
            'Deadlines lead the sort, then priority and effort keep the sequence honest and doable.',
            'Due timing drives the top of the list, with priority and effort shaping the finer ordering.',
            'I weight urgency and due dates first, then fold in priority and effort so the order feels fair.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  Prioritize structured payload (items, optional doing_progress_coach, etc.)
     */
    public function buildRankingMethodSummaryFromData(array $data, ?int $threadId = null): string
    {
        return $this->buildRankingMethodSummary($this->buildSeedContextFromPrioritizeData($data, $threadId, 'ranking_from_data'));
    }

    /**
     * Deterministic seed for processor/formatter fallbacks when full prompt seed is unavailable.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function buildSeedContextFromPrioritizePayload(array $data, ?int $threadId = null, string $fingerSuffix = 'processor'): array
    {
        return $this->buildSeedContextFromPrioritizeData($data, $threadId, $fingerSuffix);
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
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $seedContext
     */
    public function buildOrderingRationaleLineBodyFallback(array $item, array $seedContext = []): string
    {
        return $this->selectTemplate('ordering_rationale_line.fallback', $seedContext, [
            'This stays high because it is one of your clearest next moves right now.',
            'I kept this near the top since it is one of the most actionable items in this slice.',
            'This ranks strongly here because it is a practical next move in this set.',
            'This line stays prominent because it is a solid next step within this focus.',
            'This holds a top slot because it is one of the most concrete moves you can make next.',
            'This stays up here because it is a high-signal next action in this slice.',
        ]);
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
                    'When a task lands in this slice, I can spell out why it ranks first and help you slot it.',
                    'As soon as there is a match, I can walk through the top rank and help you schedule around it.',
                    'When something fits this ask, I can explain the ordering and help you plan the first block.',
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
        $factPhrase = $facts === []
            ? $this->selectTemplate('reasoning.facts.empty', $seedContext, [
                'its overall urgency and effort balance',
                'its deadline pressure and practical effort fit',
                'its urgency and realistic effort mix',
                'its urgency profile and manageable effort shape',
                'its timing pressure and effort tradeoff',
                'its time pressure and workable effort level',
            ])
            : implode(', ', $facts);
        $coachTail = $hasDoingContext
            ? $this->selectTemplate('reasoning.coach_tail.doing', $seedContext, [
                'Finish a focused chunk first, then reassess what to pick up next.',
                'Close one clear block first, then decide the next pickup with a fresh read.',
                'Wrap a tight chunk first, then check what deserves your next block.',
                'Land one focused pass first, then choose the next step with less noise.',
                'Complete a short solid block first, then re-rank what should come next.',
                'Clear one practical chunk first, then reevaluate your next move.',
            ])
            : $this->selectTemplate('reasoning.coach_tail.default', $seedContext, [
                'Start with a focused chunk so progress feels lighter and easier to sustain.',
                'Begin with one tight block so momentum builds without draining your energy.',
                'Kick off with a short focused pass so the first win comes quickly.',
                'Take one clear chunk first so progress stays steady instead of overwhelming.',
                'Open with a manageable block so your momentum grows with less friction.',
                'Start with one practical burst so the next step feels easier to carry.',
            ]);

        $template = $this->selectTemplate('reasoning.primary', $seedContext, [
            'I would start with "{title}" because it has {facts}. {coach_tail}',
            'Start with "{title}" since it is {facts}. {coach_tail}',
            'I put "{title}" first because it is {facts}. {coach_tail}',
            'Lead with "{title}" because it lines up as {facts}. {coach_tail}',
            'Anchor on "{title}" first since it reads as {facts}. {coach_tail}',
            'Open with "{title}" because it is {facts}. {coach_tail}',
        ]);

        return TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning(strtr($template, [
            '{title}' => $title,
            '{facts}' => $factPhrase,
            '{coach_tail}' => $coachTail,
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $seedContext
     */
    public function buildReasoningInvalidFallback(array $items, bool $hasDoingContext, array $seedContext = []): string
    {
        if ($items === []) {
            return TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning(
                $this->selectTemplate('reasoning.invalid.empty', $seedContext, [
                    'I could not add a custom explanation this time, but this order still follows your usual student-first ranking so you have a clear next step.',
                    'The wording is thin here, but the order still follows your student-first ranking so you can move with confidence.',
                    'I will keep this brief: the ranking still follows your usual student-first rules so the next step stays clear.',
                    'No extra narrative landed, but the sequence still reflects your student-first ranking so you know where to start.',
                    'I could not stretch a long coach note here, but the order is still grounded in your student-first ranking.',
                    'Short version: the list order still follows your student-first ranking, so the next move stays obvious.',
                ])
            );
        }

        return $this->buildReasoning($items, $hasDoingContext, array_merge($seedContext, [
            'prompt_key' => (string) ($seedContext['prompt_key'] ?? 'no_prompt').'|reasoning_invalid',
        ]));
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildFramingInvalidFallback(int $count, bool $hasDoingContext, array $seedContext = []): string
    {
        if ($hasDoingContext) {
            return TaskAssistantPrioritizeOutputDefaults::clampFraming(
                $this->selectTemplate('framing.invalid.doing', $seedContext, [
                    'You have momentum on in-progress work, so I kept this message tight while the ranked list carries the detail.',
                    'With Doing tasks in play, I kept the intro short so the ranked slice can speak for itself.',
                    'Since you already started something, I kept this framing light and let the ordered list lead.',
                    'You are already moving on a task, so I kept the opening brief and focused on the ranked next steps.',
                    'With work already underway, I kept the intro minimal so the ranked list stays the star.',
                    'Because you have tasks in motion, I kept this opener short and leaned on the ranked list below.',
                ])
            );
        }

        if ($count === 0) {
            return TaskAssistantPrioritizeOutputDefaults::clampFraming(
                $this->selectTemplate('framing.invalid.empty', $seedContext, [
                    'I could not add a custom intro this time, but the order still follows your usual student-first ranking.',
                    'The intro is light here, but the ranking still follows your student-first rules underneath.',
                    'I will keep this opener brief: the sequence still reflects your student-first ranking.',
                    'No long intro landed, but the ordering still follows your student-first ranking logic.',
                    'Short opener: the list order still follows your student-first ranking so you can proceed.',
                    'I kept this framing minimal, but the rank order still follows your student-first policy.',
                ])
            );
        }

        return TaskAssistantPrioritizeOutputDefaults::clampFraming(
            $this->selectTemplate('framing.invalid.has_items', $seedContext, [
                'Here is a clean read on your next steps using the same student-first ranking you rely on.',
                'I kept this intro simple so you can move straight into the ordered slice below.',
                'This is a light handoff into your ranked next steps, grounded in the same student-first ordering.',
                'Here is a steady opener before the ranked list, still aligned with your student-first ranking.',
                'I kept this framing short so the ordered list below can carry the weight.',
                'This is a simple bridge into your ranked slice, following the same student-first ordering.',
            ])
        );
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
                'If you want, I can schedule this top task for later today, tomorrow, or later this week.',
                'If you want, I can place this top task later today, tomorrow, or later this week.',
                'If you want, I can schedule this top task and slot it into later today, tomorrow, or later this week.',
                'If it helps, I can block this top task for later today, tomorrow, or later this week.',
                'Say the word and I can map this top task to later today, tomorrow, or later this week.',
                'If you want, I can help you place this top task later today, tomorrow, or later this week.',
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
                'If you want, I can schedule these ranked tasks for later today, tomorrow, or later this week.',
                'If you want, I can place these ranked tasks later today, tomorrow, or later this week.',
                'If you want, I can schedule these ranked tasks and slot them into later today, tomorrow, or later this week.',
                'If it helps, I can map these ranked tasks to later today, tomorrow, or later this week.',
                'Say the word and I can block these ranked tasks for later today, tomorrow, or later this week.',
                'If you want, I can help you place these ranked tasks later today, tomorrow, or later this week.',
            ])
            : $this->selectTemplate('next.multi.complete', $seedContext, [
                'This covers the key items for your request. If you want, I can schedule them for later today, tomorrow, or later this week.',
                'This captures the key items for your request. If you want, I can schedule them for later today, tomorrow, or later this week.',
                'This includes the key items for your request. If you want, I can schedule them for later today, tomorrow, or later this week.',
                'That is the core set for your ask. If you want, I can place them later today, tomorrow, or later this week.',
                'Those are the main items for this request. If you want, I can map them to later today, tomorrow, or later this week.',
                'This rounds out the key slice. If you want, I can schedule them for later today, tomorrow, or later this week.',
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
     * @param  array<string, mixed>  $seedContext
     * @return array{next_options:string,next_options_chip_texts:list<string>}
     */
    public function buildNextOptionsInvalidFallback(int $itemsCount, array $seedContext = []): array
    {
        if ($itemsCount <= 1) {
            $line = $this->selectTemplate('next.invalid.single', $seedContext, [
                'If you want, I can help schedule this next step for later today, tomorrow, or later this week.',
                'If it helps, I can help you place this next step later today, tomorrow, or later this week.',
                'Say the word and I can help map this next step to later today, tomorrow, or later this week.',
                'If you want, I can help you block this next step later today, tomorrow, or later this week.',
                'If it helps, I can help schedule this next move for later today, tomorrow, or later this week.',
                'If you want, I can help slot this next step into later today, tomorrow, or later this week.',
            ]);

            return [
                'next_options' => TaskAssistantPrioritizeOutputDefaults::clampNextField($line),
                'next_options_chip_texts' => [
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule that task for later today'),
                    TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText('Schedule that task for tomorrow'),
                ],
            ];
        }

        $line = $this->selectTemplate('next.invalid.multi', $seedContext, [
            'If you want, I can help schedule these next steps for later today, tomorrow, or later this week.',
            'If it helps, I can help you place these next steps later today, tomorrow, or later this week.',
            'Say the word and I can help map these next steps to later today, tomorrow, or later this week.',
            'If you want, I can help you block these next steps later today, tomorrow, or later this week.',
            'If it helps, I can help schedule these next moves for later today, tomorrow, or later this week.',
            'If you want, I can help slot these next steps into later today, tomorrow, or later this week.',
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
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $seedContext
     */
    public function buildReasoningProcessorDedupe(array $items, bool $hasDoingContext, array $seedContext = []): string
    {
        $first = is_array($items[0] ?? null) ? $items[0] : [];
        $title = trim((string) ($first['title'] ?? 'this top task'));
        $merged = array_merge($seedContext, ['prompt_key' => (string) ($seedContext['prompt_key'] ?? 'no_prompt').'|processor_dedupe']);

        $template = $this->selectTemplate('reasoning.processor_dedupe', $merged, [
            'Start with "{title}" first, then check your momentum before moving to the next item. Keep this step short so progress feels steady.',
            'Open with "{title}" first, then take a breath before stacking another task. Short passes keep momentum kind.',
            'Lead with "{title}" first, then reassess what fits next after a focused burst. Small wins keep the day lighter.',
            'Anchor on "{title}" first, then decide what deserves the next pocket of energy. Keep the first slice tight.',
            'Tackle "{title}" first, then let the next choice be lighter after a clear win. Steady beats heroic.',
            'Begin with "{title}" first, then line up the next move once this block feels done. Short steps add up.',
        ]);

        return TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning(strtr($template, ['{title}' => $title]));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $seedContext
     */
    public function buildReasoningProcessorTopTitleEnforced(array $items, bool $hasDoingContext, array $seedContext = []): string
    {
        $first = is_array($items[0] ?? null) ? $items[0] : [];
        $title = trim((string) ($first['title'] ?? 'this top task'));
        $merged = array_merge($seedContext, ['prompt_key' => (string) ($seedContext['prompt_key'] ?? 'no_prompt').'|processor_title']);

        $template = $this->selectTemplate('reasoning.processor_title_enforce', $merged, [
            'Start with "{title}" first, then take a focused pass before moving to the next item. Keeping this step short helps you build momentum.',
            'Open with "{title}" first, then move in a tight block before picking up anything else. Short focus keeps pressure down.',
            'Lead with "{title}" first, then let the next task wait until this chunk lands. Momentum likes a clean first win.',
            'Anchor on "{title}" first, then reassess after a deliberate burst. A crisp first step makes the rest feel lighter.',
            'Tackle "{title}" first, then choose the next move once this slice feels settled. Small wins stack fast.',
            'Begin with "{title}" first, then carry that clarity into whatever comes next. One clear step beats a vague pile.',
        ]);

        return TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning(strtr($template, ['{title}' => $title]));
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
        $effortText = $this->resolveEffortPhraseForOrdering($complexity, $seedContext);

        $template = $this->selectTemplate('ordering_rationale_line', $seedContext, [
            'This rises because it is {priority}, {due}, and {effort}.',
            'I kept this high since it is {due}, with {priority}, and {effort}.',
            'This stays near the top because {due}, {priority}, and {effort}.',
            'I ranked this here due to {due}, plus {priority}, and {effort}.',
            'This sits up here because it is {priority}, {due}, and {effort}.',
            'This line stays strong since {due}, {priority}, and {effort} line up together.',
        ]);

        return strtr($template, [
            '{priority}' => $priorityText,
            '{due}' => $dueText,
            '{effort}' => $effortText,
        ]);
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    private function resolveEffortPhraseForOrdering(string $complexity, array $seedContext): string
    {
        return match ($complexity) {
            'simple' => $this->selectTemplate('ordering_effort.simple', $seedContext, [
                'quick effort',
                'light lift',
                'small workload',
                'a light lift',
                'a small bite of work',
                'a quick pass of effort',
            ]),
            'moderate' => $this->selectTemplate('ordering_effort.moderate', $seedContext, [
                'manageable effort',
                'reasonable lift',
                'doable scope',
                'a doable chunk',
                'a steady amount of work',
                'a moderate lift',
            ]),
            'complex' => $this->selectTemplate('ordering_effort.complex', $seedContext, [
                'higher effort',
                'heavier lift',
                'larger workload',
                'a heavier lift',
                'a bigger block of work',
                'a deeper effort ask',
            ]),
            default => $this->selectTemplate('ordering_effort.default', $seedContext, [
                'current effort level',
                'expected workload',
                'overall lift',
                'the effort level you set',
                'the workload you signaled',
                'the effort picture on this item',
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildSeedContextFromPrioritizeData(array $data, ?int $threadId, string $fingerSuffix): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $resolvedThread = $threadId ?? (app()->bound('task_assistant.thread_id') ? (int) app('task_assistant.thread_id') : 0);
        $top = is_array($items[0] ?? null) ? $items[0] : [];
        $topType = trim((string) ($top['entity_type'] ?? 'task'));
        $topId = (string) ($top['entity_id'] ?? '0');
        $topTitle = trim((string) ($top['title'] ?? ''));
        $hasDoing = trim((string) ($data['doing_progress_coach'] ?? '')) !== '';

        return [
            'thread_id' => $resolvedThread,
            'top_key' => $topType.':'.$topId.':'.$topTitle,
            'items_count' => count($items),
            'has_doing_context' => $hasDoing,
            'day_bucket' => $this->resolveDayBucket([]),
            'prompt_key' => substr(sha1($fingerSuffix.'|'.$resolvedThread.'|'.$topType.$topId.$topTitle), 0, 16),
            'request_bucket' => $fingerSuffix,
        ];
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
            'rank:'.(string) ($seedContext['rank'] ?? '0'),
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
