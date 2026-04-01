<?php

namespace App\Services\LLM\Scheduling;

/**
 * Applies structured edit operations to an in-memory proposal draft (canonical times stay server-owned).
 */
final class ScheduleDraftMutationService
{
    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  array<int, array<string, mixed>>  $operations
     * @return array{
     *   ok: bool,
     *   proposals: array<int, array<string, mixed>>,
     *   error: ?string,
     *   applied_ops_count: int,
     *   changed_proposal_ids: array<int, string>,
     *   no_effect_ops: array<int, string>,
     *   clarification_required: bool
     * }
     */
    public function applyOperations(array $proposals, array $operations, string $timezoneName): array
    {
        $encoded = json_encode($proposals);
        /** @var array<int, array<string, mixed>> $copy */
        $copy = is_string($encoded) ? json_decode($encoded, true) : [];
        if (! is_array($copy)) {
            $copy = $proposals;
        }
        $appliedOpsCount = 0;
        $noEffectOps = [];

        foreach ($operations as $op) {
            if (! is_array($op)) {
                continue;
            }
            $type = strtolower(trim((string) ($op['op'] ?? '')));
            if ($type === '' || $type === 'none') {
                continue;
            }

            $idx = (int) ($op['proposal_index'] ?? -1);
            if ($idx < 0 || $idx >= count($copy)) {
                return [
                    'ok' => false,
                    'proposals' => $proposals,
                    'error' => 'That list position is not valid for this schedule.',
                    'applied_ops_count' => 0,
                    'changed_proposal_ids' => [],
                    'no_effect_ops' => [],
                    'clarification_required' => false,
                ];
            }

            try {
                $tz = new \DateTimeZone($timezoneName !== '' ? $timezoneName : 'UTC');
            } catch (\Throwable) {
                $tz = new \DateTimeZone('UTC');
            }

            if (in_array($type, ['reorder_before', 'reorder_after', 'move_to_position'], true)) {
                $applied = $this->applyReorderOperation($copy, $op);
                if ($applied) {
                    $appliedOpsCount++;
                }
            } else {
                $row = &$copy[$idx];
                if (! $this->isMutableProposalRow($row)) {
                    return [
                        'ok' => false,
                        'proposals' => $proposals,
                        'error' => 'That item cannot be edited.',
                        'applied_ops_count' => 0,
                        'changed_proposal_ids' => [],
                        'no_effect_ops' => [],
                        'clarification_required' => false,
                    ];
                }

                $beforeStart = (string) ($row['start_datetime'] ?? '');
                $beforeEnd = (string) ($row['end_datetime'] ?? '');
                $beforeDuration = (int) ($row['duration_minutes'] ?? 0);

                $applied = match ($type) {
                    'shift_minutes' => $this->applyShiftMinutes($row, (int) ($op['delta_minutes'] ?? 0)),
                    'set_duration_minutes' => $this->applyDurationMinutes($row, (int) ($op['duration_minutes'] ?? 0)),
                    'set_local_time_hhmm' => $this->applyLocalTimeHhmm($row, (string) ($op['local_time_hhmm'] ?? ''), $tz),
                    'set_local_date_ymd' => $this->applyLocalDateYmd($row, (string) ($op['local_date_ymd'] ?? ''), $tz),
                    default => false,
                };

                if ($applied) {
                    $afterStart = (string) ($row['start_datetime'] ?? '');
                    $afterEnd = (string) ($row['end_datetime'] ?? '');
                    $afterDuration = (int) ($row['duration_minutes'] ?? 0);
                    if ($beforeStart === $afterStart && $beforeEnd === $afterEnd && $beforeDuration === $afterDuration) {
                        $noEffectOps[] = $type;
                    } else {
                        $appliedOpsCount++;
                    }
                }
                unset($row);
            }

            if (! $applied) {
                return [
                    'ok' => false,
                    'proposals' => $proposals,
                    'error' => 'I could not apply that change. Try stating the time or duration differently.',
                    'applied_ops_count' => 0,
                    'changed_proposal_ids' => [],
                    'no_effect_ops' => [],
                    'clarification_required' => false,
                ];
            }
        }

        if (! $this->proposalsArePairwiseNonOverlapping($copy)) {
            return [
                'ok' => false,
                'proposals' => $proposals,
                'error' => 'Those times would overlap with another block in this draft.',
                'applied_ops_count' => 0,
                'changed_proposal_ids' => [],
                'no_effect_ops' => [],
                'clarification_required' => false,
            ];
        }

        $changedIds = $this->changedProposalIds($proposals, $copy);

        return [
            'ok' => true,
            'proposals' => $copy,
            'error' => null,
            'applied_ops_count' => $appliedOpsCount,
            'changed_proposal_ids' => $changedIds,
            'no_effect_ops' => $noEffectOps,
            'clarification_required' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  array<int, array<string, mixed>>  $updated
     * @return array<int, string>
     */
    private function changedProposalIds(array $proposals, array $updated): array
    {
        $changed = [];
        foreach ($updated as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $before = $proposals[$i] ?? null;
            if (! is_array($before)) {
                $changed[] = (string) ($row['proposal_id'] ?? 'idx:'.$i);

                continue;
            }
            if (json_encode($before) !== json_encode($row)) {
                $changed[] = (string) ($row['proposal_id'] ?? 'idx:'.$i);
            }
        }

        return $changed;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $op
     */
    private function applyReorderOperation(array &$rows, array $op): bool
    {
        $originalCount = count($rows);
        $source = (int) ($op['proposal_index'] ?? -1);
        if ($source < 0 || $source >= $originalCount) {
            return false;
        }

        $type = strtolower(trim((string) ($op['op'] ?? '')));
        $target = -1;
        if ($type === 'move_to_position') {
            $target = (int) ($op['target_index'] ?? -1);
        } elseif (in_array($type, ['reorder_before', 'reorder_after'], true)) {
            $anchor = (int) ($op['anchor_index'] ?? -1);
            if ($anchor < 0 || $anchor >= $originalCount) {
                return false;
            }
            if ($type === 'reorder_before') {
                $target = $source < $anchor ? $anchor - 1 : $anchor;
            } else {
                $target = $source < $anchor ? $anchor : $anchor + 1;
            }
        }

        if ($target < 0) {
            return false;
        }
        $target = min($target, $originalCount - 1);
        if ($source === $target) {
            return true;
        }

        $item = $rows[$source];
        array_splice($rows, $source, 1);
        $target = max(0, min($target, count($rows)));
        array_splice($rows, $target, 0, [$item]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isMutableProposalRow(array $row): bool
    {
        if (($row['status'] ?? 'pending') !== 'pending') {
            return false;
        }
        if (trim((string) ($row['title'] ?? '')) === 'No schedulable items found') {
            return false;
        }

        return (string) ($row['start_datetime'] ?? '') !== '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyShiftMinutes(array &$row, int $deltaMinutes): bool
    {
        if ($deltaMinutes === 0) {
            return true;
        }
        try {
            $start = new \DateTimeImmutable((string) $row['start_datetime']);
        } catch (\Throwable) {
            return false;
        }
        $start = $start->modify((string) $deltaMinutes.' minutes');
        $row['start_datetime'] = $start->format(\DateTimeInterface::ATOM);

        $entityType = (string) ($row['entity_type'] ?? '');
        if ($entityType === 'task') {
            $dur = max(1, (int) ($row['duration_minutes'] ?? 30));
            $end = $start->modify("+{$dur} minutes");
            $row['end_datetime'] = $end->format(\DateTimeInterface::ATOM);
        }
        if ($entityType === 'event') {
            $endRaw = (string) ($row['end_datetime'] ?? '');
            if ($endRaw === '') {
                return false;
            }
            try {
                $end = new \DateTimeImmutable($endRaw);
            } catch (\Throwable) {
                return false;
            }
            $end = $end->modify((string) $deltaMinutes.' minutes');
            $row['end_datetime'] = $end->format(\DateTimeInterface::ATOM);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyDurationMinutes(array &$row, int $minutes): bool
    {
        $entityType = (string) ($row['entity_type'] ?? '');
        if ($entityType !== 'task') {
            return false;
        }
        if ($minutes < 15 || $minutes > 24 * 60) {
            return false;
        }
        try {
            $start = new \DateTimeImmutable((string) $row['start_datetime']);
        } catch (\Throwable) {
            return false;
        }
        $row['duration_minutes'] = $minutes;
        $end = $start->modify("+{$minutes} minutes");
        $row['end_datetime'] = $end->format(\DateTimeInterface::ATOM);

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyLocalTimeHhmm(array &$row, string $hhmm, \DateTimeZone $timezone): bool
    {
        $hhmm = trim($hhmm);
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            return false;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            return false;
        }
        try {
            $priorStart = new \DateTimeImmutable((string) $row['start_datetime']);
        } catch (\Throwable) {
            return false;
        }
        $local = $priorStart->setTimezone($timezone);
        $datePart = $local->format('Y-m-d');
        $newLocal = new \DateTimeImmutable($datePart.sprintf(' %02d:%02d:00', $h, $min), $timezone);

        $entityType = (string) ($row['entity_type'] ?? '');
        if ($entityType === 'event') {
            $endRaw = (string) ($row['end_datetime'] ?? '');
            if ($endRaw === '') {
                return false;
            }
            try {
                $oldEndUtc = new \DateTimeImmutable($endRaw);
            } catch (\Throwable) {
                return false;
            }
            $spanSec = max(0, $oldEndUtc->getTimestamp() - $priorStart->getTimestamp());
            if ($spanSec === 0) {
                return false;
            }
            $row['start_datetime'] = $newLocal->format(\DateTimeInterface::ATOM);
            $row['end_datetime'] = $newLocal->modify('+'.(string) $spanSec.' seconds')->format(\DateTimeInterface::ATOM);

            return true;
        }

        $row['start_datetime'] = $newLocal->format(\DateTimeInterface::ATOM);
        if ($entityType === 'task') {
            $dur = max(1, (int) ($row['duration_minutes'] ?? 30));
            $row['end_datetime'] = $newLocal->modify("+{$dur} minutes")->format(\DateTimeInterface::ATOM);
        }

        return true;
    }

    /**
     * Change the local calendar date, keeping the same local time-of-day.
     *
     * @param  array<string, mixed>  $row
     */
    private function applyLocalDateYmd(array &$row, string $ymd, \DateTimeZone $timezone): bool
    {
        $ymd = trim($ymd);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return false;
        }
        try {
            $priorStart = new \DateTimeImmutable((string) $row['start_datetime']);
        } catch (\Throwable) {
            return false;
        }
        $local = $priorStart->setTimezone($timezone);
        $timePart = $local->format('H:i:s');
        try {
            $newLocal = new \DateTimeImmutable($ymd.' '.$timePart, $timezone);
        } catch (\Throwable) {
            return false;
        }

        $entityType = (string) ($row['entity_type'] ?? '');
        if ($entityType === 'event') {
            $endRaw = (string) ($row['end_datetime'] ?? '');
            if ($endRaw === '') {
                return false;
            }
            try {
                $oldEndUtc = new \DateTimeImmutable($endRaw);
            } catch (\Throwable) {
                return false;
            }
            $spanSec = max(0, $oldEndUtc->getTimestamp() - $priorStart->getTimestamp());
            if ($spanSec === 0) {
                return false;
            }
            $row['start_datetime'] = $newLocal->format(\DateTimeInterface::ATOM);
            $row['end_datetime'] = $newLocal->modify('+'.(string) $spanSec.' seconds')->format(\DateTimeInterface::ATOM);

            return true;
        }

        $row['start_datetime'] = $newLocal->format(\DateTimeInterface::ATOM);
        if ($entityType === 'task') {
            $dur = max(1, (int) ($row['duration_minutes'] ?? 30));
            $row['end_datetime'] = $newLocal->modify("+{$dur} minutes")->format(\DateTimeInterface::ATOM);
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     */
    private function proposalsArePairwiseNonOverlapping(array $proposals): bool
    {
        $intervals = [];
        foreach ($proposals as $p) {
            if (! is_array($p) || ($p['status'] ?? '') !== 'pending') {
                continue;
            }
            if (trim((string) ($p['title'] ?? '')) === 'No schedulable items found') {
                continue;
            }
            $s = (string) ($p['start_datetime'] ?? '');
            if ($s === '') {
                continue;
            }
            try {
                $start = new \DateTimeImmutable($s);
            } catch (\Throwable) {
                continue;
            }
            $eRaw = (string) ($p['end_datetime'] ?? '');
            if ($eRaw !== '') {
                try {
                    $end = new \DateTimeImmutable($eRaw);
                } catch (\Throwable) {
                    $end = $start;
                }
            } else {
                $dur = max(1, (int) ($p['duration_minutes'] ?? 30));
                $end = $start->modify("+{$dur} minutes");
            }
            if ($end <= $start) {
                return false;
            }
            $intervals[] = [$start, $end];
        }

        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        for ($i = 1, $n = count($intervals); $i < $n; $i++) {
            if ($intervals[$i][0] < $intervals[$i - 1][1]) {
                return false;
            }
        }

        return true;
    }
}
