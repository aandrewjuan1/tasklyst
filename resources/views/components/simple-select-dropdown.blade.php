@props([
    'position' => 'top',
    'align' => 'end',
])

@php
    $panelHeightEst = 200;
    $panelWidthEst = 128;
@endphp

<div
    x-data="{
        open: false,
        placementVertical: @js($position),
        placementHorizontal: @js($align),
        panelHeightEst: {{ $panelHeightEst }},
        panelWidthEst: {{ $panelWidthEst }},
        toggle() {
            if (this.open) {
                return this.close(this.$refs.button);
            }

            this.$refs.button.focus();

            const rect = this.$refs.button.getBoundingClientRect();
            const vh = window.innerHeight;
            const vw = window.innerWidth;
            const contentLeft = 320;

            if (rect.bottom + this.panelHeightEst > vh && rect.top > this.panelHeightEst) {
                this.placementVertical = 'top';
            } else {
                this.placementVertical = 'bottom';
            }
            const endFits = rect.right <= vw && rect.right - this.panelWidthEst >= contentLeft;
            const startFits = rect.left >= contentLeft && rect.left + this.panelWidthEst <= vw;
            if (rect.left < contentLeft) {
                this.placementHorizontal = 'start';
            } else if (endFits) {
                this.placementHorizontal = 'end';
            } else if (startFits) {
                this.placementHorizontal = 'start';
            } else {
                this.placementHorizontal = rect.right > vw ? 'start' : 'end';
            }

            this.open = true;
            this.$dispatch('dropdown-opened');
        },
        close(focusAfter) {
            if (!this.open) return;

            this.open = false;
            const leaveMs = 50;
            setTimeout(() => this.$dispatch('dropdown-closed'), leaveMs);

            focusAfter && focusAfter.focus();
        },
        get panelPlacementClasses() {
            const v = this.placementVertical;
            const h = this.placementHorizontal;
            if (v === 'top' && h === 'end') return 'bottom-full right-0 mb-1';
            if (v === 'top' && h === 'start') return 'bottom-full left-0 mb-1';
            if (v === 'bottom' && h === 'end') return 'top-full right-0 mt-1';
            if (v === 'bottom' && h === 'start') return 'top-full left-0 mt-1';
            return 'bottom-full right-0 mb-1';
        },
    }"
    @keydown.escape.prevent.stop="close($refs.button)"
    @focusin.window="($refs.panel && !$refs.panel.contains($event.target)) && close()"
    x-id="['simple-select-dropdown']"
    data-task-creation-safe
    class="relative inline-block"
    {{ $attributes }}
>
    <div
        x-ref="button"
        role="button"
        tabindex="0"
        @click="toggle()"
        @keydown.enter.prevent="toggle()"
        @keydown.space.prevent="toggle()"
        aria-haspopup="true"
        :aria-expanded="open"
        :aria-controls="$id('simple-select-dropdown')"
        :class="{ 'pointer-events-none': open }"
        class="cursor-pointer [&>*]:cursor-pointer"
    >
        {{ $trigger }}
    </div>

    <div
        x-ref="panel"
        x-show="open"
        x-transition:enter="transition ease-out duration-75"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-50"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-cloak
        @click.outside="close($refs.button)"
        @click="close($refs.button)"
        :id="$id('simple-select-dropdown')"
        :class="panelPlacementClasses"
        class="absolute z-50 min-w-32 overflow-hidden rounded-md border border-border bg-white py-1 text-foreground shadow-md dark:bg-zinc-900 contain-[paint] [&_button]:cursor-pointer"
    >
        {{ $slot }}
    </div>
</div>
