@props([
    'sidebar' => false,
    'logoSize' => 'size-12',
    'iconSize' => 'size-12',
])

<a
    {{ $attributes->merge([
        'href' => route('dashboard'),
        'class' => 'inline-flex items-center gap-0 text-brand-navy-blue dark:text-white',
    ]) }}
>
    <span class="-mr-1 flex aspect-square {{ $logoSize }} items-center justify-center overflow-hidden rounded-md">
        <x-app-logo-icon class="{{ $iconSize }} origin-center scale-[1.9] object-contain" />
    </span>
    <span class="font-sans text-xl font-bold leading-none">
        <span class="text-black dark:text-white">task</span><span style="color: #4786d7;">Lyst</span>
    </span>
</a>
