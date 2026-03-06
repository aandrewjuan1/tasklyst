<?php

namespace App\Llm\PromptTemplates;

class CreateEventPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        $fields = 'You are creating a new calendar event for the user, not editing an existing one.
Output a JSON object with:
- entity_type: always "event".
- title: short event title (required).
- description: optional longer description.
- start_datetime: optional ISO 8601 start datetime.
- end_datetime: optional ISO 8601 end/due datetime.
- timezone: optional timezone identifier (e.g. Europe/London).
- location: optional location string.
- recommended_action: 1–3 sentence summary of how this event helps the user.
- reasoning: 2–4 sentences explaining why this event and timing are helpful.
- confidence: number between 0 and 1 describing how confident you are this new event is appropriate.';

        return implode(' ', [
            $this->outputAndGuardrails(includeNoPastTimes: true),
            $fields,
            'Only propose events that clearly map to the user’s tasks or projects and avoid duplicating obvious items already in context.',
        ]);
    }
}
