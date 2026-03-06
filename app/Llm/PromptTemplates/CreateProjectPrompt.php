<?php

namespace App\Llm\PromptTemplates;

class CreateProjectPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        $fields = 'You are creating a new project for the user, grouping related tasks, not editing an existing one.
Output a JSON object with:
- entity_type: always "project".
- name: short project name (required).
- description: optional longer description of the project.
- start_datetime: optional ISO 8601 start datetime.
- end_datetime: optional ISO 8601 target completion datetime.
- recommended_action: 1–3 sentence summary of how the user should approach this project.
- reasoning: 2–4 sentences explaining why this project grouping and timeline are helpful.
- confidence: number between 0 and 1 describing how confident you are this new project makes sense.';

        return implode(' ', [
            $this->outputAndGuardrails(includeNoPastTimes: false),
            $fields,
            'Only propose projects that represent meaningful multi-step work derived from the user’s context, not trivial one-off tasks.',
        ]);
    }
}
