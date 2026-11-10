<script setup lang="ts">
import { onClickOutside } from '@vueuse/core';
import { CalendarDays, ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref, useTemplateRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { addDaysToDate } from '@/lib/zonedTime';

const props = defineProps<{
    /** Earliest selectable day, "YYYY-MM-DD". */
    min?: string;
    /** Today's date in the post's timezone, "YYYY-MM-DD". */
    today: string;
    disabled?: boolean;
    id?: string;
}>();

/** "YYYY-MM-DD" or "". */
const value = defineModel<string>({ required: true });

const WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

const isOpen = ref(false);
const root = useTemplateRef<HTMLElement>('root');
const shownMonth = ref((value.value || props.today).slice(0, 7));

onClickOutside(root, () => (isOpen.value = false));

watch(isOpen, (open) => {
    if (open) {
        shownMonth.value = (value.value || props.today).slice(0, 7);
    }
});

const pad = (number: number): string => number.toString().padStart(2, '0');

const monthLabel = computed(() => {
    const [year, month] = shownMonth.value.split('-').map(Number);

    return new Intl.DateTimeFormat(undefined, {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, 1)));
});

const days = computed(() => {
    const [year, month] = shownMonth.value.split('-').map(Number);
    const firstWeekday =
        (new Date(Date.UTC(year, month - 1, 1)).getUTCDay() + 6) % 7;
    const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const cells: ({ date: string; day: number } | null)[] = Array.from(
        { length: firstWeekday },
        () => null,
    );

    for (let day = 1; day <= daysInMonth; day++) {
        cells.push({ date: `${year}-${pad(month)}-${pad(day)}`, day });
    }

    return cells;
});

const canGoBack = computed(
    () => !props.min || shownMonth.value > props.min.slice(0, 7),
);

const buttonLabel = computed(() => {
    if (!value.value) {
        return 'Pick a date';
    }

    const [year, month, day] = value.value.split('-').map(Number);

    return new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, day)));
});

function shiftMonth(offset: number): void {
    const [year, month] = shownMonth.value.split('-').map(Number);
    const shifted = new Date(Date.UTC(year, month - 1 + offset, 1));
    shownMonth.value = `${shifted.getUTCFullYear()}-${pad(shifted.getUTCMonth() + 1)}`;
}

function isDisabled(date: string): boolean {
    return props.min !== undefined && date < props.min;
}

function pick(date: string): void {
    if (isDisabled(date)) {
        return;
    }

    value.value = date;
    isOpen.value = false;
}
</script>

<template>
    <div ref="root" class="relative">
        <Button
            :id="id"
            type="button"
            variant="outline"
            class="w-full justify-start font-normal"
            :class="value ? '' : 'text-muted-foreground'"
            :disabled="disabled"
            data-test="date-picker-button"
            @click="isOpen = !isOpen"
        >
            <CalendarDays class="size-4" />
            {{ buttonLabel }}
        </Button>

        <div
            v-if="isOpen"
            class="absolute z-50 mt-2 w-72 rounded-xl border bg-popover p-3 text-popover-foreground shadow-lg"
            data-test="date-picker-calendar"
        >
            <div class="mb-2 flex items-center justify-between">
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    :disabled="!canGoBack"
                    aria-label="Previous month"
                    @click="shiftMonth(-1)"
                >
                    <ChevronLeft class="size-4" />
                </Button>
                <span class="text-sm font-medium">{{ monthLabel }}</span>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label="Next month"
                    @click="shiftMonth(1)"
                >
                    <ChevronRight class="size-4" />
                </Button>
            </div>

            <div
                class="grid grid-cols-7 gap-1 text-center text-xs text-muted-foreground"
            >
                <span v-for="weekday in WEEKDAYS" :key="weekday" class="py-1">{{
                    weekday
                }}</span>
            </div>

            <div class="grid grid-cols-7 gap-1">
                <template
                    v-for="(cell, index) in days"
                    :key="cell?.date ?? `blank-${index}`"
                >
                    <span v-if="cell === null" />
                    <button
                        v-else
                        type="button"
                        class="flex aspect-square items-center justify-center rounded-md text-sm transition-colors"
                        :class="[
                            cell.date === value
                                ? 'bg-primary font-semibold text-primary-foreground'
                                : isDisabled(cell.date)
                                  ? 'cursor-not-allowed text-muted-foreground/40'
                                  : 'hover:bg-accent',
                            cell.date === today && cell.date !== value
                                ? 'ring-1 ring-primary'
                                : '',
                        ]"
                        :disabled="isDisabled(cell.date)"
                        :data-test="`day-${cell.date}`"
                        @click="pick(cell.date)"
                    >
                        {{ cell.day }}
                    </button>
                </template>
            </div>

            <div class="mt-3 flex gap-2 border-t pt-3">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    class="flex-1"
                    @click="pick(today)"
                    >Today</Button
                >
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    class="flex-1"
                    @click="pick(addDaysToDate(today, 1))"
                    >Tomorrow</Button
                >
            </div>
        </div>
    </div>
</template>
