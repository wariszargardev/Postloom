<script setup lang="ts">
import { ExternalLink } from '@lucide/vue';
import PlatformIcon from '@/components/PlatformIcon.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import type { EditorAccount, TargetDelivery } from '@/types/posts';

const props = defineProps<{
    rows: { account: EditorAccount; delivery: TargetDelivery }[];
    timezone: string;
}>();

function formatTime(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
        timeZone: props.timezone,
    }).format(new Date(value));
}
</script>

<template>
    <section class="flex flex-col gap-3" data-test="delivery-panel">
        <h2 class="section-title">Delivery</h2>
        <div
            v-for="row in rows"
            :key="row.account.id"
            class="surface flex flex-col gap-2 p-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="flex items-center gap-2 text-sm font-semibold"
                    ><PlatformIcon :provider="row.account.provider" />{{
                        row.account.name
                    }}</span
                >
                <div class="flex items-center gap-2">
                    <span
                        v-if="row.delivery.attempts > 0"
                        class="text-xs text-muted-foreground"
                    >
                        {{ row.delivery.attempts }}
                        {{ row.delivery.attempts === 1 ? 'try' : 'tries' }}
                    </span>
                    <StatusBadge
                        :status="row.delivery.status"
                        :label="row.delivery.status_label"
                    />
                </div>
            </div>

            <a
                v-if="row.delivery.external_url"
                :href="row.delivery.external_url"
                target="_blank"
                rel="noopener"
                class="flex w-fit items-center gap-1 text-sm font-medium text-brand hover:underline"
            >
                View post <ExternalLink class="size-3" />
            </a>
            <a
                v-if="row.delivery.status === 'ready_to_post'"
                href="/to-post"
                class="flex w-fit items-center gap-1 text-sm font-medium text-brand hover:underline"
                data-test="open-to-post"
            >
                Open To post to upload it
            </a>
            <p
                v-if="row.delivery.last_error"
                class="text-sm"
                :class="
                    row.delivery.status === 'failed'
                        ? 'text-destructive'
                        : 'text-muted-foreground'
                "
            >
                {{ row.delivery.last_error }}
            </p>

            <ul
                v-if="row.delivery.history.length > 0"
                class="flex flex-col gap-1 border-t pt-2 text-xs text-muted-foreground"
            >
                <li
                    v-for="attempt in row.delivery.history"
                    :key="`${attempt.attempt}-${attempt.started_at}`"
                    class="flex flex-wrap gap-x-2"
                >
                    <span class="font-medium">Try {{ attempt.attempt }}</span>
                    <span class="font-mono">{{
                        formatTime(attempt.started_at)
                    }}</span>
                    <span>{{ attempt.status }}</span>
                    <span v-if="attempt.duration_ms !== null"
                        >{{ (attempt.duration_ms / 1000).toFixed(1) }} s</span
                    >
                    <span
                        v-if="attempt.error_message"
                        class="w-full text-destructive"
                        >{{ attempt.error_message }}</span
                    >
                </li>
            </ul>
        </div>
    </section>
</template>
