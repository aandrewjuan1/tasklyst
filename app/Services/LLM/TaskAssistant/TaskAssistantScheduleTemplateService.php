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
                'I picked this task first because it stood out most clearly in your current priorities before I placed it into a time block.',
                'I selected this task first because it was the clearest priority before scheduling it into a block.',
                'I put this task first because it surfaced as your strongest current priority before time-block placement.',
                'I chose this task first because it stood out in your priorities before I mapped it into a block.',
                'I prioritized this task first because it was the clearest fit from your current priorities before scheduling.',
            ], $seedContext));
        }

        return $this->selectTemplate('selection.summary.multi', $seedContext, $this->forFlowMany([
            'I picked these tasks first because they stood out most clearly in your current priorities before I placed them into time blocks.',
            'I selected these tasks first because they were the clearest priorities before scheduling them into time blocks.',
            'I put these tasks first because they surfaced as your strongest priorities before time-block placement.',
            'I chose these tasks first because they stood out in your priorities before I mapped them into time blocks.',
            'I prioritized these tasks first because they were the clearest fit from your current priorities before scheduling.',
        ], $seedContext));
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildPrioritizeSelectionBasis(array $seedContext): string
    {
        return $this->selectTemplate('selection.basis', $seedContext, $this->forFlowMany([
            'Urgency leads, then explicit priority and earlier deadlines. When tasks are otherwise close, shorter blocks can help break the tie.',
            'I weigh urgency first, then explicit priority and earlier deadlines. If tasks are close, shorter blocks can break the tie.',
            'Urgency drives the first pass, followed by explicit priority and earlier due dates. When scores are close, shorter blocks become the tiebreaker.',
            'I sort by urgency first, then explicit priority and earlier deadlines. For near ties, shorter blocks help decide order.',
            'Urgency comes first, then explicit priority and earlier deadlines. If tasks are nearly equal, shorter blocks help separate them.',
        ], $seedContext));
    }

    /**
     * @param  array<string, mixed>  $seedContext
     */
    public function buildCoachingLineForToneKey(string $toneKey, array $seedContext): string
    {
        $templates = match ($toneKey) {
            'morning_momentum' => $this->forFlowMany([
                'Starting earlier gives you stronger focus and more recovery time if plans shift.',
                'An earlier start usually gives better focus and leaves recovery room if anything moves.',
                'Beginning earlier can protect focus and still leave buffer if the day shifts.',
                'Starting early helps you lock focus and gives you extra room when plans change.',
                'An early block supports stronger focus and leaves fallback space if timing shifts.',
            ], $seedContext),
            'afternoon_restart' => $this->forFlowMany([
                'An afternoon block is a practical restart window after earlier commitments.',
                'This afternoon slot works as a practical reset after earlier commitments.',
                'Afternoon timing gives you a clean restart window after earlier obligations.',
                'A focused afternoon block helps you restart smoothly after earlier commitments.',
                'This is a practical afternoon reset point after earlier commitments.',
            ], $seedContext),
            'evening_closure' => $this->forFlowMany([
                'A defined evening block helps you close the day with one clear win.',
                'A clear evening block can help you end the day with one solid win.',
                'This evening window supports a clean day close with one clear completion.',
                'An evening focus block helps you finish the day with a concrete win.',
                'A structured evening slot gives you a clear way to close the day strong.',
            ], $seedContext),
            'post_class_or_after_commitment' => $this->forFlowMany([
                'Scheduling right after commitments reduces idle time and keeps momentum.',
                'Placing this right after commitments cuts idle gaps and protects momentum.',
                'A right-after-commitments slot lowers downtime and keeps progress moving.',
                'Scheduling this after commitments helps avoid dead time and sustain momentum.',
                'This post-commitment timing reduces drift and keeps momentum steady.',
            ], $seedContext),
            'focus_protection' => $this->forFlowMany([
                'Protecting one active focus block at a time improves completion rate.',
                'Keeping one protected focus block at a time tends to raise completion.',
                'One protected focus block at a time usually improves follow-through.',
                'Protecting a single active focus block helps completion stay consistent.',
                'A single protected focus block at a time improves task completion odds.',
            ], $seedContext),
            'fallback_nearest' => $this->forFlowMany([
                'Using the closest open slot keeps progress moving instead of postponing the task.',
                'Choosing the nearest open slot keeps momentum moving instead of delaying the task.',
                'Picking the closest available slot helps you move forward instead of postponing.',
                'The nearest open slot keeps progress active rather than pushing the task out.',
                'Using the nearest slot helps preserve momentum instead of deferring the work.',
            ], $seedContext),
            'partial_fit' => $this->forFlowMany([
                'A realistic smaller plan is easier to execute than an overloaded one.',
                'A smaller realistic plan is usually easier to follow than an overloaded one.',
                'A right-sized plan is often more executable than a packed schedule.',
                'A lighter realistic plan tends to work better than an overloaded plan.',
                'A practical smaller plan is easier to carry through than an overfull one.',
            ], $seedContext),
            'next_step_prompt' => $this->forFlowMany([
                'Once one item is added or unblocked, I can schedule it immediately.',
                'As soon as one item is added or unblocked, I can place it right away.',
                'When one item is added or unblocked, I can schedule it immediately.',
                'The moment one item is available, I can slot it in right away.',
                'Once an item opens up or is added, I can schedule it immediately.',
            ], $seedContext),
            default => $this->forFlowMany([
                'Using real gaps is better than overbooking and slipping tasks.',
                'Working with real open gaps beats overbooking and later slips.',
                'Real availability usually works better than overbooking and schedule slippage.',
                'Using actual open gaps is more reliable than overpacking and slipping tasks.',
                'Planning around real gaps helps avoid overbooking and delayed carryover.',
            ], $seedContext),
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
    private function forFlowMany(array $templates, array $seedContext): array
    {
        $flowSource = trim((string) ($seedContext['flow_source'] ?? 'schedule'));
        if ($flowSource !== 'prioritize_schedule') {
            return $templates;
        }

        return array_map(
            static fn (string $template): string => str_replace('plan', 'task-first plan', $template),
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
