<?php

namespace App\Services\LLM\Intent;

/**
 * Regex-based intent signals used to validate or override LLM route classification.
 *
 * Heuristic scores (0-1) for prioritization, scheduling, and hybrid (rank + time) intents.
 *
 * @phpstan-type IntentSignals array{prioritization: float, scheduling: float, hybrid: float}
 */
final class TaskAssistantIntentSignalExtractor
{
    private const SCHEDULE_LIKE_PATTERN = '/\b(schedule|scheduling|plan my day|plan the day|my day|today plan|day plan|daily plan|time block|time-block|time blocking|calendar|time slot|when should i work|block out|block time|book time|find time|pencil in|put on my calendar|remind me|availability|free time|open slot|slot me|calendar block)\b/i';

    /**
     * Matches "fit … in" / "squeeze … in" (including "fit in", "fit my stuff in").
     */
    private const FIT_OR_SQUEEZE_IN_PATTERN = '/\b(fit|squeeze)\s+.{0,48}\bin\b/i';

    /**
     * @return IntentSignals
     */
    public function extract(string $normalized): array
    {
        $normalized = TaskAssistantIntentHybridCue::normalizeForSignals(mb_strtolower(trim($normalized)));

        $prioritization = $this->scorePrioritization($normalized);
        $scheduling = $this->scoreScheduling($normalized);
        $floor = (float) config('task-assistant.intent.merge.hybrid_signal_floor', 0.18);
        $hybrid = TaskAssistantIntentHybridCue::scoreHybridSignal($normalized, $prioritization, $scheduling, $floor);

        return [
            'prioritization' => $prioritization,
            'scheduling' => $scheduling,
            'hybrid' => $hybrid,
        ];
    }

    private function scorePrioritization(string $normalized): float
    {
        $score = 0.0;

        if (preg_match('/\b(top|priorit|first|next|important|focus|which|should i (do|work|start))\b/i', $normalized) === 1) {
            $score += 0.72;
        }
        if (preg_match('/\b(list|show|display|find|search|filter|sort|which tasks?|what tasks?|give me|pull up)\b/i', $normalized) === 1) {
            $score += 0.55;
        }
        if (preg_match('/\b(rank|ranking|re-?order|sort by|order by|backlog|triage)\b/i', $normalized) === 1
            || preg_match('/\b(order|ordering)\b/i', $normalized) === 1) {
            $score += 0.48;
        }
        if (preg_match('/\b(urgent|urgency|asap|deadline|rush|due soon|running out of time)\b/i', $normalized) === 1) {
            $score += 0.42;
        }
        if (preg_match('/\b(homework|assignment|assignments|quiz|exam|midterm|finals?|study|syllabus|class(es)?|coursework|project)\b/i', $normalized) === 1) {
            $score += 0.35;
        }
        if (preg_match('/\b(not sure|unsure|no idea|i do not know|dont know|don\'t know|which one|pick for me|what do i do|what to do|where to start|stuck|overwhelm|overwhelmed|drowning|too much stuff|help me pick|help me choose)\b/i', $normalized) === 1) {
            $score += 0.45;
        }
        if (preg_match('/\b(what matters|most important|least important|hardest|easiest|knock out|tackle first|start with|do first)\b/i', $normalized) === 1) {
            $score += 0.4;
        }
        if (preg_match('/\b(due|tag|tags|status|priority|overdue|incomplete)\b/i', $normalized) === 1) {
            $score += 0.2;
        }
        if (preg_match('/\b(task|tasks|to-?dos?|to\s+do)\b/i', $normalized) === 1) {
            $score += 0.15;
        }
        if (preg_match('/\b(\d+)\b/', $normalized) === 1) {
            $score += 0.08;
        }

        return min(1.0, $score);
    }

    private function scoreScheduling(string $normalized): float
    {
        $score = 0.0;

        if (preg_match(self::SCHEDULE_LIKE_PATTERN, $normalized) === 1
            || preg_match(self::FIT_OR_SQUEEZE_IN_PATTERN, $normalized) === 1) {
            $score += 0.72;
        }
        if (preg_match('/\b(afternoon|morning|evening|night|tonight|time block|time slot|later|today|tomorrow)\b/i', $normalized) === 1) {
            $score += 0.2;
        }
        if (preg_match('/\b(this week|next week|weekend|after school|before class|lunch break|during lunch|between classes)\b/i', $normalized) === 1) {
            $score += 0.22;
        }
        if (preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $normalized) === 1) {
            $score += 0.18;
        }
        if (preg_match('/\b(when can i|what time|what\'?s a good time|when should i|how long|duration|hours?)\b/i', $normalized) === 1) {
            $score += 0.35;
        }
        if (preg_match('/\b(those|them|above)\b/i', $normalized) === 1) {
            $score += 0.1;
        }

        return min(1.0, $score);
    }
}
