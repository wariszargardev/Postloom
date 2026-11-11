<script setup lang="ts">
import { computed } from 'vue';

/**
 * Small brand mark for a social platform, used on account chips, the posts list and the calendar.
 */
const props = withDefaults(
    defineProps<{
        provider: string;
        size?: 'sm' | 'md';
    }>(),
    { size: 'sm' },
);

const brand: Record<string, { color: string; letter: string }> = {
    linkedin: { color: '#0a66c2', letter: 'in' },
    youtube: { color: '#ff0033', letter: '▶' },
    facebook: { color: '#1877f2', letter: 'f' },
    instagram: { color: '#e1306c', letter: 'ig' },
    tiktok: { color: '#111111', letter: 'tt' },
    whatsapp: { color: '#25d366', letter: 'wa' },
};

const mark = computed(
    () => brand[props.provider] ?? { color: '#64748b', letter: '?' },
);
const boxClass = computed(() =>
    props.size === 'md' ? 'size-6 rounded-md' : 'size-4 rounded-[4px]',
);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center justify-center text-white"
        :class="boxClass"
        :style="{ backgroundColor: mark.color }"
        :title="provider"
        :data-provider="provider"
    >
        <svg
            v-if="provider === 'linkedin'"
            viewBox="0 0 24 24"
            class="size-[70%]"
            fill="currentColor"
            aria-hidden="true"
        >
            <path
                d="M4.98 3.5A2.5 2.5 0 1 1 5 8.5a2.5 2.5 0 0 1-.02-5ZM3 9.75h4v11H3v-11Zm6.5 0h3.8v1.5h.06c.53-1 1.83-2.06 3.77-2.06 4.03 0 4.77 2.65 4.77 6.1v5.46h-4v-4.84c0-1.16-.02-2.64-1.61-2.64-1.61 0-1.86 1.26-1.86 2.56v4.92h-4v-11Z"
            />
        </svg>
        <svg
            v-else-if="provider === 'youtube'"
            viewBox="0 0 24 24"
            class="size-[62%]"
            fill="currentColor"
            aria-hidden="true"
        >
            <path d="M8 5.5v13l11-6.5-11-6.5Z" />
        </svg>
        <span v-else class="text-[0.55rem] leading-none font-bold">{{
            mark.letter
        }}</span>
    </span>
</template>
