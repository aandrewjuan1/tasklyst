<?php

namespace App\Support\LLM;

use App\Enums\TaskAssistantPrioritizeVariant;

/**
 * User-facing prioritize copy used only when the prioritize narrative LLM request fails
 * (transport/connection after retries). Do not use when the model returns successfully.
 */
final class PrioritizeNarrativeConnectionFallback
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function framing(array $items, string $userMessage, TaskAssistantPrioritizeVariant $variant): string
    {
        $lead = self::framingLeadFromUserMessage($userMessage);
        if ($lead !== null && trim($lead) !== '') {
            return $lead;
        }

        $n = count($items);
        if ($variant === TaskAssistantPrioritizeVariant::FollowupSlice) {
            return $n === 1
                ? (string) __('Here’s the next item in the same order as before.')
                : (string) __('Here are the next items, in the same order as before.');
        }

        if ($variant === TaskAssistantPrioritizeVariant::Browse) {
            return $n === 1
                ? (string) __('Here’s one task that matches what you asked for.')
                : (string) __('Here are tasks that match what you asked for, in a sensible order.');
        }

        return $n === 1
            ? (string) __('Here’s the one step I’d put at the front of the line right now—by urgency and deadlines.')
            : (string) __('Here are the next steps I’d take, from most time-sensitive on down.');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function reasoning(array $items, TaskAssistantPrioritizeVariant $variant): string
    {
        $first = $items[0] ?? null;
        if (! is_array($first)) {
            return TaskAssistantListingDefaults::reasoningWhenEmpty();
        }

        $title = trim((string) ($first['title'] ?? ''));
        if ($title === '') {
            return TaskAssistantListingDefaults::reasoningWhenEmpty();
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
            default => self::reasoningFirstTaskParagraph($first, $title, $variant),
        };

        $tail = self::reasoningMultiItemTail($count, $variant);

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
    private static function reasoningFirstTaskParagraph(array $first, string $title, TaskAssistantPrioritizeVariant $variant): string
    {
        $due = trim((string) ($first['due_phrase'] ?? ''));
        $priorityRaw = trim((string) ($first['priority'] ?? ''));
        $priorityLabel = $priorityRaw !== ''
            ? sprintf('%s %s', ucfirst(mb_strtolower($priorityRaw)), __('priority'))
            : '';

        $browseNote = $variant === TaskAssistantPrioritizeVariant::Browse
            ? ' '.__('It’s a sensible place to start in this filtered view.')
            : '';

        if ($due !== '' && $due !== 'no due date' && $priorityLabel !== '') {
            return trim((string) __('I’d start with “:title” first—it’s :due and marked :priority, so it rises above the other rows here.', [
                'title' => $title,
                'due' => $due,
                'priority' => $priorityLabel,
            ]).$browseNote);
        }

        if ($due !== '' && $due !== 'no due date') {
            return trim((string) __('I’d start with “:title” first—it’s :due, so it comes before the rest on this list.', [
                'title' => $title,
                'due' => $due,
            ]).$browseNote);
        }

        if ($priorityLabel !== '') {
            return trim((string) __('I’d start with “:title” first—it’s :priority, so it lands at the top of this slice.', [
                'title' => $title,
                'priority' => $priorityLabel,
            ]).$browseNote);
        }

        return trim((string) __('I’d start with “:title” first on this list.', [
            'title' => $title,
        ]).$browseNote);
    }

    private static function reasoningMultiItemTail(int $itemCount, TaskAssistantPrioritizeVariant $variant): string
    {
        if ($itemCount <= 1) {
            return '';
        }

        $suffix = match ($variant) {
            TaskAssistantPrioritizeVariant::FollowupSlice => __(' The rows below continue in the same order as before—work through them top to bottom when you can.'),
            TaskAssistantPrioritizeVariant::Browse => __(' The other rows stay in this same order for this slice so you can scan them easily.'),
            TaskAssistantPrioritizeVariant::Rank => __(' The rows underneath follow the same urgency order—tackle them from top to bottom when you are ready.'),
        };

        return ' '.trim((string) $suffix);
    }
}
