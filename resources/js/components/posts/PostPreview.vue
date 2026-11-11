<script setup lang="ts">
import { FileText, Globe, Music, Play, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { EditorAccount, LibraryMedia, SettingValue } from '@/types/posts';

const SEE_MORE_AFTER = 210;

const props = defineProps<{
    account: EditorAccount;
    text: string;
    media: LibraryMedia[];
    settings: Record<string, SettingValue>;
    library: LibraryMedia[];
}>();

const isExpanded = ref(false);

const visibility = computed(() => String(props.settings.visibility ?? ''));
const isYouTube = computed(() => props.account.provider === 'youtube');
const isTikTok = computed(() => props.account.provider === 'tiktok');
const tikTokBadge = computed(() => {
    if (props.settings.mode === 'inbox') {
        return 'Inbox draft';
    }

    if (props.settings.mode !== 'direct') {
        return 'You post it';
    }

    return (
        props.account.rules?.settings.privacy?.options?.[
            String(props.settings.privacy ?? 'SELF_ONLY')
        ] ?? 'Only me'
    );
});
const youTubeTitle = computed(
    () => String(props.settings.title ?? '').trim() || 'Untitled video',
);
const youTubeThumbnail = computed(() => {
    const chosen = props.library.find(
        (item) => item.id === Number(props.settings.thumbnail_media_id),
    );

    return chosen?.thumbnail_url ?? firstOther.value?.thumbnail_url ?? null;
});
const privacyLabel = computed(
    () =>
        props.account.rules?.settings.privacy?.options?.[
            String(props.settings.privacy ?? 'public')
        ] ?? 'Public',
);

const isLong = computed(() => props.text.length > SEE_MORE_AFTER);
const shownText = computed(() =>
    isLong.value && !isExpanded.value
        ? `${props.text.slice(0, SEE_MORE_AFTER).trimEnd()}…`
        : props.text,
);

const images = computed(() =>
    props.media.filter((item) => item.kind === 'image'),
);
const firstOther = computed(() =>
    props.media.find((item) => item.kind !== 'image'),
);

const initials = computed(() =>
    props.account.name
        .split(' ')
        .map((part) => part.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase(),
);
</script>

<template>
    <article
        v-if="isYouTube"
        class="overflow-hidden rounded-xl border bg-card text-card-foreground"
        :data-test="`preview-${account.id}`"
    >
        <div class="relative aspect-video bg-black">
            <img
                v-if="youTubeThumbnail"
                :src="youTubeThumbnail"
                alt=""
                class="h-full w-full object-cover"
            />
            <div
                v-else
                class="flex h-full items-center justify-center text-xs text-white/70"
            >
                {{ firstOther ? 'YouTube picks a frame' : 'Add a video' }}
            </div>
            <span
                v-if="settings.format === 'short'"
                class="absolute top-2 left-2 rounded bg-red-600 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                >SHORT</span
            >
        </div>
        <div class="flex gap-3 p-3">
            <Avatar class="size-9">
                <AvatarImage
                    v-if="account.avatar_url"
                    :src="account.avatar_url"
                    :alt="account.name"
                />
                <AvatarFallback>{{ initials }}</AvatarFallback>
            </Avatar>
            <div class="flex min-w-0 flex-col">
                <span class="line-clamp-2 text-sm font-semibold">{{
                    youTubeTitle
                }}</span>
                <span class="text-xs text-muted-foreground"
                    >{{ account.name }} · {{ privacyLabel }}</span
                >
                <span
                    v-if="text"
                    class="mt-1 line-clamp-2 text-xs text-muted-foreground"
                    >{{ text }}</span
                >
            </div>
        </div>
    </article>
    <article
        v-else-if="isTikTok"
        class="relative mx-auto aspect-[9/16] w-full max-w-[220px] overflow-hidden rounded-xl border bg-black text-white"
        :data-test="`preview-${account.id}`"
    >
        <img
            v-if="firstOther?.thumbnail_url"
            :src="firstOther.thumbnail_url"
            alt=""
            class="absolute inset-0 h-full w-full object-cover opacity-90"
        />
        <div
            v-else
            class="absolute inset-0 flex items-center justify-center text-xs text-white/70"
        >
            {{ firstOther ? 'Video preview' : 'Add a video' }}
        </div>
        <span
            class="absolute top-2 left-2 rounded bg-black/60 px-1.5 py-0.5 text-[10px] font-semibold"
            >{{ tikTokBadge }}</span
        >
        <div
            v-if="firstOther"
            class="absolute inset-0 flex items-center justify-center"
        >
            <span
                class="flex size-12 items-center justify-center rounded-full bg-black/50"
            >
                <Play class="size-5" />
            </span>
        </div>
        <div
            class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-3"
        >
            <span class="text-sm font-semibold">{{ account.name }}</span>
            <p
                v-if="text && settings.mode !== 'inbox'"
                class="mt-1 line-clamp-3 text-xs whitespace-pre-wrap"
            >
                {{ text }}
            </p>
            <p v-else-if="text" class="mt-1 text-[11px] text-white/70">
                Caption is pasted in the TikTok app
            </p>
        </div>
    </article>
    <article
        v-else
        class="overflow-hidden rounded-xl border bg-card text-card-foreground"
        :data-test="`preview-${account.id}`"
    >
        <header class="flex items-center gap-3 p-4 pb-2">
            <Avatar class="size-11">
                <AvatarImage
                    v-if="account.avatar_url"
                    :src="account.avatar_url"
                    :alt="account.name"
                />
                <AvatarFallback>{{ initials }}</AvatarFallback>
            </Avatar>
            <div class="flex flex-col">
                <span class="text-sm font-semibold">{{ account.name }}</span>
                <span
                    class="flex items-center gap-1 text-xs text-muted-foreground"
                >
                    {{ account.provider_label }} ·
                    {{ settings.format === 'reel' ? 'Reel' : 'Now' }} ·
                    <Users v-if="visibility === 'CONNECTIONS'" class="size-3" />
                    <Globe v-else class="size-3" />
                </span>
            </div>
        </header>

        <div v-if="text" class="px-4 pb-3 text-sm whitespace-pre-wrap">
            {{ shownText }}
            <button
                v-if="isLong"
                type="button"
                class="font-medium text-muted-foreground hover:underline"
                @click="isExpanded = !isExpanded"
            >
                {{ isExpanded ? 'show less' : 'see more' }}
            </button>
        </div>

        <div
            v-if="images.length > 0 && !firstOther"
            class="grid gap-0.5"
            :class="images.length === 1 ? 'grid-cols-1' : 'grid-cols-2'"
        >
            <div
                v-for="(image, index) in images.slice(0, 4)"
                :key="image.id"
                class="relative aspect-square bg-muted"
            >
                <img
                    v-if="image.thumbnail_url"
                    :src="image.thumbnail_url"
                    alt=""
                    class="h-full w-full object-cover"
                />
                <div
                    v-if="index === 3 && images.length > 4"
                    class="absolute inset-0 flex items-center justify-center bg-black/60 text-2xl font-semibold text-white"
                >
                    +{{ images.length - 4 }}
                </div>
            </div>
        </div>

        <div
            v-else-if="firstOther?.kind === 'video'"
            class="relative aspect-video bg-black"
        >
            <img
                v-if="firstOther.thumbnail_url"
                :src="firstOther.thumbnail_url"
                alt=""
                class="h-full w-full object-cover opacity-90"
            />
            <div class="absolute inset-0 flex items-center justify-center">
                <span
                    class="flex size-14 items-center justify-center rounded-full bg-black/60 text-white"
                >
                    <Play class="size-6" />
                </span>
            </div>
        </div>

        <div
            v-else-if="firstOther"
            class="mx-4 mb-4 flex items-center gap-3 rounded-lg border bg-muted/40 p-3 text-sm"
        >
            <Music v-if="firstOther.kind === 'audio'" class="size-5" />
            <FileText v-else class="size-5" />
            <div class="flex flex-col">
                <span class="truncate font-medium">{{
                    firstOther.original_name
                }}</span>
                <span class="text-xs text-muted-foreground">
                    {{
                        firstOther.kind === 'audio'
                            ? 'Posted as a video with a still image'
                            : 'Shown as a document carousel'
                    }}
                </span>
            </div>
        </div>

        <footer
            class="flex justify-around border-t px-4 py-2 text-xs text-muted-foreground"
        >
            <template v-if="account.provider === 'facebook'">
                <span>Like</span><span>Comment</span><span>Share</span>
            </template>
            <template v-else>
                <span>Like</span><span>Comment</span><span>Repost</span
                ><span>Send</span>
            </template>
        </footer>
    </article>
</template>
