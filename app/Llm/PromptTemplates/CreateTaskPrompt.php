<?php

namespace App\Llm\PromptTemplates;

class CreateTaskPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        $fields = 'You are creating a new task for the user, not editing an existing one. 
Output a JSON object with:
- entity_type: always "task".
- title: short task title (required).
- description: optional longer description.
- start_datetime: optional ISO 8601 start datetime.
- end_datetime: optional ISO 8601 due datetime.
- duration: optional duration in minutes (integer > 0).
- priority: optional one of low|medium|high|urgent.
- tags: optional array of tag names (strings).
- recommended_action: 1–3 sentence summary of what the user should do with this new task.
- reasoning: 2–4 sentences explaining why this task is helpful and why the timing/priority makes sense.
- confidence: number between 0 and 1 describing how confident you are this new task is appropriate.';

        return implode(' ', [
            $this->outputAndGuardrails(includeNoPastTimes: true),
            $fields,
            'Only propose tasks that genuinely help the user progress their existing work, based on the context tasks, events, and projects.',
        ]);
    }
}
