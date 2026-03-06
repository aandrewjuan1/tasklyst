<?php

namespace App\Llm\PromptTemplates;

class UpdateTaskPropertiesPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        $fields = 'You are updating properties of an existing task, not creating a new one. 
Output a JSON object with:
- entity_type: always "task".
- recommended_action: 1–3 sentence summary of what you recommend changing about the task.
- reasoning: 2–4 sentences explaining why these changes make sense for the student.
- confidence: number between 0 and 1 describing how confident you are that these changes are appropriate.
- properties: an object whose keys are task property names and values are the new values to set.

Allowed properties in properties:
- title: new short title (string).
- description: new description (string).
- status: one of todo|doing|done|blocked (lowercase, if present).
- priority: one of low|medium|high|urgent (lowercase, if present).
- complexity: one of easy|medium|hard (lowercase, if present).
- duration: duration in minutes (integer > 0).
- startDatetime: ISO 8601 start datetime.
- endDatetime: ISO 8601 due datetime.
- tagNames: array of tag names (strings) describing the desired tags for this task.

When the user does not specify enough detail (for example, they say "change the duration" but do not say to what), set properties to an empty object and use recommended_action to ask a clear follow-up question instead of guessing.';

        return implode(' ', [
            $this->outputAndGuardrails(includeNoPastTimes: true),
            $fields,
            'Only propose changes that are consistent with the task data in context and the user\'s explicit request. Do not change properties the user did not ask about unless they are obviously implied (for example, reducing duration when they say the task is too long).',
        ]);
    }
}
