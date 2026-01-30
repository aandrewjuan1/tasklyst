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
        panelPositionClasses: '',
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
        computePanelClasses() {
            const p = this.effectivePosition;
            const a = this.effectiveAlign;
            const base = this.positionClasses[p] || this.positionClasses.bottom;
            const isVertical = p === 'top' || p === 'bottom';
            const align = isVertical ? this.alignClassesVertical[a] : this.alignClassesHorizontal[a];
            this.panelPositionClasses = base + ' ' + (align || this.alignClassesVertical.start);
        },
        updatePlacement() {
            const trigger = this.$el.querySelector('[aria-haspopup]');
            if (!trigger) return;
            
            requestAnimationFrame(() => {
                const rect = trigger.getBoundingClientRect();
                const spaceAbove = rect.top;
                const spaceBelow = window.innerHeight - rect.bottom;
                const spaceLeft = rect.left;
                const spaceRight = window.innerWidth - rect.right;
                const isVertical = this.preferredPosition === 'top' || this.preferredPosition === 'bottom';
                
                let newPosition, newAlign;
                if (isVertical) {
                    newPosition = spaceBelow >= spaceAbove ? 'bottom' : 'top';
                    newAlign = spaceRight >= spaceLeft ? 'start' : 'end';
                } else {
                    newPosition = spaceRight >= spaceLeft ? 'right' : 'left';
                    newAlign = spaceBelow >= spaceAbove ? 'start' : 'end';
                }
                
                if (this.effectivePosition !== newPosition || this.effectiveAlign !== newAlign) {
                    this.effectivePosition = newPosition;
                    this.effectiveAlign = newAlign;
                    this.computePanelClasses();
                }
            });
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
    x-effect="computePanelClasses()"
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
        :data-open="open"
        :class="open ? '[&>*]:bg-primary/10 [&>*]:dark:bg-primary/20 [&>*]:shadow-lg [&>*]:shadow-primary/20 [&>*]:border-primary/40 [&>*]:dark:border-primary/50 [&>*]:scale-[0.98]' : ''"
        class="transition-all duration-200 ease-out"
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
        :class="panelPositionClasses"
    >
        {{ $slot }}
    </div>
</div>
