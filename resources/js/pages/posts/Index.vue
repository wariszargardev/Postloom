<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Clock,
    Copy,
    FileText,
    Image,
    Plus,
    Trash2,
    Upload,
} from '@lucide/vue';
import PostController from '@/actions/App/Http/Controllers/PostController';
import { create as importPosts } from '@/actions/App/Http/Controllers/PostImportController';
import Heading from '@/components/Heading.vue';
import PlatformIcon from '@/components/PlatformIcon.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    create as createPost,
    edit as editPost,
    index as postsIndex,
} from '@/routes/posts';

type PostRow = {
    id: number;
    title: string | null;
    excerpt: string;
    status: string;
    status_label: string;
    scheduled_at: string | null;
    timezone: string;
    media_count: number;
    thumbnail_url: string | null;
    accounts: {
        id: number;
        name: string;
        provider: string;
        avatar_url: string | null;
    }[];
    is_editable: boolean;
    is_deletable: boolean;
};

type PaginationLink = { url: string | null; label: string; active: boolean };

defineProps<{
    posts: { data: PostRow[]; links: PaginationLink[] };
    filters: { status: string | null };
    statuses: { value: string; label: string; count: number }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Posts', href: postsIndex() }],
    },
});

function formatWhen(post: PostRow): string {
    if (!post.scheduled_at) {
        return 'No time set';
    }

    return new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: post.timezone,
    }).format(new Date(post.scheduled_at));
}

function statusHref(status: string | null): string {
    return postsIndex({ query: status ? { status } : {} }).url;
}

function duplicate(post: PostRow): void {
    router.post(PostController.duplicate(post.id).url);
}

function destroy(post: PostRow): void {
    if (confirm('Delete this post?')) {
        router.delete(PostController.destroy(post.id).url, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Posts" />

    <div class="page">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Posts"
                description="Drafts, scheduled and published posts in this workspace."
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="importPosts()" data-test="import-csv"
                        ><Upload class="size-4" /> Import CSV</Link
                    >
                </Button>
                <Button as-child>
                    <Link :href="createPost()" data-test="new-post"
                        ><Plus class="size-4" /> New post</Link
                    >
                </Button>
            </div>
        </div>

        <nav class="flex flex-wrap gap-2" aria-label="Filter by status">
            <Link
                :href="statusHref(null)"
                preserve-scroll
                class="filter-chip"
                :data-active="filters.status === null"
                >All</Link
            >
            <Link
                v-for="status in statuses.filter(
                    (item) =>
                        item.count > 0 ||
                        ['draft', 'scheduled'].includes(item.value),
                )"
                :key="status.value"
                :href="statusHref(status.value)"
                preserve-scroll
                class="filter-chip"
                :data-active="filters.status === status.value"
                >{{ status.label }}
                <span class="font-mono text-xs opacity-70">{{
                    status.count
                }}</span></Link
            >
        </nav>

        <p v-if="posts.data.length === 0" class="empty-state">
            No posts here yet. Click <strong>New post</strong> to write one.
        </p>

        <ul class="flex flex-col gap-3">
            <li
                v-for="post in posts.data"
                :key="post.id"
                class="surface flex flex-col gap-4 p-4 transition-colors hover:border-brand/40 sm:flex-row sm:items-center"
                :data-test="`post-${post.id}`"
            >
                <Link
                    :href="editPost(post.id)"
                    class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted"
                    tabindex="-1"
                >
                    <img
                        v-if="post.thumbnail_url"
                        :src="post.thumbnail_url"
                        alt=""
                        class="h-full w-full object-cover"
                    />
                    <FileText v-else class="size-6 text-muted-foreground" />
                </Link>

                <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            :status="post.status"
                            :label="post.status_label"
                        />
                        <span
                            class="flex items-center gap-1 font-mono text-xs text-muted-foreground"
                        >
                            <Clock class="size-3.5" />
                            {{ formatWhen(post) }}
                        </span>
                        <span
                            v-if="post.media_count > 0"
                            class="flex items-center gap-1 font-mono text-xs text-muted-foreground"
                        >
                            <Image class="size-3.5" /> {{ post.media_count }}
                        </span>
                    </div>
                    <Link
                        :href="editPost(post.id)"
                        class="line-clamp-1 font-semibold hover:text-brand"
                        >{{ post.title || post.excerpt || '(no text)' }}</Link
                    >
                    <p
                        v-if="post.title"
                        class="line-clamp-1 text-sm text-muted-foreground"
                    >
                        {{ post.excerpt }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2 pt-0.5">
                        <span
                            v-for="account in post.accounts"
                            :key="account.id"
                            class="inline-flex items-center gap-1.5 rounded-full border bg-background/60 py-0.5 pr-2.5 pl-1 text-xs"
                        >
                            <PlatformIcon :provider="account.provider" />
                            {{ account.name }}
                        </span>
                    </div>
                </div>

                <div class="flex shrink-0 gap-1">
                    <Button size="sm" variant="outline" as-child>
                        <Link :href="editPost(post.id)">{{
                            post.is_editable ? 'Edit' : 'View'
                        }}</Link>
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        title="Duplicate"
                        @click="duplicate(post)"
                        ><Copy class="size-4" />
                        <span class="hidden lg:inline">Duplicate</span></Button
                    >
                    <Button
                        v-if="post.is_deletable"
                        size="sm"
                        variant="ghost"
                        class="text-destructive hover:text-destructive"
                        title="Delete"
                        @click="destroy(post)"
                        ><Trash2 class="size-4" />
                        <span class="hidden lg:inline">Delete</span></Button
                    >
                </div>
            </li>
        </ul>

        <nav v-if="posts.links.length > 3" class="flex flex-wrap gap-1">
            <template v-for="link in posts.links" :key="link.label">
                <Button
                    v-if="link.url"
                    :variant="link.active ? 'default' : 'outline'"
                    size="sm"
                    as-child
                >
                    <Link :href="link.url" preserve-scroll
                        ><span v-html="link.label"
                    /></Link>
                </Button>
            </template>
        </nav>
    </div>
</template>
