<?php

namespace App\Llm\PromptTemplates;

class UpdateEventPropertiesPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        $fields = 'You are updating properties of an existing event, not creating a new one. 
Output a JSON object with:
- entity_type: always "event".
- recommended_action: 1–3 sentence summary of what you recommend changing about the event.
- reasoning: 2–4 sentences explaining why these changes make sense for the student.
- confidence: number between 0 and 1 describing how confident you are that these changes are appropriate.
- properties: an object whose keys are event property names and values are the new values to set.

Allowed properties in properties:
- title: new short title (string).
- description: new description (string).
- startDatetime: ISO 8601 start datetime.
- endDatetime: ISO 8601 end datetime.
- allDay: boolean flag for all-day events.

When the user does not specify enough detail (for example, they say "make this an all-day event" but the context has no matching event), set properties to an empty object and use recommended_action to ask a clear follow-up question instead of guessing.';

        return implode(' ', [
            $this->outputAndGuardrails(includeNoPastTimes: true),
            $fields,
            'Only propose changes that are consistent with the event data in context and the user\'s explicit request.',
        ]);
    }
}
