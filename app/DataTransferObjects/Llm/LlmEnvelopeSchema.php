<?php

namespace App\DataTransferObjects\Llm;

use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class LlmEnvelopeSchema
{
    /**
     * Build the canonical Prism schema for the LLM response envelope.
     *
     * This schema plays a similar role to Hermes 3's JSON-mode / function-calling schemas:
     * - "intent" + "data" describe the high-level action and its parameters.
     * - "tool_call" is the model's proposal for a concrete tool invocation, analogous to a function call.
     * - The backend is responsible for executing tools and may override or ignore proposals for safety.
     */
    public static function make(string $schemaVersion, array $allowedTools): ObjectSchema
    {
        $toolEnumValues = array_values($allowedTools);

        return new ObjectSchema(
            name: 'llm_envelope',
            description: 'Canonical LLM response envelope',
            properties: [
                new StringSchema(
                    name: 'schema_version',
                    description: 'Schema version string that must match the backend expectation'
                ),
                new EnumSchema(
                    name: 'intent',
                    description: 'High-level intent classification',
                    options: [
                        'schedule',
                        'create',
                        'update',
                        'prioritize',
                        'list',
                        'general',
                        'clarify',
                        'error',
                    ]
                ),
                new ObjectSchema(
                    name: 'data',
                    description: 'Intent-specific payload object',
                    properties: [],
                    allowAdditionalProperties: true,
                ),
                new ObjectSchema(
                    name: 'tool_call',
                    description: 'Optional tool invocation proposal',
                    properties: [
                        new EnumSchema(
                            name: 'tool',
                            description: 'Tool name to invoke',
                            options: $toolEnumValues,
                        ),
                        new ObjectSchema(
                            name: 'args',
                            description: 'Tool arguments object',
                            properties: [],
                            allowAdditionalProperties: true,
                        ),
                        new StringSchema(
                            name: 'client_request_id',
                            description: 'Opaque client request identifier used for idempotency'
                        ),
                        new BooleanSchema(
                            name: 'confirmation_required',
                            description: 'Whether the client should confirm before executing the tool'
                        ),
                    ]
                ),
                new StringSchema(
                    name: 'message',
                    description: 'User-facing natural language explanation'
                ),
                new ObjectSchema(
                    name: 'meta',
                    description: 'Additional metadata about the response',
                    properties: [
                        new NumberSchema(
                            name: 'confidence',
                            description: 'Model confidence between 0.0 and 1.0'
                        ),
                    ],
                    requiredFields: ['confidence']
                ),
            ],
            requiredFields: [
                'schema_version',
                'intent',
                'data',
                'message',
                'meta',
            ]
        );
    }
}
