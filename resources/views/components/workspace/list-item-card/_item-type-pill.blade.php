@if($type)
    <span class="lic-item-type-pill {{ $itemTypePillKindClass }}">
        @if($kind === 'event')
            <flux:icon name="calendar" class="size-3 shrink-0 opacity-90" />
        @elseif($kind === 'project')
            <flux:icon name="folder" class="size-3 shrink-0 opacity-90" />
        @else
            <flux:icon name="clipboard-document-list" class="size-3 shrink-0 opacity-90" />
        @endif
        {{ $type }}
    </span>
@endif
