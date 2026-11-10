<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Image, Plus } from '@lucide/vue';
import PostController from '@/actions/App/Http/Controllers/PostController';
import Heading from '@/components/Heading.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
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
    accounts: {
        id: number;
        name: string;
        provider: string;
        avatar_url: string | null;
    }[];
    is_editable: boolean;
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

const statusVariant: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    draft: 'outline',
    scheduled: 'default',
    publishing: 'secondary',
    published: 'secondary',
    partly_published: 'destructive',
    failed: 'destructive',
};

function formatWhen(post: PostRow): string {
    if (!post.scheduled_at) {
        return 'No time set';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
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

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <Heading
                title="Posts"
                description="Drafts, scheduled and published posts in this workspace."
            />
            <Button as-child>
                <Link :href="createPost()" data-test="new-post"
                    ><Plus class="size-4" /> New post</Link
                >
            </Button>
        </div>

        <nav class="flex flex-wrap gap-2">
            <Button
                :variant="filters.status === null ? 'default' : 'outline'"
                size="sm"
                as-child
            >
                <Link :href="statusHref(null)" preserve-scroll>All</Link>
            </Button>
            <Button
                v-for="status in statuses.filter(
                    (item) =>
                        item.count > 0 ||
                        ['draft', 'scheduled'].includes(item.value),
                )"
                :key="status.value"
                :variant="
                    filters.status === status.value ? 'default' : 'outline'
                "
                size="sm"
                as-child
            >
                <Link :href="statusHref(status.value)" preserve-scroll
                    >{{ status.label }} ({{ status.count }})</Link
                >
            </Button>
        </nav>

        <p
            v-if="posts.data.length === 0"
            class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
        >
            No posts here yet.
        </p>

        <ul class="flex flex-col gap-3">
            <li
                v-for="post in posts.data"
                :key="post.id"
                class="flex flex-col gap-3 rounded-xl border p-4 md:flex-row md:items-center md:justify-between"
                :data-test="`post-${post.id}`"
            >
                <div class="flex min-w-0 flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            :variant="statusVariant[post.status] ?? 'outline'"
                            >{{ post.status_label }}</Badge
                        >
                        <span class="text-sm text-muted-foreground">{{
                            formatWhen(post)
                        }}</span>
                        <span
                            v-if="post.media_count > 0"
                            class="flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <Image class="size-3" /> {{ post.media_count }}
                        </span>
                    </div>
                    <span v-if="post.title" class="font-medium">{{
                        post.title
                    }}</span>
                    <p class="line-clamp-2 text-sm text-muted-foreground">
                        {{ post.excerpt || '(no text)' }}
                    </p>
                    <div class="flex -space-x-2 pt-1">
                        <Avatar
                            v-for="account in post.accounts"
                            :key="account.id"
                            class="size-7 border-2 border-background"
                            :title="account.name"
                        >
                            <AvatarImage
                                v-if="account.avatar_url"
                                :src="account.avatar_url"
                                :alt="account.name"
                            />
                            <AvatarFallback class="text-[10px]">{{
                                account.name.slice(0, 2).toUpperCase()
                            }}</AvatarFallback>
                        </Avatar>
                    </div>
                </div>
                <div class="flex shrink-0 gap-2">
                    <Button size="sm" variant="outline" as-child>
                        <Link :href="editPost(post.id)">{{
                            post.is_editable ? 'Edit' : 'View'
                        }}</Link>
                    </Button>
                    <Button size="sm" variant="ghost" @click="duplicate(post)"
                        >Duplicate</Button
                    >
                    <Button
                        v-if="post.is_editable"
                        size="sm"
                        variant="ghost"
                        class="text-destructive"
                        @click="destroy(post)"
                        >Delete</Button
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
