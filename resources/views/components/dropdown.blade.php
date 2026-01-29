@props([
    'position' => null,
    'align' => null,
])

@php
    $positionClass = match ($position) {
        'right' => 'left-full top-0 ml-2',
        'left' => 'right-full top-0 mr-2',
        'top' => 'bottom-full mb-2 left-0',
        'bottom' => 'mt-1 left-0',
        default => 'mt-1 left-0',
    };

    $alignClass = match ($align) {
        'start' => 'origin-top-left',
        'end' => 'origin-top-right',
        'center' => 'left-1/2 -translate-x-1/2 origin-top',
        default => 'origin-top-left',
    };
@endphp

<div
    x-data="{
        open: false,
        toggle() {
            this.open = !this.open;
        },
        close() {
            this.open = false;
        },
    }"
    x-on:keydown.escape.stop.prevent="close()"
    x-on:click.outside="close()"
    class="relative inline-block text-left"
    {{ $attributes }}
>
    <div x-on:click="toggle()" x-ref="button">
        {{ $trigger ?? $slot }}
    </div>

    <div
        x-cloak
        x-show="open"
        x-transition
        x-ref="menu"
        class="absolute z-[99999] min-w-40 rounded-md border border-border/60 bg-background/95 shadow-lg backdrop-blur focus:outline-none {{ $positionClass }} {{ $alignClass }}"
        x-on:click.stop
    >
        {{ $content ?? $menu ?? null }}
    </div>
</div>
