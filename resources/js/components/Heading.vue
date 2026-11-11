<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? '' : 'space-y-1'">
        <h2
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-semibold'
                    : 'text-2xl font-extrabold tracking-tight md:text-3xl'
            "
        >
            {{ title }}
        </h2>
        <p
            v-if="description"
            :class="
                variant === 'small'
                    ? 'text-sm text-muted-foreground'
                    : 'text-[0.95rem] text-muted-foreground'
            "
        >
            {{ description }}
        </p>
    </header>
</template>
