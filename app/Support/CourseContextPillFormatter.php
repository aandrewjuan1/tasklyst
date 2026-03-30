<?php

namespace App\Support;

final class CourseContextPillFormatter
{
    /**
     * Honorific prefixes matched case-insensitively on the first word (with or without a trailing period).
     *
     * @var list<string>
     */
    private const HONORIFICS = [
        'prof',
        'dr',
        'dra',
        'engr',
        'eng',
        'mr',
        'ms',
        'mrs',
        'miss',
        'rev',
        'atty',
        'hon',
    ];

    /**
     * Compact label for the course pill: "[Honorific]. [Surname]: [Subject]" when both are present.
     */
    public static function compactLine(string $subjectName, string $teacherName): ?string
    {
        $subject = trim($subjectName);
        $teacher = trim($teacherName);

        if ($subject === '' && $teacher === '') {
            return null;
        }

        if ($subject === '') {
            return self::compactTeacherOnly($teacher);
        }

        if ($teacher === '') {
            return $subject;
        }

        $parts = preg_split('/\s+/u', $teacher, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return $subject;
        }

        $honorificToken = self::honorificTokenFromParts($parts);

        if ($honorificToken !== null) {
            $rest = array_slice($parts, 1);
            $surname = self::surnameFromNameParts($rest);
            if ($surname !== '') {
                return self::normalizeHonorificForDisplay($honorificToken).' '.$surname.': '.$subject;
            }

            return $teacher.': '.$subject;
        }

        $surname = self::surnameFromNameParts($parts);
        if ($surname !== '') {
            return $surname.': '.$subject;
        }

        return $teacher.': '.$subject;
    }

    private static function compactTeacherOnly(string $teacher): string
    {
        $parts = preg_split('/\s+/u', $teacher, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return $teacher;
        }

        $honorificToken = self::honorificTokenFromParts($parts);

        if ($honorificToken !== null) {
            $rest = array_slice($parts, 1);
            $surname = self::surnameFromNameParts($rest);
            if ($surname !== '') {
                return self::normalizeHonorificForDisplay($honorificToken).' '.$surname;
            }
        }

        return $teacher;
    }

    /**
     * @param  list<string>  $parts
     */
    private static function honorificTokenFromParts(array $parts): ?string
    {
        if ($parts === []) {
            return null;
        }

        $firstNormalized = mb_strtolower(rtrim($parts[0], '.'));

        foreach (self::HONORIFICS as $h) {
            if ($firstNormalized === mb_strtolower(rtrim($h, '.'))) {
                return $parts[0];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $parts  Name parts with honorific already stripped, or full name
     */
    private static function surnameFromNameParts(array $parts): string
    {
        if ($parts === []) {
            return '';
        }

        return $parts[array_key_last($parts)];
    }

    private static function normalizeHonorificForDisplay(string $token): string
    {
        $base = rtrim(trim($token), '.');

        if ($base === '') {
            return $token;
        }

        $lower = mb_strtolower($base);

        return mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1).'.';
    }
}
