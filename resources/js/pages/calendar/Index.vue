<script setup lang="ts">
import type {
    CalendarOptions,
    EventClickArg,
    EventDropArg,
    EventSourceFuncArg,
    EventInput,
} from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin, {
    type DateClickArg,
} from '@fullcalendar/interaction';
import timeGridPlugin from '@fullcalendar/timegrid';
import FullCalendar from '@fullcalendar/vue3';
import { Head, router } from '@inertiajs/vue3';
import { computed, useTemplateRef } from 'vue';
import { toast } from 'vue-sonner';
import PostEventContent from '@/components/calendar/PostEventContent.vue';
import Heading from '@/components/Heading.vue';
import { zonedParts } from '@/lib/zonedTime';
import {
    events as calendarEvents,
    index as calendarIndex,
} from '@/routes/calendar';
import {
    create as createPost,
    reschedule as reschedulePost,
} from '@/routes/posts';

const props = defineProps<{
    timezone: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Calendar', href: calendarIndex() }],
    },
});

const calendar = useTemplateRef<InstanceType<typeof FullCalendar>>('calendar');
const pad = (value: number): string => value.toString().padStart(2, '0');

/** The calendar runs in UTC mode with wall-clock times, so a Date's UTC parts are the wall time. */
function wallTime(date: Date): string {
    return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}T${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}`;
}

function nowWallTime(offsetMinutes = 0): string {
    const parts = zonedParts(
        new Date(Date.now() + offsetMinutes * 60000),
        props.timezone,
    );

    return `${parts.date}T${parts.time}`;
}

async function loadEvents(info: EventSourceFuncArg): Promise<EventInput[]> {
    const url = calendarEvents({
        query: { start: info.startStr, end: info.endStr },
    }).url;
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        toast.error('Could not load the calendar.');

        return [];
    }

    return response.json();
}

function onDateClick(info: DateClickArg): void {
    let slot = wallTime(info.date);

    if (info.allDay) {
        const day = slot.slice(0, 10);
        slot =
            day === nowWallTime().slice(0, 10)
                ? nowWallTime(1)
                : `${day}T09:00`;
    }

    if (slot <= nowWallTime()) {
        toast.info('That time has already passed.');

        return;
    }

    router.visit(createPost({ query: { scheduled_at: slot } }).url);
}

function onEventClick(info: EventClickArg): void {
    info.jsEvent.preventDefault();
    router.visit(info.event.extendedProps.edit_url as string);
}

function onEventDrop(info: EventDropArg): void {
    const start = info.event.start;

    if (start === null) {
        info.revert();

        return;
    }

    const newTime = wallTime(start);

    if (newTime <= nowWallTime()) {
        info.revert();
        toast.error('Pick a time in the future.');

        return;
    }

    router.patch(
        reschedulePost(Number(info.event.id)).url,
        { scheduled_at: `${newTime}:00` },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                info.revert();
                toast.error(errors.scheduled_at ?? 'Could not move the post.');
            },
        },
    );
}

const options = computed<CalendarOptions>(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: 'timeGridWeek',
    timeZone: 'UTC',
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay',
    },
    buttonText: { today: 'Today', month: 'Month', week: 'Week', day: 'Day' },
    firstDay: 1,
    nowIndicator: true,
    now: () => `${nowWallTime()}:00`,
    height: 'auto',
    slotDuration: '00:30:00',
    scrollTime: '08:00:00',
    eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
    slotLabelFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
    dayMaxEvents: 4,
    editable: true,
    eventDurationEditable: false,
    defaultTimedEventDuration: '00:30',
    events: loadEvents,
    dateClick: onDateClick,
    eventClick: onEventClick,
    eventDrop: onEventDrop,
}));
</script>

<template>
    <Head title="Calendar" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <Heading
                title="Calendar"
                description="Click an empty time to start a post there. Drag a draft or scheduled post to move it."
            />
            <span
                class="text-xs text-muted-foreground"
                data-test="calendar-timezone"
                >Times in {{ timezone }}</span
            >
        </div>

        <div class="flex flex-wrap gap-3 text-xs text-muted-foreground">
            <span class="flex items-center gap-1"
                ><span class="legend legend--scheduled" />Scheduled</span
            >
            <span class="flex items-center gap-1"
                ><span class="legend legend--draft" />Draft</span
            >
            <span class="flex items-center gap-1"
                ><span class="legend legend--publishing" />Publishing</span
            >
            <span class="flex items-center gap-1"
                ><span class="legend legend--published" />Published</span
            >
            <span class="flex items-center gap-1"
                ><span class="legend legend--failed" />Failed</span
            >
        </div>

        <div
            class="postloom-calendar rounded-xl border p-3"
            data-test="calendar"
        >
            <FullCalendar ref="calendar" :options="options">
                <template #eventContent="arg">
                    <PostEventContent :arg="arg" />
                </template>
            </FullCalendar>
        </div>
    </div>
</template>

<style>
.postloom-calendar {
    --fc-border-color: var(--color-border);
    --fc-page-bg-color: transparent;
    --fc-neutral-bg-color: var(--color-muted);
    --fc-today-bg-color: color-mix(
        in oklab,
        var(--color-primary) 8%,
        transparent
    );
    --fc-now-indicator-color: #ef4444;
    --fc-button-bg-color: transparent;
    --fc-button-border-color: var(--color-border);
    --fc-button-text-color: var(--color-foreground);
    --fc-button-hover-bg-color: var(--color-accent);
    --fc-button-hover-border-color: var(--color-border);
    --fc-button-active-bg-color: var(--color-primary);
    --fc-button-active-border-color: var(--color-primary);
    font-size: 0.85rem;
}

.postloom-calendar .fc-button-active {
    color: var(--color-primary-foreground) !important;
}

.postloom-calendar .fc-toolbar-title {
    font-size: 1.05rem;
    font-weight: 600;
}

.postloom-event {
    border-radius: 6px;
    border-width: 1px;
    cursor: pointer;
    overflow: hidden;
}

.postloom-event--scheduled {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
}

.postloom-event--draft {
    background: color-mix(in oklab, var(--color-muted) 80%, transparent);
    border: 1px dashed var(--color-muted-foreground);
    color: var(--color-foreground);
}

.postloom-event--publishing {
    background: #d97706;
    border-color: #d97706;
    color: #fff;
}

.postloom-event--published {
    background: #16a34a;
    border-color: #16a34a;
    color: #fff;
}

.postloom-event--failed {
    background: #dc2626;
    border-color: #dc2626;
    color: #fff;
}

.postloom-event .fc-event-main {
    color: inherit;
}

.legend {
    display: inline-block;
    width: 0.75rem;
    height: 0.75rem;
    border-radius: 3px;
}

.legend--scheduled {
    background: #2563eb;
}

.legend--draft {
    border: 1px dashed currentColor;
}

.legend--publishing {
    background: #d97706;
}

.legend--published {
    background: #16a34a;
}

.legend--failed {
    background: #dc2626;
}
</style>
