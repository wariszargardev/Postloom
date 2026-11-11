<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, Copy, Download, ExternalLink, Video } from '@lucide/vue';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PlatformIcon from '@/components/PlatformIcon.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type HandoffItem = {
    id: number;
    post_id: number;
    account: {
        name: string;
        handle: string | null;
        provider: string;
        provider_label: string;
        avatar_url: string | null;
    };
    caption: string;
    scheduled_at: string | null;
    timezone: string;
    video: {
        name: string;
        size_bytes: number;
        thumbnail_url: string | null;
        download_url: string;
    } | null;
};

const props = defineProps<{
    items: HandoffItem[];
    uploadUrl: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'To post', href: '/to-post' }],
    },
});

const copiedId = reactive<Record<number, boolean>>({});
const forms = reactive<
    Record<number, ReturnType<typeof useForm<{ url: string }>>>
>(
    Object.fromEntries(
        props.items.map((item) => [item.id, useForm({ url: '' })]),
    ),
);

async function copyCaption(item: HandoffItem): Promise<void> {
    await navigator.clipboard.writeText(item.caption);
    copiedId[item.id] = true;
    setTimeout(() => (copiedId[item.id] = false), 2000);
}

function markPosted(item: HandoffItem): void {
    forms[item.id].post(`/to-post/${item.id}/posted`, { preserveScroll: true });
}

function formatTime(item: HandoffItem): string {
    if (item.scheduled_at === null) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: item.timezone,
    }).format(new Date(item.scheduled_at));
}

function formatSize(bytes: number): string {
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
</script>

<template>
    <Head title="To post" />

    <div
        class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-6 md:px-8 md:py-8"
    >
        <Heading
            title="To post"
            description="Posts you chose to upload yourself. Download the video, copy the caption, upload it in TikTok Studio, then mark it as posted."
        />

        <p
            v-if="items.length === 0"
            class="surface p-6 text-sm text-muted-foreground"
            data-test="to-post-empty"
        >
            Nothing is waiting. When a TikTok post set to "I'll post it myself"
            reaches its time, it shows up here.
        </p>

        <article
            v-for="item in items"
            :key="item.id"
            class="surface flex flex-col gap-4 p-4 md:flex-row"
            :data-test="`to-post-${item.id}`"
        >
            <div
                class="relative aspect-[9/16] w-32 shrink-0 self-start overflow-hidden rounded-lg bg-black"
            >
                <img
                    v-if="item.video?.thumbnail_url"
                    :src="item.video.thumbnail_url"
                    alt=""
                    class="h-full w-full object-cover"
                />
                <div
                    v-else
                    class="flex h-full items-center justify-center text-white/60"
                >
                    <Video class="size-6" />
                </div>
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-3">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <PlatformIcon :provider="item.account.provider" />
                    <span class="font-semibold">{{ item.account.name }}</span>
                    <span
                        v-if="item.account.handle"
                        class="text-muted-foreground"
                        >@{{ item.account.handle }}</span
                    >
                    <span class="text-muted-foreground"
                        >· due {{ formatTime(item) }}</span
                    >
                </div>

                <p
                    class="max-h-32 overflow-y-auto rounded-md border bg-background/40 p-2 text-sm whitespace-pre-wrap"
                >
                    {{ item.caption || '(no caption)' }}
                </p>

                <div class="flex flex-wrap gap-2">
                    <Button v-if="item.video" as-child size="sm">
                        <a
                            :href="item.video.download_url"
                            data-test="download-video"
                        >
                            <Download class="size-4" /> Download video ({{
                                formatSize(item.video.size_bytes)
                            }})
                        </a>
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        data-test="copy-caption"
                        @click="copyCaption(item)"
                    >
                        <Check v-if="copiedId[item.id]" class="size-4" />
                        <Copy v-else class="size-4" />
                        {{ copiedId[item.id] ? 'Copied' : 'Copy caption' }}
                    </Button>
                    <Button as-child size="sm" variant="outline">
                        <a :href="uploadUrl" target="_blank" rel="noopener">
                            <ExternalLink class="size-4" /> Open TikTok Studio
                        </a>
                    </Button>
                </div>

                <form
                    class="flex flex-col gap-2 sm:flex-row sm:items-start"
                    @submit.prevent="markPosted(item)"
                >
                    <div class="flex flex-1 flex-col gap-1">
                        <Input
                            v-model="forms[item.id].url"
                            type="url"
                            placeholder="Paste the TikTok link (optional)"
                            :name="`url-${item.id}`"
                        />
                        <InputError :message="forms[item.id].errors.url" />
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="forms[item.id].processing"
                        data-test="mark-posted"
                    >
                        <Check class="size-4" /> Mark as posted
                    </Button>
                </form>
            </div>
        </article>
    </div>
</template>
