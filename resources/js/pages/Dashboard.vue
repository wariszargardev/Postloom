<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    Plus,
    XCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import PlatformIcon from '@/components/PlatformIcon.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as accountsIndex } from '@/routes/accounts';
import { index as calendarIndex } from '@/routes/calendar';
import {
    create as createPost,
    edit as editPost,
    index as postsIndex,
} from '@/routes/posts';

type UpNextRow = {
    id: number;
    title: string;
    status: string;
    status_label: string;
    scheduled_at: string | null;
    timezone: string;
    providers: string[];
};

const props = defineProps<{
    stats: {
        scheduled_this_week: number;
        published_last_30_days: number;
        failed: number;
        accounts_needing_attention: number;
    };
    upNext: UpNextRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const cards = computed(() => [
    {
        label: 'Scheduled this week',
        value: props.stats.scheduled_this_week,
        icon: CalendarClock,
        tone: 'text-status-scheduled',
        href: postsIndex({ query: { status: 'scheduled' } }).url,
    },
    {
        label: 'Published, last 30 days',
        value: props.stats.published_last_30_days,
        icon: CheckCircle2,
        tone: 'text-status-published',
        href: postsIndex({ query: { status: 'published' } }).url,
    },
    {
        label: 'Failed',
        value: props.stats.failed,
        icon: XCircle,
        tone: 'text-status-failed',
        href: postsIndex({ query: { status: 'failed' } }).url,
    },
    {
        label: 'Accounts needing attention',
        value: props.stats.accounts_needing_attention,
        icon: AlertTriangle,
        tone: 'text-status-partly',
        href: accountsIndex().url,
    },
]);

function formatWhen(row: UpNextRow): string {
    if (!row.scheduled_at) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: row.timezone,
    }).format(new Date(row.scheduled_at));
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="page">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Dashboard"
                description="What's going out next, and anything that needs a look."
            />
            <Button as-child>
                <Link :href="createPost()"
                    ><Plus class="size-4" /> New post</Link
                >
            </Button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Link
                v-for="card in cards"
                :key="card.label"
                :href="card.href"
                class="surface flex flex-col gap-3 p-5 transition-colors hover:border-brand/40"
                :data-test="`stat-${card.label}`"
            >
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted-foreground">{{
                        card.label
                    }}</span>
                    <component
                        :is="card.icon"
                        class="size-5"
                        :class="card.tone"
                    />
                </div>
                <span
                    class="font-mono text-4xl font-bold tracking-tight"
                    :class="card.value > 0 ? card.tone : ''"
                    >{{ card.value }}</span
                >
            </Link>
        </div>

        <section class="surface flex flex-col gap-4 p-5">
            <div class="flex items-center justify-between">
                <h2 class="section-title">
                    <span class="step-number">›</span> Up next
                </h2>
                <Link
                    :href="calendarIndex()"
                    class="text-sm font-medium text-brand hover:underline"
                    >Open calendar</Link
                >
            </div>

            <p v-if="upNext.length === 0" class="empty-state">
                Nothing scheduled.
                <Link :href="createPost()" class="text-brand underline"
                    >Write a post</Link
                >
                and schedule it.
            </p>

            <ul v-else class="flex flex-col divide-y">
                <li
                    v-for="row in upNext"
                    :key="row.id"
                    class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                >
                    <span
                        class="w-48 shrink-0 font-mono text-sm whitespace-nowrap text-muted-foreground"
                        >{{ formatWhen(row) }}</span
                    >
                    <Link
                        :href="editPost(row.id)"
                        class="min-w-0 flex-1 truncate font-medium hover:text-brand"
                        >{{ row.title }}</Link
                    >
                    <span class="flex gap-1">
                        <PlatformIcon
                            v-for="provider in row.providers"
                            :key="provider"
                            :provider="provider"
                            size="md"
                        />
                    </span>
                    <StatusBadge
                        :status="row.status"
                        :label="row.status_label"
                    />
                </li>
            </ul>
        </section>
    </div>
</template>
