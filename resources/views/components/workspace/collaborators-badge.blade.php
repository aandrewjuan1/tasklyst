@props([
    'count' => 0,
])

@if($count > 0)
    <span class="inline-flex items-center gap-1.5 rounded-full border border-black/10 bg-emerald-500/10 px-2.5 py-0.5 font-medium text-emerald-500 dark:border-white/10">
        <flux:icon name="users" class="size-3" />
        <span class="inline-flex items-baseline gap-1">
            <span class="text-[10px] font-semibold uppercase tracking-wide opacity-70">
                {{ __('Collaborators') }}:
            </span>
            <span>
                {{ $count }}
            </span>
        </span>
    </span>
@endif
