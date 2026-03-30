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
     * @return array{ok: bool, proposals: array<int, array<string, mixed>>, error: ?string}
     */
    public function applyOperations(array $proposals, array $operations, string $timezoneName): array
    {
        $encoded = json_encode($proposals);
        /** @var array<int, array<string, mixed>> $copy */
        $copy = is_string($encoded) ? json_decode($encoded, true) : [];
        if (! is_array($copy)) {
            $copy = $proposals;
        }

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
                ];
            }

            $row = &$copy[$idx];
            if (! $this->isMutableProposalRow($row)) {
                return [
                    'ok' => false,
                    'proposals' => $proposals,
                    'error' => 'That item cannot be edited.',
                ];
            }

            try {
                $tz = new \DateTimeZone($timezoneName !== '' ? $timezoneName : 'UTC');
            } catch (\Throwable) {
                $tz = new \DateTimeZone('UTC');
            }

            $applied = match ($type) {
                'shift_minutes' => $this->applyShiftMinutes($row, (int) ($op['delta_minutes'] ?? 0)),
                'set_duration_minutes' => $this->applyDurationMinutes($row, (int) ($op['duration_minutes'] ?? 0)),
                'set_local_time_hhmm' => $this->applyLocalTimeHhmm($row, (string) ($op['local_time_hhmm'] ?? ''), $tz),
                default => false,
            };

            unset($row);

            if (! $applied) {
                return [
                    'ok' => false,
                    'proposals' => $proposals,
                    'error' => 'I could not apply that change. Try stating the time or duration differently.',
                ];
            }
        }

        if (! $this->proposalsArePairwiseNonOverlapping($copy)) {
            return [
                'ok' => false,
                'proposals' => $proposals,
                'error' => 'Those times would overlap with another block in this draft.',
            ];
        }

        return [
            'ok' => true,
            'proposals' => $copy,
            'error' => null,
        ];
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
            $priorStartUtc = new \DateTimeImmutable((string) $row['start_datetime']);
        } catch (\Throwable) {
            return false;
        }
        $local = $priorStartUtc->setTimezone($timezone);
        $datePart = $local->format('Y-m-d');
        $newLocal = new \DateTimeImmutable($datePart.sprintf(' %02d:%02d:00', $h, $min), $timezone);
        $startUtc = $newLocal->setTimezone(new \DateTimeZone('UTC'));

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
            $spanSec = max(0, $oldEndUtc->getTimestamp() - $priorStartUtc->getTimestamp());
            if ($spanSec === 0) {
                return false;
            }
            $row['start_datetime'] = $startUtc->format(\DateTimeInterface::ATOM);
            $row['end_datetime'] = $startUtc->modify('+'.(string) $spanSec.' seconds')->format(\DateTimeInterface::ATOM);

            return true;
        }

        $row['start_datetime'] = $startUtc->format(\DateTimeInterface::ATOM);
        if ($entityType === 'task') {
            $dur = max(1, (int) ($row['duration_minutes'] ?? 30));
            $row['end_datetime'] = $startUtc->modify("+{$dur} minutes")->format(\DateTimeInterface::ATOM);
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
