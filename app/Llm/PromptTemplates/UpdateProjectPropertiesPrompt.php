<?php

namespace App\Llm\PromptTemplates;

class UpdateProjectPropertiesPrompt extends AbstractLlmPromptTemplate
{
    public function systemPrompt(): string
    {
        $fields = 'You are updating properties of an existing project, not creating a new one. 
Output a JSON object with:
- entity_type: always "project".
- recommended_action: 1–3 sentence summary of what you recommend changing about the project.
- reasoning: 2–4 sentences explaining why these changes make sense for the student.
- confidence: number between 0 and 1 describing how confident you are that these changes are appropriate.
- properties: an object whose keys are project property names and values are the new values to set.

Allowed properties in properties:
- name: new short project name (string).
- description: new description (string).
- startDatetime: ISO 8601 start datetime.
- endDatetime: ISO 8601 due datetime.';

        return implode(' ', [
            $this->outputAndGuardrails(includeNoPastTimes: true),
            $fields,
            'Only propose changes that are consistent with the project data in context and the user\'s explicit request.',
        ]);
    }
}
