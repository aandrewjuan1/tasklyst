@props([
    'position' => 'top',
    'align' => 'end',
])

@php
    $panelPositionClasses = match (true) {
        $position === 'top' && $align === 'end' => 'bottom-full right-0 mb-1',
        $position === 'top' && $align === 'start' => 'bottom-full left-0 mb-1',
        $position === 'bottom' && $align === 'end' => 'top-full right-0 mt-1',
        $position === 'bottom' && $align === 'start' => 'top-full left-0 mt-1',
        default => 'bottom-full right-0 mb-1',
    };
@endphp

<div
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="relative inline-block"
    {{ $attributes }}
>
    <div
        @click="open = !open"
        aria-haspopup="true"
        :aria-expanded="open"
        class="cursor-pointer"
    >
        {{ $trigger }}
    </div>

    <div
        x-show="open"
        x-transition
        x-cloak
        @click="open = false"
        class="absolute z-50 min-w-32 overflow-hidden rounded-md border border-border bg-white py-1 text-foreground shadow-md dark:bg-zinc-900 {{ $panelPositionClasses }}"
    >
        {{ $slot }}
    </div>
</div>
