<?php

namespace App\Services\LLM\TaskAssistant;

use Illuminate\Support\Arr;

class TaskAssistantToolInterpreter
{
    /**
     * Interpret raw tool suggestion JSON into a normalized envelope.
     *
     * @param  array<string, mixed>|string  $raw
     * @return array{tool: string, arguments: array<string, mixed>}|null
     */
    public function interpret(array|string $raw): ?array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return null;
            }
            $raw = $decoded;
        }

        $tool = $raw['tool'] ?? $raw['name'] ?? $raw['function'] ?? null;

        if (! is_string($tool) || $tool === '') {
            return null;
        }

        $arguments = $raw['arguments'] ?? $raw['params'] ?? $raw['parameters'] ?? [];
        if (! is_array($arguments)) {
            $arguments = [];
        }

        return [
            'tool' => $tool,
            'arguments' => $arguments,
        ];
    }

    /**
     * Resolve a configured tool class name from its logical tool key.
     */
    public function resolveToolClass(string $toolName): ?string
    {
        $config = config('prism-tools', []);
        $class = Arr::get($config, $toolName);

        return is_string($class) ? $class : null;
    }
}
