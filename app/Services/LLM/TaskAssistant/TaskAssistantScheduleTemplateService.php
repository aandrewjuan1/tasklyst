<?php

namespace App\Services\LLM\TaskAssistant;

use Carbon\CarbonImmutable;

final class TaskAssistantScheduleTemplateService
{
    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildFraming(string $scenarioKey, array $seedContext, array $replacements = []): string
    {
        $templates = match ($scenarioKey) {
            'FLOW_PRIORITIZE_SCHEDULE_TASKS_ONLY' => $this->forFlowMany([
                'I proposed focused blocks for your top-ranked tasks so they fit realistic open windows and stay doable.',
                'I mapped your top-ranked tasks into open windows so this stays practical and easier to execute.',
                'I set up focused blocks for your top-ranked tasks in windows that are actually open, so momentum can hold.',
                'I arranged top-ranked tasks into open windows so your schedule remains workable without overload.',
                'I lined up your top-ranked tasks in realistic windows to keep progress steady and clear.',
            ],
                $seedContext
            ),
            'STRICT_WINDOW_NO_FIT' => $this->forFlowMany([
                'I checked {requested_window_label} first and paused because that strict window could not fully fit.',
                'I started with {requested_window_label}, but that strict window could not fully fit, so I kept this realistic.',
                'I tried to keep this inside {requested_window_label} first, but the strict window could not fully fit.',
                'I attempted {requested_window_label} first, but that strict window did not fully fit this plan.',
                'I began with {requested_window_label}, then paused when that strict window could not fully fit cleanly.',
            ],
                $seedContext
            ),
            'TOP_N_SHORTFALL' => $this->forFlowMany([
                'I built the strongest draft possible for your requested count while keeping it realistic.',
                'I drafted the best feasible plan for the number of items you requested, without forcing overload.',
                'I prepared the strongest workable draft for your requested item count.',
                'I created the best feasible draft for the requested number of items in this pass.',
                'I prepared the strongest possible draft for your requested item total, grounded in open capacity.',
            ],
                $seedContext
            ),
            default => $this->forFlowMany([
                'I proposed the closest feasible window for what you asked, so you can move without reshuffling everything.',
                'I drafted the nearest feasible window for your request while keeping the plan stable.',
                'I suggested the closest workable time window for what you asked.',
                'I selected the nearest workable window that fits your request and preserves flow in your day.',
                'I proposed the closest practical window that can fit this request without overpacking your schedule.',
            ],
                $seedContext
            ),
        };

        return $this->render($this->selectTemplate('framing.'.$scenarioKey, $seedContext, $templates), $replacements);
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildReasoning(string $scenarioKey, array $seedContext, array $replacements = []): string
    {
        $templates = match ($scenarioKey) {
            'STRICT_WINDOW_NO_FIT' => $this->forFlowMany([
                'I could not fit {requested_window_label} because {blockers_text} occupied that period, so I used the nearest open slot{chosen_suffix}.',
                'I could not keep this in {requested_window_label} due to {blockers_text}, so I moved it to the nearest open slot{chosen_suffix}.',
                '{requested_window_label} was blocked by {blockers_text}, so I used the nearest open slot{chosen_suffix} to keep progress moving.',
                '{requested_window_label} was too constrained by {blockers_text}, so I shifted to the nearest open slot{chosen_suffix}.',
                'Because {blockers_text} filled {requested_window_label}, I used the nearest open slot{chosen_suffix} as the most workable next move.',
            ],
                $seedContext
            ),
            'TOP_N_SHORTFALL' => $this->forFlowMany([
                'You asked for {requested_count}, and {placed_count} fit in {requested_window_label} in this pass.',
                'You requested {requested_count}, and {placed_count} could be placed in {requested_window_label} this round.',
                'In this pass, {placed_count} out of {requested_count} fit inside {requested_window_label}, so this stays executable.',
                '{placed_count} of {requested_count} could be placed in {requested_window_label} during this pass.',
                'This round placed {placed_count} of {requested_count} inside {requested_window_label} while keeping the plan realistic.',
            ],
                $seedContext
            ),
            'FLOW_PRIORITIZE_SCHEDULE_TASKS_ONLY' => $this->forFlowMany([
                'I spread these tasks across conflict-free windows that fit the rest of your schedule without overloading one block.',
                'I distributed these tasks into conflict-free windows so one block does not get overloaded and you can sustain pace.',
                'I placed these tasks across open windows to keep the workload realistic across your day.',
                'I spread these tasks over open windows so your day does not bunch into one heavy block.',
                'I placed these tasks across realistic windows to keep the plan balanced, clear, and doable.',
            ],
                $seedContext
            ),
            default => $this->forFlowMany([
                'I placed this{chosen_suffix} because it is the closest open slot that fits your requested scope.',
                'I scheduled this{chosen_suffix} because it is the nearest open window that fits your request and keeps momentum.',
                'I used this slot{chosen_suffix} because it is the closest feasible opening for your requested scope.',
                'I chose this slot{chosen_suffix} because it is the nearest workable opening for your request.',
                'I placed this{chosen_suffix} since it is the closest realistic slot for your requested scope and energy.',
            ],
                $seedContext
            ),
        };

        return $this->render($this->selectTemplate('reasoning.'.$scenarioKey, $seedContext, $templates), $replacements);
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildConfirmation(string $scenarioKey, array $seedContext, array $replacements = []): string
    {
        $templates = match ($scenarioKey) {
            'STRICT_WINDOW_NO_FIT' => $this->forFlowMany([
                'Do you want me to keep this nearest-slot draft, or should we pick another time window?',
                'Want to keep this nearest-slot draft, or should we choose a different time window?',
                'Should I keep this nearest-slot draft, or would you rather choose another time window?',
                'Would you like to keep this nearest-slot draft, or should we switch to another time window?',
                'Keep this nearest-slot draft, or pick a different time window so it feels better to execute?',
            ],
                $seedContext
            ),
            'TOP_N_SHORTFALL' => $this->forFlowMany([
                'Do you want to continue with this draft or widen the window to fit more items?',
                'Want to keep this draft, or should we widen the window to fit more items?',
                'Should we continue with this draft, or widen the window so more items can fit?',
                'Do you want to keep this draft, or widen the window so more items can fit while staying manageable?',
                'Continue with this draft, or open the window wider to fit additional items?',
            ],
                $seedContext
            ),
            default => $this->forFlowMany([
                'Do these times look workable, or should I shift earlier/later before you finalize?',
                'Do these times feel workable, or should I move them earlier/later before you finalize?',
                'Do these slots work for you, or do you want them shifted earlier/later before finalizing?',
                'Are these times workable for you, or should I move them earlier/later before finalizing?',
                'Do these time blocks feel right, or should I shift them earlier/later before you finalize so they are easier to follow?',
            ],
                $seedContext
            ),
        };

        return $this->render($this->selectTemplate('confirmation.'.$scenarioKey, $seedContext, $templates), $replacements);
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildWindowSelectionExplanation(array $seedContext, array $replacements = []): string
    {
        $templates = $this->forFlowMany([
            'I prioritized slots between {window_start} and {window_end} so this plan fits the time window you asked for.',
            'I focused on slots from {window_start} to {window_end} so the plan stays inside your requested window.',
            'I selected slots between {window_start} and {window_end} to match the window you asked for.',
            'I used slots from {window_start} to {window_end} so this stays within your requested window and remains realistic.',
            'I kept placement between {window_start} and {window_end} to honor the time window you asked for while preserving flow.',
        ],
            $seedContext
        );

        return $this->render($this->selectTemplate('explain.window_selection', $seedContext, $templates), $replacements);
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildOrderingRationaleLine(array $seedContext, array $replacements = []): string
    {
        $templates = $this->forFlowMany([
            '#{rank} {title}: placed at {start_label} as one of the strongest fit windows.',
            '#{rank} {title}: scheduled at {start_label} because it is one of the strongest fit windows.',
            '#{rank} {title}: slotted at {start_label} as a strong fit for your available window.',
            '#{rank} {title}: placed at {start_label} as a strong fit for your current availability.',
            '#{rank} {title}: scheduled at {start_label} because it best fits your open window and keeps sequencing practical.',
        ],
            $seedContext
        );

        return $this->render($this->selectTemplate('explain.ordering_line', $seedContext, $templates), $replacements);
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildFallbackChoiceExplanation(string $fallbackMode, array $seedContext): string
    {
        $templates = match ($fallbackMode) {
            'auto_relaxed_today_or_tomorrow' => $this->forFlowMany([
                'I widened placement to nearby days because the original window had no valid opening.',
                'I widened this to nearby days since the original window had no valid opening.',
                'I expanded placement to nearby days because the original window had no workable opening.',
                'I relaxed the window into nearby days because the original slot had no valid opening.',
                'I widened to nearby days because the original window could not support a valid placement, which keeps this plan realistic.',
            ],
                $seedContext
            ),
            default => $this->forFlowMany([
                'I used a safer fallback schedule strategy to keep your plan realistic.',
                'I used a safer fallback scheduling strategy so the plan stays realistic.',
                'I switched to a safer fallback scheduling mode to keep this plan realistic.',
                'I applied a safer fallback scheduling approach to keep this plan realistic and actionable.',
                'I moved to a safer fallback mode so the plan remains realistic, workable, and easier to follow.',
            ],
                $seedContext
            ),
        };

        return $this->selectTemplate('explain.fallback.'.$fallbackMode, $seedContext, $templates);
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildPrioritizeSelectionSummary(int $selectedCount, array $seedContext): string
    {
        if ($selectedCount <= 1) {
            return $this->selectTemplate('selection.summary.single', $seedContext, $this->forFlowMany([
                'I selected this task first because it stood out as the clearest next step before scheduling.',
                'I prioritized this task first because it surfaced as the strongest next move before time blocking.',
                'I chose this task first because it looked most actionable before placing it on your schedule.',
                'I put this task first because it was the clearest fit to tackle next before scheduling.',
                'I started with this task because it rose to the top of your priorities before planning time blocks.',
                'I placed this task first because it showed the strongest urgency and fit before scheduling.',
                'I ranked this task first because it was the clearest priority to act on before time placement.',
                'I led with this task because it stood out as the most practical first move before scheduling.',
                'I began with this task because it was the strongest immediate priority before time blocking.',
                'I put this task at the top because it was the clearest focus to schedule first.',
            ], $seedContext));
        }

        return $this->selectTemplate('selection.summary.multi', $seedContext, $this->forFlowMany([
            'I selected these tasks first because they stood out as the clearest priorities before scheduling.',
            'I prioritized these tasks first because they surfaced as the strongest next steps before time blocking.',
            'I chose these tasks first because they looked most actionable before placing them on your schedule.',
            'I put these tasks first because they were the clearest fit to tackle next before scheduling.',
            'I started with these tasks because they rose to the top of your priorities before planning time blocks.',
            'I placed these tasks first because they showed the strongest urgency and fit before scheduling.',
            'I ranked these tasks first because they were the clearest priorities to act on before time placement.',
            'I led with these tasks because they stood out as the most practical first moves before scheduling.',
            'I began with these tasks because they were the strongest immediate priorities before time blocking.',
            'I put these tasks at the top because they were the clearest focus to schedule first.',
        ], $seedContext));
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildPrioritizeSelectionBasis(int $selectedCount, array $seedContext): string
    {
        if ($selectedCount <= 1) {
            return $this->selectTemplate('selection.basis.single', $seedContext, $this->forFlowMany([
                'I weigh urgency first, then explicit priority and due timing. When signals are close, I favor a shorter focused block so this stays doable.',
                'Urgency leads the ranking, followed by explicit priority and due timing. If scores are close, I use a shorter block as the tiebreak so the plan stays realistic.',
                'I rank this by urgency first, then by explicit priority and due timing. When the score is tight, I choose a shorter block to keep momentum high.',
                'The first pass is urgency, then explicit priority and due timing. For close ties, a shorter block wins so this is easier to execute.',
                'I sort using urgency first, then explicit priority and due timing. If factors are nearly equal, I break ties with a shorter manageable block.',
                'Urgency is the primary signal, followed by explicit priority and due timing. When ranking is close, I choose the shorter focused block for easier follow-through.',
                'I start with urgency, then apply explicit priority and due timing. For close results, I use a shorter block as the practical tiebreak.',
                'I prioritize urgency first, then explicit priority and due timing. If this is near a tie, a shorter block is favored so execution stays clean.',
                'The ranking starts with urgency, then explicit priority and due timing. In close calls, I pick a shorter block to reduce friction.',
                'I score urgency first, then explicit priority and due timing. When signals are close, I choose a shorter block to keep this schedule manageable.',
            ], $seedContext));
        }

        return $this->selectTemplate('selection.basis.multi', $seedContext, $this->forFlowMany([
            'I weigh urgency first, then explicit priority and due timing. When signals are close, I favor shorter focused blocks so this approach stays doable.',
            'Urgency leads the ranking, followed by explicit priority and due timing. If scores are close, shorter blocks become the tiebreak so execution stays realistic.',
            'I rank these by urgency first, then by explicit priority and due timing. When scores are tight, shorter blocks help keep momentum steady.',
            'The first pass is urgency, then explicit priority and due timing. For close ties, shorter blocks win so this stays easier to execute.',
            'I sort using urgency first, then explicit priority and due timing. If factors are nearly equal, I break ties with shorter manageable blocks.',
            'Urgency is the primary signal, followed by explicit priority and due timing. When ranking is close, I choose shorter focused blocks for better follow-through.',
            'I start with urgency, then apply explicit priority and due timing. For close results, shorter blocks act as the practical tiebreak.',
            'I prioritize urgency first, then explicit priority and due timing. In near ties, shorter blocks are favored so execution stays clean.',
            'The ranking starts with urgency, then explicit priority and due timing. In close calls, shorter blocks reduce friction in your schedule.',
            'I score urgency first, then explicit priority and due timing. When signals are close, I choose shorter blocks to keep the plan manageable.',
        ], $seedContext));
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildCoachingLineForToneKey(string $toneKey, array $seedContext): string
    {
        $templates = match ($toneKey) {
            'morning_momentum' => $this->forFlowMany([
                'An early block gives you clearer focus and leaves buffer time if the day changes.',
                'Starting earlier usually improves focus and keeps backup room if timing shifts.',
                'A morning start protects focus and gives you extra space if plans move.',
                'Early timing helps you begin with momentum and still leaves room for changes.',
                'This early slot supports stronger focus and keeps recovery space in your day.',
            ], $seedContext, false),
            'afternoon_restart' => $this->forFlowMany([
                'An afternoon block gives you a clean reset after earlier commitments.',
                'This afternoon slot works as a practical restart point after earlier obligations.',
                'Afternoon timing gives you a fresh focus window after earlier demands.',
                'A focused afternoon block helps you re-enter work without rushing.',
                'This afternoon placement creates a steady restart window for the rest of the day.',
            ], $seedContext, false),
            'evening_closure' => $this->forFlowMany([
                'A defined evening block helps you close the day with one clear completion.',
                'This evening slot gives you a focused way to finish with one concrete win.',
                'Evening timing helps you wrap up the day with a clear end point.',
                'A structured evening block supports a calm finish and one visible result.',
                'This evening window makes it easier to end the day with a finished task.',
            ], $seedContext, false),
            'post_class_or_after_commitment' => $this->forFlowMany([
                'Scheduling this right after commitments reduces idle gaps and keeps momentum steady.',
                'A post-commitment slot helps you continue progress without losing pace.',
                'Placing this after commitments lowers downtime and keeps your flow moving.',
                'This after-commitment timing avoids dead space and supports consistent momentum.',
                'A right-after-commitments block helps you keep momentum without a long reset.',
            ], $seedContext, false),
            'focus_protection' => $this->forFlowMany([
                'Protecting one active focus block at a time improves follow-through.',
                'Keeping one protected focus block at a time usually improves completion consistency.',
                'A single protected focus block helps execution stay steady.',
                'Holding one active focus block at a time keeps completion more reliable.',
                'One protected focus block at a time is usually easier to execute well.',
            ], $seedContext, false),
            'fallback_nearest' => $this->forFlowMany([
                'Using the nearest open slot keeps progress moving instead of delaying the work.',
                'Choosing the closest available slot helps you keep momentum now, not later.',
                'The nearest open window gives you a practical next step without postponing.',
                'Picking the closest slot keeps this moving while the context is still fresh.',
                'A nearest-slot choice helps you act now rather than defer the task.',
            ], $seedContext, false),
            'partial_fit' => $this->forFlowMany([
                'A right-sized plan is usually easier to execute than an overloaded schedule.',
                'A smaller realistic plan is often more sustainable than trying to fit everything at once.',
                'A manageable plan tends to produce better follow-through than an overpacked one.',
                'Keeping this plan lighter makes execution steadier than forcing every item in one pass.',
                'A realistic subset is typically easier to complete than an overloaded set.',
            ], $seedContext, false),
            'next_step_prompt' => $this->forFlowMany([
                'Once one item is added or unblocked, I can schedule it right away.',
                'As soon as one item is available, I can place it immediately.',
                'When one item opens up, I can schedule it in the next step.',
                'The moment an item is ready, I can slot it without delay.',
                'If one item becomes available, I can schedule it immediately.',
            ], $seedContext, false),
            default => $this->forFlowMany([
                'Using real open windows is more reliable than overbooking the day.',
                'Working with actual availability usually leads to steadier execution than overpacking.',
                'Plans built on real open time are easier to sustain than overloaded ones.',
                'Scheduling around true availability reduces spillover and keeps progress realistic.',
                'Using real gaps helps maintain momentum without creating avoidable overload.',
            ], $seedContext, false),
        };

        return $this->selectTemplate('coaching.'.$toneKey, $seedContext, $templates);
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildFramingFallbackForScheduleRows(
        int $blockCount,
        string $windowPhrase,
        string $windowLead,
        string $primaryLabel,
        string $secondLabel,
        array $seedContext
    ): string {
        if ($blockCount <= 1) {
            return $this->selectTemplate('schedule_rows.single', $seedContext, $this->forFlowMany([
                "I set aside {$windowPhrase} for {$primaryLabel}; the row below shows the exact start and end.",
                "I lined up one focused block for {$primaryLabel} {$windowPhrase}; the next line has the exact time.",
                "{$windowLead}, I placed {$primaryLabel} on your plan and the row below shows the exact timing.",
                "I carved out {$windowPhrase} for {$primaryLabel}; the row below shows the exact start and end so it is easy to follow.",
                "{$windowLead}, I set one focused slot for {$primaryLabel}; exact timing is right below.",
            ],
                $seedContext
            ));
        }

        return $this->selectTemplate('schedule_rows.multi', $seedContext, $this->forFlowMany([
            "I mapped {$blockCount} blocks across {$windowPhrase}; each row below is one block you can tweak if needed.",
            "Here is how {$blockCount} blocks land {$windowPhrase}: one row per block under this note.",
            "Across {$windowPhrase} I sequenced {$blockCount} blocks starting with {$primaryLabel} and {$secondLabel}.",
            "{$windowLead}, I lined up {$blockCount} blocks and each row below shows one scheduled window.",
            "I arranged {$blockCount} blocks for {$windowPhrase}, starting with {$primaryLabel} and {$secondLabel}, so execution stays clear.",
        ],
            $seedContext
        ));
    }

    /**
     * @param  array<string, mixed>  $seedContext
     * @return list<string>
     */
    private function forFlow(string $defaultA, string $defaultB, string $defaultC, array $seedContext): array
    {
        return $this->forFlowMany([$defaultA, $defaultB, $defaultC], $seedContext);
    }

    /**
     * @param  list<string>  $templates
     * @param  array<string, mixed>  $seedContext
     * @return list<string>
     */
    private function forFlowMany(array $templates, array $seedContext, bool $applyTaskFirstRewrite = true): array
    {
        $flowSource = trim((string) ($seedContext['flow_source'] ?? 'schedule'));
        if ($flowSource !== 'prioritize_schedule' || ! $applyTaskFirstRewrite) {
            return $templates;
        }

        return array_map(
            static fn (string $template): string => preg_replace('/\bplan\b/u', 'task-first plan', $template) ?? $template,
            $templates
        );
    }

    /**
     * @param  array<string, mixed>  $replacements
     */
    private function render(string $template, array $replacements): string
    {
        $pairs = [];
        foreach ($replacements as $key => $value) {
            $pairs['{'.$key.'}'] = (string) $value;
        }

        return strtr($template, $pairs);
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
            'flow:schedule',
            'source:'.(string) ($seedContext['flow_source'] ?? 'schedule'),
            'section:'.$section,
            'scenario:'.(string) ($seedContext['scenario_key'] ?? 'default'),
            'thread:'.(string) ($seedContext['thread_id'] ?? '0'),
            'window:'.(string) ($seedContext['requested_window_label'] ?? 'window'),
            'count:'.(string) ($seedContext['placed_count'] ?? '0'),
            'day:'.$this->resolveDayBucket($seedContext),
            'prompt:'.(string) ($seedContext['prompt_key'] ?? 'no_prompt'),
            'req:'.(string) ($seedContext['request_bucket'] ?? 'default'),
            'turn:'.(string) ($seedContext['turn_seed'] ?? '0'),
        ]);
        $index = abs((int) crc32($seedBase)) % count($templates);

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
