@props([
    'position' => 'bottom',
    'align' => 'start',
    'lazy' => false,
])

@php
    $positionClasses = [
        'top' => 'bottom-full mb-1',
        'bottom' => 'top-full mt-1',
        'left' => 'right-full mr-1',
        'right' => 'left-full ml-1',
    ];
    $alignVertical = [
        'start' => 'left-0',
        'end' => 'right-0',
        'center' => 'left-1/2 -translate-x-1/2',
    ];
    $alignHorizontal = [
        'start' => 'top-0',
        'end' => 'bottom-0',
        'center' => 'top-1/2 -translate-y-1/2',
    ];
    $isVertical = in_array($position, ['top', 'bottom'], true);
    $alignMap = $isVertical ? $alignVertical : $alignHorizontal;
    $positionClass = $positionClasses[$position] ?? $positionClasses['bottom'];
    $alignClass = $alignMap[$align] ?? $alignVertical['start'];
    $panelPositionClasses = $positionClass . ' ' . $alignClass;
@endphp

<div
    x-data="{ open: false }"
    x-on:click.outside="open = false"
    x-on:keydown.escape.window="open = false"
    class="relative inline-block"
    {{ $attributes }}
>
    <div
        x-on:click="open = !open"
        aria-haspopup="true"
        x-bind:aria-expanded="open"
        role="button"
    >
        {{ $trigger }}
    </div>

    <div
        x-show="open"
        x-transition
        x-cloak
        class="absolute z-50 min-w-32 overflow-hidden rounded-md border border-border bg-white py-1 text-foreground shadow-md dark:bg-zinc-900 {{ $panelPositionClasses }}"
    >
        @if ($lazy)
            <template x-if="open">
                <div @click.stop="">
                    {{ $slot }}
                </div>
            </template>
        @else
            <div @click.stop="">
                {{ $slot }}
            </div>
        @endif
    </div>
</div>
