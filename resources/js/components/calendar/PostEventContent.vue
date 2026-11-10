<script setup lang="ts">
import type { EventContentArg } from '@fullcalendar/core';
import { computed } from 'vue';

const props = defineProps<{
    arg: EventContentArg;
}>();

type EventAccount = { name: string; avatar_url: string | null };

const accounts = computed(
    () => (props.arg.event.extendedProps.accounts ?? []) as EventAccount[],
);
const isCompact = computed(() => props.arg.view.type === 'dayGridMonth');
</script>

<template>
    <div
        class="flex min-w-0 items-center gap-1 px-1 py-0.5 text-xs leading-tight"
        :class="isCompact ? '' : 'flex-col items-start'"
        :title="`${arg.event.extendedProps.status_label}: ${arg.event.title}`"
    >
        <span class="shrink-0 font-semibold">{{ arg.timeText }}</span>
        <span class="min-w-0 flex-1 truncate">{{ arg.event.title }}</span>
        <span v-if="!isCompact && accounts.length > 0" class="flex -space-x-1">
            <template
                v-for="account in accounts.slice(0, 4)"
                :key="account.name"
            >
                <img
                    v-if="account.avatar_url"
                    :src="account.avatar_url"
                    :alt="account.name"
                    class="size-4 rounded-full border border-white/70 object-cover"
                />
                <span
                    v-else
                    class="flex size-4 items-center justify-center rounded-full border border-white/70 bg-black/30 text-[8px]"
                    >{{ account.name.slice(0, 1) }}</span
                >
            </template>
        </span>
    </div>
</template>
