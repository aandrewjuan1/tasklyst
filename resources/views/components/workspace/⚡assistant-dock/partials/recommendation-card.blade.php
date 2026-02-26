@props([
    'snapshot' => [],
])

@php
    $intent = $snapshot['intent'] ?? 'general_query';
    $entityType = $snapshot['entity_type'] ?? 'task';
    $reasoning = $snapshot['reasoning'] ?? '';
    $validationConfidence = (float) ($snapshot['validation_confidence'] ?? 0);
    $usedFallback = (bool) ($snapshot['used_fallback'] ?? false);
    $structured = $snapshot['structured'] ?? [];
    $rankedTasks = $structured['ranked_tasks'] ?? [];
    $rankedEvents = $structured['ranked_events'] ?? [];
    $rankedProjects = $structured['ranked_projects'] ?? [];
    $blockers = $structured['blockers'] ?? [];
    $startDatetime = $structured['start_datetime'] ?? null;
    $endDatetime = $structured['end_datetime'] ?? null;
    $priority = $structured['priority'] ?? null;
    $duration = $structured['duration'] ?? null;
    $isReadonly = in_array($intent, ['prioritize_events', 'prioritize_projects'], true);
@endphp

<div class="mt-3 space-y-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-600 dark:bg-zinc-800/80">
    {{-- Confidence: validation-based, labeled --}}
    <div class="flex flex-wrap items-center gap-2">
        <flux:badge size="sm" color="{{ $validationConfidence >= 0.7 ? 'green' : ($validationConfidence >= 0.4 ? 'amber' : 'zinc') }}">
            {{ __('System-validated') }}: {{ round($validationConfidence * 100) }}%
        </flux:badge>
        @if($usedFallback)
            <flux:badge size="sm" color="amber">{{ __('Rule-based plan') }}</flux:badge>
        @endif
        @if($isReadonly)
            <flux:badge size="sm" color="zinc">{{ __('Read-only') }}</flux:badge>
        @endif
    </div>

    {{-- Reasoning (collapsible) --}}
    @if($reasoning !== '')
        <details class="group">
            <summary class="cursor-pointer text-sm font-medium text-zinc-700 dark:text-zinc-300">
                {{ __('Show reasoning') }}
            </summary>
            <div class="mt-1 whitespace-pre-wrap rounded bg-zinc-100 p-2 text-sm text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                {{ $reasoning }}
            </div>
        </details>
    @endif

    {{-- Blockers --}}
    @if(count($blockers) > 0)
        <flux:callout color="amber">
            <flux:callout.heading>{{ __('Blockers / considerations') }}</flux:callout.heading>
            <ul class="list-inside list-disc text-sm">
                @foreach($blockers as $blocker)
                    <li>{{ is_string($blocker) ? $blocker : ($blocker['description'] ?? json_encode($blocker)) }}</li>
                @endforeach
            </ul>
        </flux:callout>
    @endif

    {{-- Task prioritization: ranked list --}}
    @if(count($rankedTasks) > 0)
        <div>
            <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Suggested order') }}</p>
            <ol class="space-y-1.5">
                @foreach($rankedTasks as $item)
                    <li class="flex items-center gap-2 rounded border border-zinc-100 bg-zinc-50 px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-700/50">
                        <flux:badge size="sm" color="zinc">{{ $item['rank'] ?? $loop->iteration }}</flux:badge>
                        <span class="min-w-0 flex-1 truncate font-medium">{{ $item['title'] ?? __('Task') }}</span>
                        @if(!empty($item['end_datetime']))
                            <span class="shrink-0 text-xs text-zinc-500">{{ \Carbon\Carbon::parse($item['end_datetime'])->format('M j, g:i A') }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    {{-- Events / projects ranked (readonly) --}}
    @if(count($rankedEvents) > 0)
        <div>
            <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Suggested order (events)') }}</p>
            <ol class="space-y-1.5">
                @foreach($rankedEvents as $item)
                    <li class="flex items-center gap-2 rounded border border-zinc-100 bg-zinc-50 px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-700/50">
                        <flux:badge size="sm" color="zinc">{{ $item['rank'] ?? $loop->iteration }}</flux:badge>
                        <span class="min-w-0 flex-1 truncate">{{ $item['title'] ?? __('Event') }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
    @if(count($rankedProjects) > 0)
        <div>
            <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Suggested order (projects)') }}</p>
            <ol class="space-y-1.5">
                @foreach($rankedProjects as $item)
                    <li class="flex items-center gap-2 rounded border border-zinc-100 bg-zinc-50 px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-700/50">
                        <flux:badge size="sm" color="zinc">{{ $item['rank'] ?? $loop->iteration }}</flux:badge>
                        <span class="min-w-0 flex-1 truncate">{{ $item['name'] ?? $item['title'] ?? __('Project') }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    {{-- Schedule / adjust: proposed date-time --}}
    @if($startDatetime || $endDatetime)
        <div class="rounded border border-zinc-100 bg-zinc-50 p-2 text-sm dark:border-zinc-600 dark:bg-zinc-700/50">
            @if($startDatetime)
                <p>{{ __('Start') }} → {{ \Carbon\Carbon::parse($startDatetime)->format('D, M j · g:i A') }}</p>
            @endif
            @if($endDatetime)
                <p>{{ __('End') }} → {{ \Carbon\Carbon::parse($endDatetime)->format('D, M j · g:i A') }}</p>
            @endif
            @if($priority)
                <p>{{ __('Priority') }} → {{ $priority }}</p>
            @endif
            @if($duration !== null && $duration !== '')
                <p>{{ __('Duration') }} → {{ (int) $duration }} {{ __('min') }}</p>
            @endif
        </div>
    @endif

    {{-- Action buttons (Phase 6: display only; Phase 7 will wire to backend) --}}
    <div class="flex flex-wrap gap-2 border-t border-zinc-200 pt-2 dark:border-zinc-600">
        @if($isReadonly)
            <flux:button variant="subtle" size="sm">{{ __('Done') }}</flux:button>
            <flux:button variant="ghost" size="sm">{{ __('Ask something else') }}</flux:button>
        @else
            <flux:button variant="primary" size="sm">{{ __('Accept') }}</flux:button>
            <flux:button variant="subtle" size="sm">{{ __('Modify') }}</flux:button>
            <flux:button variant="ghost" size="sm">{{ __('Reject') }}</flux:button>
        @endif
    </div>
</div>
