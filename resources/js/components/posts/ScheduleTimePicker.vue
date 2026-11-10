<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    addDaysToDate,
    describeFromNow,
    zonedParts,
    zonedToInstant,
} from '@/lib/zonedTime';

const props = defineProps<{
    timezone: string;
    disabled?: boolean;
}>();

/** "YYYY-MM-DDTHH:mm" in the post's timezone, or "" when no time is set. */
const value = defineModel<string>({ required: true });

const now = ref(new Date());
let clock: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    clock = setInterval(() => (now.value = new Date()), 15000);
});

onBeforeUnmount(() => clearInterval(clock));

const date = ref(value.value ? value.value.slice(0, 10) : '');
const time = ref(value.value ? value.value.slice(11, 16) : '');

watch(value, (newValue) => {
    date.value = newValue ? newValue.slice(0, 10) : '';
    time.value = newValue ? newValue.slice(11, 16) : '';
});

const nowParts = computed(() => zonedParts(now.value, props.timezone));
const earliestTime = computed(() => {
    const nextMinute = zonedParts(
        new Date(now.value.getTime() + 60000),
        props.timezone,
    );

    return date.value === nowParts.value.date ? nextMinute.time : undefined;
});

const chosenInstant = computed(() =>
    date.value && time.value
        ? zonedToInstant(date.value, time.value, props.timezone)
        : null,
);
const isPast = computed(
    () =>
        chosenInstant.value !== null &&
        chosenInstant.value.getTime() <= now.value.getTime(),
);
const hint = computed(() =>
    chosenInstant.value
        ? describeFromNow(chosenInstant.value, now.value)
        : 'Pick a date and a time.',
);

function emitValue(): void {
    value.value = date.value && time.value ? `${date.value}T${time.value}` : '';
}

function onDateChange(): void {
    if (date.value && !time.value) {
        time.value = earliestTime.value ?? '09:00';
    }

    emitValue();
}

function setIn(minutes: number): void {
    const parts = zonedParts(
        new Date(Date.now() + minutes * 60000),
        props.timezone,
    );
    date.value = parts.date;
    time.value = parts.time;
    emitValue();
}

function setTomorrowAt(clockTime: string): void {
    date.value = addDaysToDate(zonedParts(new Date(), props.timezone).date, 1);
    time.value = clockTime;
    emitValue();
}

function clear(): void {
    date.value = '';
    time.value = '';
    emitValue();
}

const presets = [
    { label: '+15 min', minutes: 15 },
    { label: '+30 min', minutes: 30 },
    { label: '+1 hour', minutes: 60 },
    { label: '+4 hours', minutes: 240 },
];
</script>

<template>
    <div class="flex flex-col gap-3" data-test="schedule-time-picker">
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="flex flex-col gap-1">
                <Label for="schedule-date">Date</Label>
                <Input
                    id="schedule-date"
                    v-model="date"
                    type="date"
                    :min="nowParts.date"
                    :disabled="disabled"
                    data-test="schedule-date"
                    @change="onDateChange"
                />
            </div>
            <div class="flex flex-col gap-1">
                <Label for="schedule-time">Time</Label>
                <Input
                    id="schedule-time"
                    v-model="time"
                    type="time"
                    step="60"
                    :min="earliestTime"
                    :disabled="disabled || !date"
                    data-test="schedule-time"
                    @change="emitValue"
                />
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <Button
                v-for="preset in presets"
                :key="preset.label"
                type="button"
                size="sm"
                variant="outline"
                :disabled="disabled"
                :data-test="`preset-${preset.minutes}`"
                @click="setIn(preset.minutes)"
            >
                {{ preset.label }}
            </Button>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="disabled"
                @click="setTomorrowAt('09:00')"
            >
                Tomorrow 9:00 AM
            </Button>
            <Button
                v-if="date || time"
                type="button"
                size="sm"
                variant="ghost"
                :disabled="disabled"
                @click="clear"
                >Clear</Button
            >
        </div>

        <p
            class="text-xs"
            :class="
                isPast
                    ? 'font-medium text-destructive'
                    : 'text-muted-foreground'
            "
            data-test="schedule-hint"
        >
            {{ hint
            }}<template v-if="chosenInstant"> · {{ timezone }}</template>
        </p>
    </div>
</template>
