<script setup lang="ts">
import type { EventContentArg } from '@fullcalendar/core';
import { computed } from 'vue';
import PlatformIcon from '@/components/PlatformIcon.vue';

const props = defineProps<{
    arg: EventContentArg;
}>();

type EventAccount = {
    name: string;
    provider: string;
    avatar_url: string | null;
};

const accounts = computed(
    () => (props.arg.event.extendedProps.accounts ?? []) as EventAccount[],
);
const providers = computed(() => [
    ...new Set(accounts.value.map((account) => account.provider)),
]);
const isCompact = computed(() => props.arg.view.type === 'dayGridMonth');
</script>

<template>
    <div
        class="flex min-w-0 items-center gap-1 px-1.5 py-0.5 text-xs leading-tight"
        :class="isCompact ? '' : 'flex-col items-start gap-0.5 py-1'"
        :title="`${arg.event.extendedProps.status_label}: ${arg.event.title}`"
    >
        <span class="shrink-0 font-mono font-semibold">{{ arg.timeText }}</span>
        <span class="min-w-0 flex-1 truncate font-medium">{{
            arg.event.title
        }}</span>
        <span v-if="providers.length > 0" class="flex shrink-0 gap-0.5">
            <PlatformIcon
                v-for="provider in providers"
                :key="provider"
                :provider="provider"
            />
        </span>
    </div>
</template>
