@props([
    'variant' => 'default',
])

<div {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center']) }}>
    <livewire:notifications.bell-dropdown :variant="$variant" />
</div>
