<?php

namespace App\Services\LLM\Scheduling;

final class TaskAssistantWindowPlacementService
{
    /**
     * @param  array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>  $windows
     * @param  array<string, mixed>  $unit
     * @param  array<string, mixed>  $snapshot
     * @return array{0:int,1:\DateTimeImmutable}|null
     */
    public function selectBestFittingWindow(
        array $windows,
        int $requiredMinutes,
        array $unit,
        array $snapshot,
        ?\DateTimeImmutable $minStartAt = null
    ): ?array {
        $best = null;
        foreach ($windows as $index => $window) {
            $start = $window['start'];
            if ($minStartAt instanceof \DateTimeImmutable && $start < $minStartAt) {
                $start = $minStartAt;
            }

            $availableMinutes = (int) floor(($window['end']->getTimestamp() - $start->getTimestamp()) / 60);
            if ($availableMinutes < $requiredMinutes) {
                continue;
            }

            $score = $this->scoreCandidateStart($start, $unit, $snapshot);
            if ($best === null || $score > $best['score']) {
                $best = [
                    'index' => $index,
                    'start' => $start,
                    'score' => $score,
                ];
            }
        }

        if (! is_array($best)) {
            return null;
        }

        return [(int) $best['index'], $best['start']];
    }

    /**
     * @param  array<string, mixed>  $unit
     * @param  array<string, mixed>  $snapshot
     */
    private function scoreCandidateStart(\DateTimeImmutable $startAt, array $unit, array $snapshot): float
    {
        $weights = is_array(config('task-assistant.schedule.window_scoring.weights'))
            ? config('task-assistant.schedule.window_scoring.weights')
            : [];
        $score = 0.0;
        $hour = (int) $startAt->format('H');

        $score += (float) ($weights['earlier_start_bonus'] ?? 1.0) * max(0, 24 - $hour);
        $score += $this->dueSoonScore($startAt, $unit, $snapshot) * (float) ($weights['due_soon_multiplier'] ?? 1.0);
        $score += $this->complexityFitScore($hour, $unit) * (float) ($weights['complexity_fit_multiplier'] ?? 1.0);
        $score += $this->classAdjacencyScore($startAt, $snapshot, $unit) * (float) ($weights['class_adjacency_multiplier'] ?? 1.0);
        $score += $this->energyBiasScore($hour, $snapshot) * (float) ($weights['energy_bias_multiplier'] ?? 1.0);

        return $score;
    }

    /**
     * @param  array<string, mixed>  $unit
     * @param  array<string, mixed>  $snapshot
     */
    private function dueSoonScore(\DateTimeImmutable $startAt, array $unit, array $snapshot): float
    {
        if (($unit['entity_type'] ?? '') !== 'task') {
            return 0.0;
        }

        $task = $this->taskFromSnapshot((int) ($unit['entity_id'] ?? 0), $snapshot);
        $endsAt = is_array($task) ? (string) ($task['ends_at'] ?? '') : '';
        if ($endsAt === '') {
            return 0.0;
        }

        try {
            $deadline = new \DateTimeImmutable($endsAt);
        } catch (\Throwable) {
            return 0.0;
        }

        $hours = ($deadline->getTimestamp() - $startAt->getTimestamp()) / 3600;
        if ($hours <= 0) {
            return 120.0;
        }

        return max(0.0, 120.0 - $hours);
    }

    /**
     * @param  array<string, mixed>  $unit
     */
    private function complexityFitScore(int $hour, array $unit): float
    {
        $complexity = strtolower((string) ($unit['complexity'] ?? ''));
        if (in_array($complexity, ['high', 'hard', 'complex'], true)) {
            if ($hour >= 8 && $hour < 13) {
                return 25.0;
            }

            if ($hour >= 18) {
                return -10.0;
            }
        }

        if (in_array($complexity, ['low', 'easy'], true)) {
            if ($hour >= 18) {
                return 12.0;
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $unit
     */
    private function classAdjacencyScore(\DateTimeImmutable $startAt, array $snapshot, array $unit): float
    {
        if (($unit['entity_type'] ?? '') !== 'task') {
            return 0.0;
        }

        $intervals = is_array($snapshot['school_class_busy_intervals'] ?? null)
            ? $snapshot['school_class_busy_intervals']
            : [];
        $best = 0.0;
        foreach ($intervals as $interval) {
            if (! is_array($interval) || ! is_string($interval['end'] ?? null)) {
                continue;
            }

            try {
                $end = new \DateTimeImmutable((string) $interval['end']);
            } catch (\Throwable) {
                continue;
            }

            $minutesAfter = (int) floor(($startAt->getTimestamp() - $end->getTimestamp()) / 60);
            if ($minutesAfter < 0 || $minutesAfter > 90) {
                continue;
            }

            $complexity = strtolower((string) ($unit['complexity'] ?? ''));
            $candidate = in_array($complexity, ['high', 'hard', 'complex'], true) ? -6.0 : 8.0;
            $best = max($best, $candidate);
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function energyBiasScore(int $hour, array $snapshot): float
    {
        $preferences = is_array($snapshot['schedule_preferences'] ?? null)
            ? $snapshot['schedule_preferences']
            : [];
        $bias = strtolower((string) ($preferences['energy_bias'] ?? 'balanced'));

        if ($bias === 'morning') {
            return ($hour >= 8 && $hour < 13) ? 18.0 : 0.0;
        }

        if ($bias === 'evening') {
            return ($hour >= 18 && $hour < 22) ? 18.0 : 0.0;
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function taskFromSnapshot(int $taskId, array $snapshot): ?array
    {
        if ($taskId <= 0) {
            return null;
        }

        $tasks = is_array($snapshot['tasks'] ?? null) ? $snapshot['tasks'] : [];
        foreach ($tasks as $task) {
            if (is_array($task) && (int) ($task['id'] ?? 0) === $taskId) {
                return $task;
            }
        }

        return null;
    }
}
