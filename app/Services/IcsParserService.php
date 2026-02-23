<?php

namespace App\Services;

use Carbon\Carbon;

class IcsParserService
{
    /**
     * Parse a raw ICS string into a list of event-like arrays.
     *
     * @return array<int, array{
     *     uid: string,
     *     summary: string|null,
     *     description: string|null,
     *     location: string|null,
     *     dtstart: \Carbon\Carbon|null,
     *     dtend: \Carbon\Carbon|null,
     *     all_day: bool
     * }>
     */
    public function parse(string $icsContent): array
    {
        if (trim($icsContent) === '') {
            return [];
        }

        $lines = preg_split("/(\r\n|\n|\r)/", $icsContent) ?: [];

        // Handle folded lines: lines starting with space or tab continue the previous line.
        $unfolded = [];
        foreach ($lines as $line) {
            if ($line === '' && $unfolded === []) {
                continue;
            }

            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                $lastIndex = count($unfolded) - 1;
                if ($lastIndex >= 0) {
                    $unfolded[$lastIndex] .= substr($line, 1);
                }

                continue;
            }

            $unfolded[] = $line;
        }

        $events = [];
        $current = null;

        foreach ($unfolded as $line) {
            $trimmed = trim($line);
            if ($trimmed === 'BEGIN:VEVENT') {
                $current = [
                    'uid' => null,
                    'summary' => null,
                    'description' => null,
                    'location' => null,
                    'dtstart' => null,
                    'dtend' => null,
                    'all_day' => false,
                ];

                continue;
            }

            if ($trimmed === 'END:VEVENT') {
                if ($current !== null && $current['uid'] !== null) {
                    $events[] = $current;
                }

                $current = null;

                continue;
            }

            if ($current === null) {
                continue;
            }

            $parts = explode(':', $trimmed, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$nameAndParams, $value] = $parts;
            $nameParts = explode(';', $nameAndParams);
            $property = strtoupper($nameParts[0]);
            $params = array_slice($nameParts, 1);

            $paramMap = [];
            foreach ($params as $param) {
                $kv = explode('=', $param, 2);
                if (count($kv) === 2) {
                    $paramMap[strtoupper($kv[0])] = strtoupper($kv[1]);
                }
            }

            switch ($property) {
                case 'UID':
                    $current['uid'] = $value;

                    break;

                case 'SUMMARY':
                    $current['summary'] = $value !== '' ? $value : null;

                    break;

                case 'DESCRIPTION':
                    $current['description'] = $value !== '' ? $value : null;

                    break;

                case 'LOCATION':
                    $current['location'] = $value !== '' ? $value : null;

                    break;

                case 'DTSTART':
                    $parsed = $this->parseDateTime($value, $paramMap);
                    $current['dtstart'] = $parsed['value'];
                    $current['all_day'] = $parsed['all_day'] || $current['all_day'];

                    break;

                case 'DTEND':
                    $parsed = $this->parseDateTime($value, $paramMap);
                    $current['dtend'] = $parsed['value'];
                    $current['all_day'] = $parsed['all_day'] || $current['all_day'];

                    break;
            }
        }

        return $events;
    }

    /**
     * @param  array<string, string>  $params
     * @return array{value: \Carbon\Carbon|null, all_day: bool}
     */
    private function parseDateTime(string $value, array $params): array
    {
        $isAllDay = isset($params['VALUE']) && $params['VALUE'] === 'DATE';

        try {
            if ($isAllDay) {
                if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) === 1) {
                    $date = Carbon::createFromDate((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();

                    return ['value' => $date, 'all_day' => true];
                }
            }

            if (str_ends_with($value, 'Z')) {
                $dt = Carbon::parse($value)->utc();

                return ['value' => $dt, 'all_day' => false];
            }

            $dt = Carbon::parse($value);

            return ['value' => $dt, 'all_day' => false];
        } catch (\Throwable) {
            return ['value' => null, 'all_day' => $isAllDay];
        }
    }
}
