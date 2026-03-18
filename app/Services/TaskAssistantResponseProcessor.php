<?php

namespace App\Services;

use App\Enums\TaskAssistantIntent;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Support\TaskAssistantSchemas;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class TaskAssistantResponseProcessor
{
    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantSnapshotService $snapshotService,
    ) {}

    /**
     * Process and validate LLM response with formatting for student-friendly output.
     *
     * @param  string  $flow
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $snapshot
     * @param  TaskAssistantThread|null  $thread
     * @param  string|null  $originalUserMessage
     * @return array{valid: bool, formatted_content: string, structured_data: array<string, mixed>, errors: array<int, string>}
     */
    public function processResponse(
        string $flow,
        array $data,
        array $snapshot = [],
        ?TaskAssistantThread $thread = null,
        ?string $originalUserMessage = null
    ): array {
        Log::info('task-assistant.response-processor.start', [
            'flow' => $flow,
            'thread_id' => $thread?->id,
            'has_data' => !empty($data),
        ]);

        // Validate structured data
        $validation = $this->validateFlowData($flow, $data, $snapshot);

        // Retry with LLM if validation fails and we have context for retry
        if (!$validation['valid'] && $thread && $originalUserMessage) {
            Log::warning('task-assistant.response-processor.validation_failed', [
                'flow' => $flow,
                'thread_id' => $thread->id,
                'errors' => $validation['errors'],
            ]);

            $retryData = $this->retryWithLLM($flow, $validation['errors'], $snapshot, $thread, $originalUserMessage);
            if (!empty($retryData)) {
                $data = $retryData;
                $validation = $this->validateFlowData($flow, $data, $snapshot);
            }
        }

        // Format to user-friendly text (paragraph style, richer) and include raw structured JSON for transparency
        $formattedContent = $this->formatFlowData($flow, $data);

        Log::info('task-assistant.response-processor.complete', [
            'flow' => $flow,
            'thread_id' => $thread?->id,
            'valid' => $validation['valid'],
            'content_length' => strlen($formattedContent),
        ]);

        return [
            'valid' => $validation['valid'],
            'formatted_content' => $formattedContent,
            'structured_data' => $data,
            'errors' => $validation['errors'],
        ];
    }

    /**
     * Validate flow-specific data against schemas and business rules.
     *
     * @param  string  $flow
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $snapshot
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    private function validateFlowData(string $flow, array $data, array $snapshot): array
    {
        return match ($flow) {
            'advisory' => $this->validateAdvisoryData($data),
            'task_choice' => $this->validateTaskChoiceData($data, $snapshot),
            'daily_schedule' => $this->validateDailyScheduleData($data, $snapshot),
            'study_plan' => $this->validateStudyPlanData($data, $snapshot),
            'review_summary' => $this->validateReviewSummaryData($data, $snapshot),
            'mutating' => $this->validateMutatingData($data),
            default => ['valid' => true, 'data' => $data, 'errors' => []],
        };
    }

    /**
     * Validate advisory flow data.
     * Supports both legacy keys (bullets) and newer keys (points) and is forgiving to small-model outputs.
     *
     * @param  array<string, mixed>  $data
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    private function validateAdvisoryData(array $data): array
    {
        // Accept both 'points' and 'bullets' as arrays
        $pointsKey = array_key_exists('points', $data) ? 'points' : (array_key_exists('bullets', $data) ? 'bullets' : 'points');

        $rules = [
            'summary' => ['nullable', 'string', 'max:2000'],
            "$pointsKey" => ['required', 'array', 'min:1', 'max:20'],
            "$pointsKey.*" => ['required', 'string', 'min:3', 'max:2000'],
            'follow_ups' => ['nullable', 'array', 'max:10'],
            'follow_ups.*' => ['nullable', 'string', 'min:3', 'max:1000'],
        ];

        // Normalize data key for validator
        $validatorData = $data;
        if ($pointsKey === 'bullets' && !array_key_exists('points', $validatorData)) {
            $validatorData['bullets'] = $validatorData['bullets'] ?? $validatorData['points'] ?? [];
        }

        $validator = Validator::make($validatorData, $rules);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'data' => [],
                'errors' => $validator->errors()->all(),
            ];
        }

        // Light content checks (lenient to allow rich outputs)
        $errors = [];
        $summary = (string) ($data['summary'] ?? '');
        $points = $data[$pointsKey] ?? $data['bullets'] ?? [];

        if ($summary !== '' && Str::length($summary) < 20) {
            // warn but not fatal: prefer to let model produce richness
            $errors[] = 'Summary is short — consider adding more context for a richer answer.';
        }

        foreach ($points as $i => $p) {
            if (is_string($p) && Str::length(trim($p)) < 5) {
                $errors[] = "Point " . ($i + 1) . " looks very short.";
            }
        }

        // If only warnings exist, return valid but include warnings in errors array so caller can log/show them
        if (!empty($errors)) {
            return [
                'valid' => true,
                'data' => $data,
                'errors' => $errors,
            ];
        }

        return [
            'valid' => true,
            'data' => $data,
            'errors' => [],
        ];
    }

    // Keep existing taskChoice validation but allow both 'suggested_next_steps' and 'steps' keys
    private function validateTaskChoiceData(array $data, array $snapshot): array
    {
        $validator = new TaskAssistantResponseValidator();
        $baseValidation = $validator->validateTaskChoice($data, $snapshot);

        if (!$baseValidation['valid']) {
            return $baseValidation;
        }

        // non-blocking content checks
        $errors = [];
        $suggestion = (string) ($data['suggestion'] ?? $data['summary'] ?? $data['natural_suggestion'] ?? '');
        $reason = (string) ($data['reason'] ?? '');
        $steps = $data['steps'] ?? $data['suggested_next_steps'] ?? $data['suggested'] ?? [];

        if ($suggestion !== '' && Str::length($suggestion) < 12) {
            $errors[] = 'Suggestion is short; consider richer explanation to help the user.';
        }

        if ($reason !== '' && Str::length($reason) < 20) {
            $errors[] = 'Reason is brief; more context would improve guidance.';
        }

        if (is_array($steps)) {
            foreach ($steps as $i => $s) {
                if (is_string($s) && Str::length(trim($s)) < 6) {
                    $errors[] = "Step " . ($i + 1) . " is very short.";
                }
            }
        }

        if (!empty($errors)) {
            // non-fatal: return valid true but include warnings
            return [
                'valid' => true,
                'data' => $data,
                'errors' => $errors,
            ];
        }

        return $baseValidation;
    }

    /**
     * Daily schedule validation unchanged but permissive about optional fields.
     */
    private function validateDailyScheduleData(array $data, array $snapshot): array
    {
        // reuse original logic (kept for brevity) - you can keep existing implementation
        // ... (call original implementation)
        return (function () use ($data, $snapshot) {
            $rules = [
                'blocks' => ['required', 'array', 'min:1', 'max:48'],
                'blocks.*.start_time' => ['required', 'string'],
                'blocks.*.end_time' => ['required', 'string'],
            ];

            $validator = Validator::make($data, $rules);

            if ($validator->fails()) {
                return [
                    'valid' => false,
                    'data' => [],
                    'errors' => $validator->errors()->all(),
                ];
            }

            // Basic time ordering checks
            $errors = [];
            foreach ($data['blocks'] as $index => $block) {
                $start = $block['start_time'] ?? '';
                $end = $block['end_time'] ?? '';
                if ($start !== '' && $end !== '' && $start >= $end) {
                    $errors[] = "blocks.$index.start_time must be before end_time.";
                }
            }

            if (!empty($errors)) {
                return [
                    'valid' => false,
                    'data' => [],
                    'errors' => $errors,
                ];
            }

            return [
                'valid' => true,
                'data' => $data,
                'errors' => [],
            ];
        })();
    }

    private function validateStudyPlanData(array $data, array $snapshot): array
    {
        // Keep it permissive: items required, labels must exist
        $rules = [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.label' => ['required', 'string', 'min:1', 'max:2000'],
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'data' => [],
                'errors' => $validator->errors()->all(),
            ];
        }

        return [
            'valid' => true,
            'data' => $data,
            'errors' => [],
        ];
    }

    private function validateReviewSummaryData(array $data, array $snapshot): array
    {
        // Keep existing logic but permissive on lengths
        $rules = [
            'completed' => ['required', 'array'],
            'remaining' => ['required', 'array'],
            'summary' => ['nullable', 'string', 'max:2000'],
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'data' => [],
                'errors' => $validator->errors()->all(),
            ];
        }

        return [
            'valid' => true,
            'data' => $data,
            'errors' => [],
        ];
    }

    private function validateMutatingData(array $data): array
    {
        if (!is_array($data) || empty($data)) {
            return [
                'valid' => false,
                'data' => [],
                'errors' => ['Mutating flow data must be a non-empty array.'],
            ];
        }

        return [
            'valid' => true,
            'data' => $data,
            'errors' => [],
        ];
    }

    /**
     * Retry LLM request with correction message.
     * (unchanged)
     */
    private function retryWithLLM(
        string $flow,
        array $errors,
        array $snapshot,
        TaskAssistantThread $thread,
        string $originalUserMessage
    ): array {
        $user = $thread->user;
        $promptData = $this->promptData->forUser($user);
        $promptData['snapshot'] = $snapshot;

        $maxRetries = 2;
        $attempt = 0;
        $lastErrors = $errors;

        while ($attempt <= $maxRetries) {
            $correction = $this->buildCorrectionMessage($flow, $lastErrors, $snapshot);

            Log::info('task-assistant.response-processor.retry', [
                'flow' => $flow,
                'thread_id' => $thread->id,
                'attempt' => $attempt,
            ]);

            $schema = $this->getSchemaForFlow($flow);
            $timeout = (int) config('prism.request_timeout', 120);

            try {
                $structuredResponse = Prism::structured()
                    ->using(Provider::Ollama, 'hermes3:3b')
                    ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                    ->withMessages([
                        new UserMessage($originalUserMessage),
                        new UserMessage($correction),
                    ])
                    ->withSchema($schema)
                    ->withClientOptions(['timeout' => $timeout])
                    ->asStructured();

                $payload = $structuredResponse->structured ?? [];
                if (! is_array($payload)) {
                    $payload = [];
                }

                $validation = $this->validateFlowData($flow, $payload, $snapshot);

                if ($validation['valid']) {
                    Log::info('task-assistant.response-processor.retry_success', [
                        'flow' => $flow,
                        'thread_id' => $thread->id,
                        'attempt' => $attempt,
                    ]);

                    return $payload;
                }

                $lastErrors = $validation['errors'];
                $attempt++;

            } catch (\Throwable $e) {
                Log::error('task-assistant.response-processor.retry_error', [
                    'flow' => $flow,
                    'thread_id' => $thread->id,
                    'attempt' => $attempt,
                    'exception' => $e,
                ]);

                $attempt++;
            }
        }

        Log::warning('task-assistant.response-processor.retry_failed', [
            'flow' => $flow,
            'thread_id' => $thread->id,
            'final_errors' => $lastErrors,
        ]);

        // Return fallback data
        return $this->buildFallbackData($flow, $snapshot);
    }

    private function buildCorrectionMessage(string $flow, array $errors, array $snapshot): string
    {
        $primaryReason = $errors[0] ?? "The {$flow} JSON did not match the required fields.";

        $parts = [
            "Your previous {$flow} JSON was invalid: {$primaryReason}",
            'Please retry the same request with corrected JSON.',
        ];

        match ($flow) {
            'advisory' => $parts[] = 'Ensure the JSON body contains the expected keys and valid arrays where applicable.',
            default => null,
        };

        $parts[] = 'Respond with only the JSON object that matches the schema.';

        return implode(' ', $parts);
    }

    private function getSchemaForFlow(string $flow): \Prism\Prism\Schema\ObjectSchema
    {
        return match ($flow) {
            'advisory' => TaskAssistantSchemas::advisorySchema(),
            'task_choice' => TaskAssistantSchemas::taskChoiceSchema(),
            'daily_schedule' => TaskAssistantSchemas::dailyScheduleSchema(),
            'study_plan' => TaskAssistantSchemas::studyPlanSchema(),
            'review_summary' => TaskAssistantSchemas::reviewSummarySchema(),
            default => TaskAssistantSchemas::advisorySchema(),
        };
    }

    private function buildFallbackData(string $flow, array $snapshot): array
    {
        // Unchanged fallbacks
        return match ($flow) {
            'advisory' => [
                'summary' => 'I need more details to provide tailored guidance. Could you clarify what you want help with?',
                'bullets' => [
                    'Try asking about specific tasks or deadlines',
                    'Consider requesting help with prioritization',
                    'You can ask me to create or update tasks',
                ],
                'follow_ups' => [
                    'Would you like help organizing your tasks?',
                    'Do you need assistance with time management?',
                ],
            ],
            'task_choice' => $this->buildTaskChoiceFallback($snapshot),
            'daily_schedule' => $this->buildDailyScheduleFallback($snapshot),
            'study_plan' => $this->buildStudyPlanFallback($snapshot),
            'review_summary' => $this->buildReviewSummaryFallback($snapshot),
            default => [],
        };
    }

    private function buildTaskChoiceFallback(array $snapshot): array
    {
        $tasks = collect($snapshot['tasks'] ?? []);

        if ($tasks->isEmpty()) {
            return [
                'chosen_task_id' => null,
                'chosen_task_title' => null,
                'summary' => 'No tasks are available. Consider creating some tasks to get started.',
                'reason' => 'Without tasks, I cannot suggest specific next steps.',
                'suggested_next_steps' => [
                    'Create your first task for an important deadline',
                    'Break down a large project into smaller tasks',
                    'Set up a daily routine with task reminders',
                ],
            ];
        }

        $chosen = $tasks->first();
        return [
            'chosen_task_id' => $chosen['id'] ?? null,
            'chosen_task_title' => $chosen['title'] ?? 'First available task',
            'summary' => 'Focus on your most immediate task to build momentum.',
            'reason' => 'Starting with a clear, actionable task helps establish productive habits.',
            'suggested_next_steps' => [
                'Review the task requirements carefully',
                'Block dedicated time to work on it',
                'Gather any resources or information needed',
            ],
        ];
    }

    private function buildDailyScheduleFallback(array $snapshot): array
    {
        return [
            'blocks' => [
                [
                    'start_time' => '09:00',
                    'end_time' => '10:30',
                    'task_id' => null,
                    'event_id' => null,
                    'label' => 'Focus time',
                    'reason' => 'Dedicated morning block for important work.',
                ],
                [
                    'start_time' => '14:00',
                    'end_time' => '15:30',
                    'task_id' => null,
                    'event_id' => null,
                    'label' => 'Review and plan',
                    'reason' => 'Afternoon block for reviewing progress and planning next steps.',
                ],
            ],
            'summary' => 'A simple schedule with focus blocks to structure your day effectively.',
        ];
    }

    private function buildStudyPlanFallback(array $snapshot): array
    {
        return [
            'items' => [
                [
                    'label' => 'Review current task priorities',
                    'task_id' => null,
                    'estimated_minutes' => 25,
                    'reason' => 'Start by understanding what needs attention most.',
                ],
                [
                    'label' => 'Break down complex tasks',
                    'task_id' => null,
                    'estimated_minutes' => 30,
                    'reason' => 'Make large projects more manageable.',
                ],
                [
                    'label' => 'Set up daily review routine',
                    'task_id' => null,
                    'estimated_minutes' => 15,
                    'reason' => 'Build consistency in tracking progress.',
                ],
            ],
            'summary' => 'A foundational study plan to establish productive habits and task management.',
        ];
    }

    private function buildReviewSummaryFallback(array $snapshot): array
    {
        $tasks = collect($snapshot['tasks'] ?? []);
        $totalTasks = $tasks->count();

        return [
            'completed' => [],
            'remaining' => $tasks->take(5)->map(fn ($task) => [
                'task_id' => $task['id'],
                'title' => $task['title'],
            ])->values()->all(),
            'summary' => "You have {$totalTasks} tasks to work on. Focus on completing them systematically rather than all at once.",
            'next_steps' => [
                'Choose one task to complete today',
                'Break it into smaller, manageable steps',
                'Set a specific time to work on it',
            ],
        ];
    }

    /**
     * Format flow data into student-friendly paragraph-style text.
     *
     * @param  string  $flow
     * @param  array<string, mixed>  $data
     * @return string
     */
    private function formatFlowData(string $flow, array $data): string
    {
        $body = match ($flow) {
            'advisory' => $this->formatAdvisoryData($data),
            'task_choice' => $this->formatTaskChoiceData($data),
            'daily_schedule' => $this->formatDailyScheduleData($data),
            'study_plan' => $this->formatStudyPlanData($data),
            'review_summary' => $this->formatReviewSummaryData($data),
            'mutating' => $this->formatMutatingData($data),
            default => $this->formatDefaultData($data),
        };

        return trim($body);
    }

    /**
     * Build a richer paragraph for advisory outputs. Supports both 'points' and 'bullets'.
     *
     * @param  array<string, mixed>  $data
     * @return string
     */
    private function formatAdvisoryData(array $data): string
    {
        $summary = trim((string) ($data['summary'] ?? ''));
        $points = $data['points'] ?? $data['bullets'] ?? [];
        $followUps = $data['follow_ups'] ?? [];

        $paragraphs = [];

        if ($summary !== '') {
            $paragraphs[] = $summary;
        }

        if (is_array($points) && count($points) > 0) {
            // Transform points into natural flowing advice
            $adviceText = $this->transformPointsToNaturalAdvice($points);
            if ($adviceText !== '') {
                $paragraphs[] = $adviceText;
            }
        }

        if (is_array($followUps) && count($followUps) > 0) {
            $questions = array_map(fn ($q) => trim((string) $q), $followUps);
            $questions = array_filter($questions, fn ($q) => $q !== '');
            if (!empty($questions)) {
                $paragraphs[] = 'To help me give you better guidance, you could answer: ' . $this->joinSentences($questions);
            }
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * Transform bullet points into natural, flowing advice paragraphs.
     *
     * @param  array<string>  $points
     * @return string
     */
    private function transformPointsToNaturalAdvice(array $points): string
    {
        $cleanedPoints = array_map(fn ($p) => trim((string) $p), $points);
        $cleanedPoints = array_filter($cleanedPoints, fn ($p) => $p !== '');
        
        if (empty($cleanedPoints)) {
            return '';
        }

        // Remove robotic prefixes and clean up the text
        $processedPoints = [];
        foreach ($cleanedPoints as $point) {
            // Remove common robotic prefixes
            $point = preg_replace('/^(Top priorities|why|Next action|Recommended|Suggested):\s*/i', '', $point);
            
            // Remove task ID references like [31] to make it more natural
            $point = preg_replace('/\[\d+\]\s*/', '', $point);
            
            // Clean up any remaining colons and make it flow
            $point = str_replace(['— why:', '—', 'and'], [', which is important because', ',', 'and'], $point);
            
            // Remove any leading numbers or bullets
            $point = preg_replace('/^[\d\.\-\*\s]+/', '', $point);
            
            if (!empty(trim($point))) {
                $processedPoints[] = ucfirst(trim($point));
            }
        }

        if (empty($processedPoints)) {
            return '';
        }

        // Create natural flowing paragraphs based on content
        return $this->createNaturalAdviceParagraph($processedPoints);
    }

    /**
     * Create a natural advice paragraph from processed points.
     *
     * @param  array<string>  $points
     * @return string
     */
    private function createNaturalAdviceParagraph(array $points): string
    {
        if (count($points) === 1) {
            return $points[0];
        }

        if (count($points) === 2) {
            return $points[0] . ' ' . lcfirst($points[1]);
        }

        // For multiple points, create a more structured but natural paragraph
        $mainPoint = array_shift($points);
        $supportingPoints = $points;

        $paragraph = $mainPoint;
        
        if (!empty($supportingPoints)) {
            $paragraph .= ' Specifically, ' . lcfirst(implode(', ', array_slice($supportingPoints, 0, -1)));
            
            if (count($supportingPoints) > 1) {
                $lastPoint = end($supportingPoints);
                $paragraph .= ', and ' . lcfirst($lastPoint);
            }
        }

        return $paragraph . '.';
    }

    /**
     * Format task choice as a natural paragraph with steps described inline.
     */
    private function formatTaskChoiceData(array $data): string
    {
        $taskId = $data['chosen_task_id'] ?? null;
        $taskTitle = trim((string) ($data['chosen_task_title'] ?? ''));

        $suggestion = trim((string) ($data['suggestion'] ?? $data['summary'] ?? ''));
        $reason = trim((string) ($data['reason'] ?? ''));
        $steps = $data['steps'] ?? $data['suggested_next_steps'] ?? [];

        $parts = [];

        if ($suggestion !== '') {
            $parts[] = $suggestion;
        }

        if ($reason !== '') {
            $parts[] = $this->makeNaturalReasoning($reason);
        }

        if ($taskId !== null && $taskTitle !== '') {
            $parts[] = "The task I'm referring to is \"{$taskTitle}\".";
        }

        if (is_array($steps) && !empty($steps)) {
            $stepText = $this->makeNaturalSteps($steps);
            if ($stepText !== '') {
                $parts[] = $stepText;
            }
        }

        if (empty($parts)) {
            return 'I recommend focusing on your most important task to make progress today.';
        }

        return implode("\n\n", $parts);
    }

    /**
     * Make reasoning sound more natural.
     *
     * @param  string  $reason
     * @return string
     */
    private function makeNaturalReasoning(string $reason): string
    {
        // Remove robotic prefixes
        $reason = preg_replace('/^(This is important because|The reason is|Why):\s*/i', '', $reason);
        
        // Make it flow better
        return ucfirst($reason);
    }

    /**
     * Make steps sound more natural and conversational.
     *
     * @param  array<string>  $steps
     * @return string
     */
    private function makeNaturalSteps(array $steps): string
    {
        $cleanSteps = array_map(fn ($s) => trim((string) $s), $steps);
        $cleanSteps = array_filter($cleanSteps, fn ($s) => $s !== '');
        
        if (empty($cleanSteps)) {
            return '';
        }

        // Remove numbered list formatting and make it conversational
        $processedSteps = [];
        foreach ($cleanSteps as $step) {
            $step = preg_replace('/^[\d\.\-\*\s]+/', '', $step); // Remove numbers/bullets
            $step = preg_replace('/^(Step|Next|Action):\s*/i', '', $step); // Remove prefixes
            
            if (!empty(trim($step))) {
                $processedSteps[] = lcfirst(trim($step));
            }
        }

        if (empty($processedSteps)) {
            return '';
        }

        if (count($processedSteps) === 1) {
            return "Start by " . $processedSteps[0] . ".";
        }

        if (count($processedSteps) === 2) {
            return "Start by " . $processedSteps[0] . ", then " . $processedSteps[1] . ".";
        }

        // For multiple steps, create a natural flow
        $first = array_shift($processedSteps);
        $middle = array_slice($processedSteps, 0, -1);
        $last = end($processedSteps);

        $text = "Start by " . $first;
        
        if (!empty($middle)) {
            $text .= ", then " . implode(', then ', $middle);
        }
        
        $text .= ", and finally " . $last . ".";

        return $text;
    }

    private function formatDailyScheduleData(array $data): string
    {
        $summary = trim((string) ($data['summary'] ?? ''));
        $blocks = $data['blocks'] ?? [];

        $paragraphs = [];
        if ($summary !== '') {
            $paragraphs[] = $summary;
        }

        if (is_array($blocks) && !empty($blocks)) {
            $sentences = [];
            foreach ($blocks as $block) {
                if (!is_array($block)) continue;
                $start = (string) ($block['start_time'] ?? '');
                $end = (string) ($block['end_time'] ?? '');
                $label = (string) ($block['label'] ?? $block['title'] ?? 'Focus time');
                $reason = (string) ($block['reason'] ?? $block['note'] ?? '');
                $ref = $label;
                if ($block['task_id'] ?? null) {
                    $ref .= ' (task ' . $block['task_id'] . ')';
                } elseif ($block['event_id'] ?? null) {
                    $ref .= ' (event ' . $block['event_id'] . ')';
                }

                $time = trim($start . '–' . $end, '–');
                $sentence = ($time !== '' ? $time . ': ' : '') . $ref;
                if ($reason !== '') {
                    $sentence .= ' — ' . $reason;
                }

                $sentences[] = $sentence;
            }

            if (!empty($sentences)) {
                $paragraphs[] = 'Planned time blocks: ' . $this->joinSentences($sentences);
            }
        }

        return implode("\n\n", $paragraphs);
    }

    private function formatStudyPlanData(array $data): string
    {
        $summary = trim((string) ($data['summary'] ?? ''));
        $items = $data['items'] ?? [];

        $paragraphs = [];
        if ($summary !== '') {
            $paragraphs[] = $summary;
        }

        if (is_array($items) && !empty($items)) {
            $sentences = [];
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $label = trim((string) ($item['label'] ?? ''));
                $minutes = $item['estimated_minutes'] ?? $item['minutes'] ?? null;
                $reason = trim((string) ($item['reason'] ?? $item['why'] ?? ''));
                $s = $label;
                if ($minutes) {
                    $s .= ' (' . (int) $minutes . ' min)';
                }
                if ($reason !== '') {
                    $s .= ' — ' . $reason;
                }
                $sentences[] = $s;
            }

            if (!empty($sentences)) {
                $paragraphs[] = 'Study items include: ' . $this->joinSentences($sentences);
            }
        }

        return implode("\n\n", $paragraphs);
    }

    private function formatReviewSummaryData(array $data): string
    {
        $summary = trim((string) ($data['summary'] ?? ''));
        $completed = $data['completed'] ?? [];
        $remaining = $data['remaining'] ?? [];
        $nextSteps = $data['next_steps'] ?? [];

        $parts = [];
        if ($summary !== '') {
            $parts[] = $summary;
        }

        if (!empty($completed)) {
            $titles = array_map(fn ($t) => trim((string) ($t['title'] ?? '')), $completed);
            $titles = array_filter($titles, fn ($t) => $t !== '');
            if (!empty($titles)) {
                $parts[] = 'Recently completed: ' . $this->joinSentences($titles);
            }
        }

        if (!empty($remaining)) {
            $titles = array_map(fn ($t) => trim((string) ($t['title'] ?? '')), $remaining);
            $titles = array_filter($titles, fn ($t) => $t !== '');
            if (!empty($titles)) {
                $parts[] = 'Still to do: ' . $this->joinSentences($titles);
            }
        }

        if (!empty($nextSteps)) {
            $steps = array_map(fn ($s) => trim((string) $s), $nextSteps);
            $steps = array_filter($steps, fn ($s) => $s !== '');
            if (!empty($steps)) {
                $parts[] = 'Recommended next steps: ' . $this->joinSentences($steps);
            }
        }

        return implode("\n\n", $parts);
    }

    private function formatMutatingData(array $data): string
    {
        if (isset($data['message']) && is_string($data['message'])) {
            return trim((string) $data['message']);
        }

        if (isset($data['ok']) && $data['ok']) {
            return 'Your request has been completed successfully.';
        }

        return 'I encountered an issue while processing your request. Please try again.';
    }

    private function formatDefaultData(array $data): string
    {
        if (isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }

        if (isset($data['summary']) && is_string($data['summary'])) {
            return $data['summary'];
        }

        return 'I\'ve processed your request. Is there anything specific you\'d like me to help you with next?';
    }

    /**
     * Join sentences into a natural flowing paragraph. Uses commas and conjunction for readability.
     *
     * @param  array<int, string>  $sentences
     * @return string
     */
    private function joinSentences(array $sentences): string
    {
        $sentences = array_values($sentences);
        $count = count($sentences);
        if ($count === 0) return '';
        if ($count === 1) return $sentences[0];
        if ($count === 2) return $sentences[0] . ' and ' . $sentences[1];

        $last = array_pop($sentences);
        return implode(', ', $sentences) . ', and ' . $last;
    }
}
