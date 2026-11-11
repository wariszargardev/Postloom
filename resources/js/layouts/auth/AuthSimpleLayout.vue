<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="bg-grid flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <div class="w-full max-w-sm">
            <div class="surface flex flex-col gap-8 p-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-3"
                    >
                        <AppLogoIcon class="size-14" />
                        <span class="font-mono text-lg font-bold tracking-tight"
                            >Post<span class="text-brand">loom</span></span
                        >
                    </Link>
                    <div class="space-y-1.5 text-center">
                        <h1 class="text-xl font-bold tracking-tight">
                            {{ title }}
                        </h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
            <p class="mt-6 text-center font-mono text-xs text-muted-foreground">
                Write once, post everywhere.
            </p>
        </div>
    </div>
</template>
