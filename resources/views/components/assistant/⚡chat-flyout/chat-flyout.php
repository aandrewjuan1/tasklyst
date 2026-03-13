<?php

use Livewire\Component;

new class extends Component
{
    public ?int $threadId = null;

    public ?string $currentTraceId = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $messages = [];

    public int $pendingAssistantCount = 0;
};

