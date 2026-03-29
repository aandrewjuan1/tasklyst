<?php

namespace App\Support\LLM;

/**
 * User-facing prioritize copy used only when the prioritize narrative LLM request fails
 * (transport/connection after retries). Do not use when the model returns successfully.
 */
final class PrioritizeNarrativeConnectionFallback
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function framing(array $items, string $userMessage): string
    {
        $lead = self::framingLeadFromUserMessage($userMessage);
        if ($lead !== null && trim($lead) !== '') {
            return $lead;
        }

        $n = count($items);

        return $n === 1
            ? (string) __('Here’s the one step I’d put at the front of the line right now—by urgency and deadlines.')
            : (string) __('Here are the next steps I’d take, from most time-sensitive on down.');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function reasoning(array $items): string
    {
        $first = $items[0] ?? null;
        if (! is_array($first)) {
            return TaskAssistantPrioritizeOutputDefaults::reasoningWhenEmpty();
        }

        $title = trim((string) ($first['title'] ?? ''));
        if ($title === '') {
            return TaskAssistantPrioritizeOutputDefaults::reasoningWhenEmpty();
        }

        $count = count($items);
        $type = strtolower(trim((string) ($first['entity_type'] ?? 'task')));

        $firstParagraph = match (true) {
            $type === 'event' => (string) __('I’d start with “:title” first—it’s the most time-bound item on this slice, so it’s worth planning around before the rest.', [
                'title' => $title,
            ]),
            $type === 'project' => (string) __('I’d put “:title” first here—projects are ordered by urgency so you can see where to focus.', [
                'title' => $title,
            ]),
            default => self::reasoningFirstTaskParagraph($first, $title),
        };

        $tail = self::reasoningMultiItemTail($count);

        return trim($firstParagraph.$tail);
    }

    private static function framingLeadFromUserMessage(string $userMessage): ?string
    {
        $m = mb_strtolower(trim($userMessage));
        if ($m === '') {
            return null;
        }

        if (preg_match('/\b(what should i do first|what to do first|where (do i|should i) start|what do i start (with)?)\b/u', $m) === 1) {
            return (string) __('For what to do first, I’d look at the item below—it’s ordered by urgency and your deadlines.');
        }

        if (preg_match('/\b(top\s+\d+|top tasks?|most urgent|as soon as possible|asap)\b/u', $m) === 1) {
            return (string) __('Here’s what needs attention soonest, based on urgency and due dates.');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $first
     */
    private static function reasoningFirstTaskParagraph(array $first, string $title): string
    {
        $due = trim((string) ($first['due_phrase'] ?? ''));
        $priorityRaw = trim((string) ($first['priority'] ?? ''));
        $priorityLabel = $priorityRaw !== ''
            ? sprintf('%s %s', ucfirst(mb_strtolower($priorityRaw)), __('priority'))
            : '';

        if ($due !== '' && $due !== 'no due date' && $priorityLabel !== '') {
            return trim((string) __('I’d start with “:title” first—it’s :due and marked :priority, so it rises above the other rows here.', [
                'title' => $title,
                'due' => $due,
                'priority' => $priorityLabel,
            ]));
        }

        if ($due !== '' && $due !== 'no due date') {
            return trim((string) __('I’d start with “:title” first—it’s :due, so it comes before the rest on this list.', [
                'title' => $title,
                'due' => $due,
            ]));
        }

        if ($priorityLabel !== '') {
            return trim((string) __('I’d start with “:title” first—it’s :priority, so it lands at the top of this slice.', [
                'title' => $title,
                'priority' => $priorityLabel,
            ]));
        }

        return trim((string) __('I’d start with “:title” first on this list.', [
            'title' => $title,
        ]));
    }

    private static function reasoningMultiItemTail(int $itemCount): string
    {
        if ($itemCount <= 1) {
            return '';
        }

        return ' '.trim((string) __(' The rows underneath follow the same urgency order—tackle them from top to bottom when you are ready.'));
    }
}
