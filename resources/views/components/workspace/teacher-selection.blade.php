@props([
    'position' => 'bottom',
    'align' => 'end',
    'readonly' => false,
])

@php
    $readonly = filter_var($readonly, FILTER_VALIDATE_BOOLEAN);
@endphp

{{-- Popover state lives on item-creation root (same scope as newTeacherName) so Flux inputs resolve x-model; nested x-data breaks $parent inside Flux. --}}
<div
    @keydown.escape.prevent.stop="teacherPopoverOpen && closeTeacherPopover($refs.teacherSelectionTrigger)"
    x-id="['teacher-selection-dropdown']"
    class="relative inline-flex w-fit max-w-full min-w-0 flex-col items-stretch"
    data-item-creation-safe
    @click.outside="teacherPopoverOpen && closeTeacherPopover($refs.teacherSelectionTrigger)"
    {{ $attributes }}
>
    <button
        x-ref="teacherSelectionTrigger"
        type="button"
        @if ($readonly)
            disabled
            aria-haspopup="true"
            aria-disabled="true"
            class="inline-flex w-max max-w-full cursor-default items-center gap-1 rounded-full border border-border/60 bg-muted px-2 py-1 text-left font-medium text-muted-foreground opacity-90 outline-none"
        @else
            @click="toggleTeacherPopover()"
            aria-haspopup="true"
            :aria-expanded="teacherPopoverOpen"
            :aria-controls="$id('teacher-selection-dropdown')"
            class="inline-flex w-max max-w-full cursor-pointer items-center gap-1 rounded-full border border-border/60 bg-muted px-2 py-1 text-left font-medium text-muted-foreground outline-none transition-[box-shadow,transform] duration-150 ease-out focus-visible:ring-2 focus-visible:ring-ring"
            :class="{ 'shadow-md ring-1 ring-border/50': teacherPopoverOpen }"
        @endif
        data-item-creation-safe
    >
        <flux:icon
            name="user"
            class="size-3 shrink-0"
            x-show="schoolClassTeacherTriggerLabel()"
        />
        <span
            class="min-w-0 max-w-[min(100%,12rem)] truncate text-[10px] font-semibold uppercase leading-tight sm:max-w-[16rem]"
            x-show="schoolClassTeacherTriggerLabel()"
            x-text="schoolClassTeacherTriggerLabel()"
        ></span>
        <span
            x-show="!schoolClassTeacherTriggerLabel()"
            class="inline-flex min-w-0 items-center gap-1"
        >
            <flux:icon name="user" class="size-3 shrink-0" />
            <span class="whitespace-nowrap text-[10px] font-semibold uppercase">{{ __('Add teacher') }}</span>
        </span>
        <flux:icon name="chevron-down" class="size-3 shrink-0 text-muted-foreground opacity-80" />
    </button>

    <div
        x-ref="teacherSelectionPanel"
        x-show="teacherPopoverOpen"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-cloak
        @click.stop
        :id="$id('teacher-selection-dropdown')"
        :class="teacherPopoverPanelClasses()"
        class="absolute z-50 flex min-w-[16rem] max-w-[min(100vw-2rem,20rem)] flex-col gap-2 overflow-hidden rounded-md border border-border bg-white py-1 text-foreground shadow-md dark:bg-zinc-900 contain-[paint]"
        data-item-creation-safe
        role="menu"
    >
        <div wire:ignore class="flex flex-col gap-2">
            <div class="flex items-center gap-1.5 border-b border-border/60 px-3 py-1.5">
                <flux:input
                    x-model="newTeacherName"
                    x-ref="newTeacherInput"
                    placeholder="{{ __('Create teacher…') }}"
                    size="sm"
                    class="min-w-0 flex-1"
                    @keydown.enter.prevent="!creatingTeacher && newTeacherName?.trim() && $dispatch('teacher-create-request', { teacherName: newTeacherName })"
                />
                <button
                    type="button"
                    x-bind:disabled="!newTeacherName || !newTeacherName.trim() || creatingTeacher"
                    class="cursor-pointer shrink-0 rounded-md p-1 hover:bg-muted/80 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="!creatingTeacher && newTeacherName?.trim() && $dispatch('teacher-create-request', { teacherName: newTeacherName })"
                >
                    <flux:icon name="paper-airplane" class="size-3.5" />
                </button>
            </div>

            <div class="max-h-44 overflow-y-auto">
                <template x-for="teacher in mergedSchoolClassTeachers()" :key="String(teacher.id)">
                    <div
                        class="group flex cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="isSchoolClassTeacherSelected(teacher.id) ? 'bg-muted/90 ring-1 ring-border/60 dark:bg-zinc-800/80' : ''"
                        @click="selectSchoolClassTeacher(teacher)"
                        role="menuitemradio"
                        :aria-checked="isSchoolClassTeacherSelected(teacher.id)"
                    >
                        <span class="flex size-4 shrink-0 items-center justify-center" aria-hidden="true">
                            <flux:icon
                                x-show="isSchoolClassTeacherSelected(teacher.id)"
                                name="check"
                                class="size-4 text-brand-blue"
                            />
                            <span
                                x-show="!isSchoolClassTeacherSelected(teacher.id)"
                                class="size-3.5 rounded-full border border-border/80 dark:border-border/60"
                            ></span>
                        </span>
                        <span x-text="teacher.name" class="min-w-0 flex-1 truncate text-left"></span>
                        <flux:tooltip :content="__('Delete teacher')" position="left">
                            <button
                                type="button"
                                @click.stop="$dispatch('teacher-delete-request', { teacher })"
                                x-bind:disabled="deletingTeacherIds?.has(teacher.id)"
                                class="cursor-pointer shrink-0 rounded p-0.5 disabled:cursor-not-allowed disabled:opacity-50"
                                aria-label="{{ __('Delete teacher') }}"
                            >
                                <flux:icon name="x-mark" class="size-3.5" />
                            </button>
                        </flux:tooltip>
                    </div>
                </template>
                <div
                    x-show="mergedSchoolClassTeachers().length === 0"
                    x-cloak
                    class="px-3 py-2 text-sm text-muted-foreground"
                >
                    {{ __('No teachers yet') }}
                </div>
            </div>
        </div>
    </div>
</div>
