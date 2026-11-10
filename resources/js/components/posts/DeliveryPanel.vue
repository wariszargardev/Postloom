<script setup lang="ts">
import { ExternalLink } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import type { EditorAccount, TargetDelivery } from '@/types/posts';

const props = defineProps<{
    rows: { account: EditorAccount; delivery: TargetDelivery }[];
    timezone: string;
}>();

const statusVariant: Record<
    TargetDelivery['status'],
    'outline' | 'secondary' | 'default' | 'destructive'
> = {
    pending: 'outline',
    publishing: 'secondary',
    published: 'default',
    failed: 'destructive',
};

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
        <h2 class="text-sm font-semibold">Delivery</h2>
        <div
            v-for="row in rows"
            :key="row.account.id"
            class="flex flex-col gap-2 rounded-lg border p-3"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-sm font-medium"
                    >{{ row.account.name }} ·
                    {{ row.account.provider_label }}</span
                >
                <div class="flex items-center gap-2">
                    <span
                        v-if="row.delivery.attempts > 0"
                        class="text-xs text-muted-foreground"
                    >
                        {{ row.delivery.attempts }}
                        {{ row.delivery.attempts === 1 ? 'try' : 'tries' }}
                    </span>
                    <Badge :variant="statusVariant[row.delivery.status]">{{
                        row.delivery.status_label
                    }}</Badge>
                </div>
            </div>

            <a
                v-if="row.delivery.external_url"
                :href="row.delivery.external_url"
                target="_blank"
                rel="noopener"
                class="flex items-center gap-1 text-sm underline underline-offset-4"
            >
                View post <ExternalLink class="size-3" />
            </a>
            <p v-if="row.delivery.last_error" class="text-sm text-destructive">
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
                    <span>{{ formatTime(attempt.started_at) }}</span>
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
