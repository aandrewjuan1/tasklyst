<?php

namespace App\Services\LLM\Scheduling;

final class ScheduleFallbackConfirmationService
{
    /**
     * @param  array<string, mixed>  $generationData
     * @return array{confirmation_required: bool, data: array<string, mixed>}
     */
    public function finalize(array $generationData, bool $confirmationRequired): array
    {
        $data = is_array($generationData['data'] ?? null) ? $generationData['data'] : [];
        if ($data !== []) {
            foreach (['framing', 'reasoning', 'confirmation'] as $field) {
                $value = trim((string) ($data[$field] ?? ''));
                if ($value === '') {
                    continue;
                }

                $value = preg_replace('/\binto in\b/iu', 'into', $value) ?? $value;
                $value = preg_replace('/\bbefore you save\b/iu', 'before we lock it in', $value) ?? $value;
                $value = preg_replace('/\bearliest realistic windows\b/iu', 'time that fits your availability', $value) ?? $value;
                $value = preg_replace('/\bbiggest work starts first\b/iu', 'priority work starts in a clear first slot', $value) ?? $value;
                $value = preg_replace('/\bfollow-up blocks stay lighter\b/iu', 'later blocks stay manageable', $value) ?? $value;
                $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
                $data[$field] = trim($value);
            }

            $context = is_array($data['confirmation_context'] ?? null) ? $data['confirmation_context'] : [];
            if ($context !== []) {
                $prompt = trim((string) ($context['prompt'] ?? ''));
                if ($prompt !== '') {
                    $prompt = preg_replace('/\s+/u', ' ', $prompt) ?? $prompt;
                    $context['prompt'] = trim($prompt);
                }

                $reasonMessage = trim((string) ($context['reason_message'] ?? ''));
                if ($reasonMessage !== '') {
                    $reasonMessage = preg_replace('/\s+/u', ' ', $reasonMessage) ?? $reasonMessage;
                    $context['reason_message'] = trim($reasonMessage);
                }

                $data['confirmation_context'] = $context;
            }

            $generationData['data'] = $data;
        }

        return [
            'confirmation_required' => $confirmationRequired,
            'data' => $generationData,
        ];
    }
}
