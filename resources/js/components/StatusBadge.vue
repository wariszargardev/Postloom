<script setup lang="ts">
import { computed } from 'vue';

/**
 * One coloured pill for every status in the app (posts, accounts on a post, social accounts),
 * so "Published" or "Failed" always looks the same.
 */
const props = defineProps<{
    status: string;
    label: string;
}>();

const tones: Record<string, string> = {
    draft: 'text-status-draft bg-status-draft/10 ring-status-draft/25',
    pending: 'text-status-draft bg-status-draft/10 ring-status-draft/25',
    scheduled:
        'text-status-scheduled bg-status-scheduled/10 ring-status-scheduled/30',
    publishing:
        'text-status-publishing bg-status-publishing/10 ring-status-publishing/30',
    published:
        'text-status-published bg-status-published/10 ring-status-published/30',
    connected:
        'text-status-published bg-status-published/10 ring-status-published/30',
    partly_published:
        'text-status-partly bg-status-partly/10 ring-status-partly/30',
    expiring_soon:
        'text-status-scheduled bg-status-scheduled/10 ring-status-scheduled/30',
    failed: 'text-status-failed bg-status-failed/10 ring-status-failed/30',
    reconnect_needed:
        'text-status-failed bg-status-failed/10 ring-status-failed/30',
};

const tone = computed(() => tones[props.status] ?? tones.draft);
const isLive = computed(() => props.status === 'publishing');
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap ring-1 ring-inset"
        :class="tone"
        :data-status="status"
    >
        <span class="relative flex size-1.5">
            <span
                v-if="isLive"
                class="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-60"
            />
            <span
                class="relative inline-flex size-1.5 rounded-full bg-current"
            />
        </span>
        {{ label }}
    </span>
</template>
