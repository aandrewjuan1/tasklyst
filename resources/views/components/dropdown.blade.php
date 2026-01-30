@props([
    'position' => 'bottom',
    'align' => 'start',
    'keepOpen' => false,
])

<div
    x-data="{
        open: false,
        closeTimeout: null,
        preferredPosition: @js($position),
        preferredAlign: @js($align),
        effectivePosition: @js($position),
        effectiveAlign: @js($align),
        positionClasses: {
            top: 'bottom-full mb-1',
            bottom: 'top-full mt-1',
            left: 'right-full mr-1',
            right: 'left-full ml-1',
        },
        alignClassesVertical: {
            start: 'left-0',
            end: 'right-0',
            center: 'left-1/2 -translate-x-1/2',
        },
        alignClassesHorizontal: {
            start: 'top-0',
            end: 'bottom-0',
            center: 'top-1/2 -translate-y-1/2',
        },
        updatePlacement() {
            const trigger = this.$el.querySelector('[aria-haspopup]');
            if (!trigger) return;
            const rect = trigger.getBoundingClientRect();
            const spaceAbove = rect.top;
            const spaceBelow = window.innerHeight - rect.bottom;
            const spaceLeft = rect.left;
            const spaceRight = window.innerWidth - rect.right;
            const isVertical = this.preferredPosition === 'top' || this.preferredPosition === 'bottom';
            if (isVertical) {
                this.effectivePosition = spaceBelow >= spaceAbove ? 'bottom' : 'top';
                this.effectiveAlign = spaceRight >= spaceLeft ? 'start' : 'end';
            } else {
                this.effectivePosition = spaceRight >= spaceLeft ? 'right' : 'left';
                this.effectiveAlign = spaceBelow >= spaceAbove ? 'start' : 'end';
            }
        },
        getPanelPositionClasses() {
            const p = this.effectivePosition;
            const a = this.effectiveAlign;
            const base = this.positionClasses[p] || this.positionClasses.bottom;
            const isVertical = p === 'top' || p === 'bottom';
            const align = isVertical ? this.alignClassesVertical[a] : this.alignClassesHorizontal[a];
            return base + ' ' + (align || this.alignClassesVertical.start);
        },
        toggle() {
            if (!this.open) {
                this.updatePlacement();
            }
            this.open = !this.open;
        },
        close() {
            this.open = false;
            if (this.closeTimeout) {
                clearTimeout(this.closeTimeout);
                this.closeTimeout = null;
            }
        },
        scheduleClose() {
            if (this.closeTimeout) {
                clearTimeout(this.closeTimeout);
            }
            this.closeTimeout = setTimeout(() => {
                this.close();
                this.closeTimeout = null;
            }, 350);
        },
        onRootMouseLeave(event) {
            const relatedTarget = event.relatedTarget;
            if (!relatedTarget) {
                this.scheduleClose();
                return;
            }
            if (this.$el.contains(relatedTarget)) {
                return;
            }
            this.scheduleClose();
        },
        onPanelMouseEnter() {
            if (this.closeTimeout) {
                clearTimeout(this.closeTimeout);
                this.closeTimeout = null;
            }
        }
    }"
    @click.outside="close()"
    @keydown.escape.window="close()"
    @mouseleave="onRootMouseLeave($event)"
    class="relative inline-block"
    {{ $attributes }}
>
    <div
        @click="toggle()"
        aria-haspopup="true"
        :aria-expanded="open"
    >
        {{ $trigger }}
    </div>

    <div
        x-show="open"
        x-transition
        x-cloak
        @mouseenter="onPanelMouseEnter()"
        @if(!$keepOpen)
            @click="close()"
        @endif
        class="absolute z-50 min-w-32 overflow-hidden rounded-md border border-border bg-white py-1 text-foreground shadow-md dark:bg-zinc-900"
        :class="getPanelPositionClasses()"
    >
        {{ $slot }}
    </div>
</div>
