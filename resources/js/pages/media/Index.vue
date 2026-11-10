<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { FileText, Music, UploadCloud, Video } from '@lucide/vue';
import { computed, ref } from 'vue';
import MediaController from '@/actions/App/Http/Controllers/MediaController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as mediaIndex } from '@/routes/media';

type MediaItem = {
    id: number;
    kind: 'image' | 'video' | 'audio' | 'document';
    original_name: string;
    mime_type: string;
    size_bytes: number;
    width: number | null;
    height: number | null;
    duration_seconds: number | null;
    alt_text: string | null;
    url: string;
    thumbnail_url: string | null;
    created_at: string | null;
};

type KindOption = {
    value: MediaItem['kind'];
    label: string;
    count: number;
    extensions: string[];
    max_mb: number;
};

type PaginationLink = { url: string | null; label: string; active: boolean };

const props = defineProps<{
    media: { data: MediaItem[]; links: PaginationLink[]; total: number };
    filters: { kind: MediaItem['kind'] | null };
    kinds: KindOption[];
    maxFilesPerUpload: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Media', href: mediaIndex() }],
    },
});

const fileInput = ref<HTMLInputElement | null>(null);
const isDragging = ref(false);
const editingAltId = ref<number | null>(null);
const altDraft = ref('');

const uploadForm = useForm<{ files: File[] }>({ files: [] });

const acceptAttribute = computed(() =>
    props.kinds
        .flatMap((kind) => kind.extensions.map((extension) => `.${extension}`))
        .join(','),
);

const totalCount = computed(() =>
    props.kinds.reduce((sum, kind) => sum + kind.count, 0),
);

const uploadErrors = computed(() =>
    Object.entries(uploadForm.errors)
        .filter(([key]) => key === 'files' || key.startsWith('files.'))
        .map(([, message]) => message),
);

function upload(files: FileList | File[] | null): void {
    if (!files || files.length === 0) {
        return;
    }

    uploadForm.files = Array.from(files);
    uploadForm.post(MediaController.store().url, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            uploadForm.files = [];

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function onDrop(event: DragEvent): void {
    isDragging.value = false;
    upload(event.dataTransfer?.files ?? null);
}

function startEditingAlt(item: MediaItem): void {
    editingAltId.value = item.id;
    altDraft.value = item.alt_text ?? '';
}

function saveAlt(item: MediaItem): void {
    router.patch(
        MediaController.update(item.id).url,
        { alt_text: altDraft.value === '' ? null : altDraft.value },
        { preserveScroll: true, onSuccess: () => (editingAltId.value = null) },
    );
}

function destroy(item: MediaItem): void {
    if (
        !confirm(
            `Delete "${item.original_name}"? The file is removed from this PC.`,
        )
    ) {
        return;
    }

    router.delete(MediaController.destroy(item.id).url, {
        preserveScroll: true,
    });
}

function formatSize(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function formatDuration(seconds: number | null): string | null {
    if (seconds === null) {
        return null;
    }

    const minutes = Math.floor(seconds / 60);
    const rest = Math.round(seconds % 60)
        .toString()
        .padStart(2, '0');

    return `${minutes}:${rest}`;
}

function kindHref(kind: MediaItem['kind'] | null): string {
    return mediaIndex({ query: kind ? { kind } : {} }).url;
}
</script>

<template>
    <Head title="Media" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Media"
            description="Images, videos, audio and PDFs for this workspace. Upload once, use them in any post."
        />

        <div
            class="flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed p-8 text-center transition-colors"
            :class="
                isDragging ? 'border-primary bg-primary/5' : 'border-border'
            "
            data-test="media-dropzone"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="onDrop"
        >
            <UploadCloud class="size-8 text-muted-foreground" />
            <p class="text-sm">
                Drag files here, or
                <button
                    type="button"
                    class="font-medium underline underline-offset-4"
                    @click="fileInput?.click()"
                >
                    choose files
                </button>
            </p>
            <p class="text-xs text-muted-foreground">
                Up to {{ maxFilesPerUpload }} files at a time.
                <template v-for="(kind, index) in kinds" :key="kind.value">
                    {{ kind.label }}: {{ kind.extensions.join(', ') }} up to
                    {{ kind.max_mb }} MB<template
                        v-if="index < kinds.length - 1"
                        >·
                    </template>
                </template>
            </p>
            <input
                ref="fileInput"
                type="file"
                multiple
                class="hidden"
                :accept="acceptAttribute"
                data-test="media-file-input"
                @change="upload(($event.target as HTMLInputElement).files)"
            />

            <div v-if="uploadForm.processing" class="w-full max-w-sm">
                <div class="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full bg-primary transition-all"
                        :style="{
                            width: `${uploadForm.progress?.percentage ?? 0}%`,
                        }"
                    />
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    Uploading… {{ uploadForm.progress?.percentage ?? 0 }}%
                </p>
            </div>

            <div
                v-for="message in uploadErrors"
                :key="message"
                class="w-full max-w-xl"
            >
                <InputError :message="message" />
            </div>
        </div>

        <nav class="flex flex-wrap gap-2">
            <Button
                :variant="filters.kind === null ? 'default' : 'outline'"
                size="sm"
                as-child
            >
                <Link :href="kindHref(null)" preserve-scroll
                    >All ({{ totalCount }})</Link
                >
            </Button>
            <Button
                v-for="kind in kinds"
                :key="kind.value"
                :variant="filters.kind === kind.value ? 'default' : 'outline'"
                size="sm"
                as-child
            >
                <Link :href="kindHref(kind.value)" preserve-scroll
                    >{{ kind.label }} ({{ kind.count }})</Link
                >
            </Button>
        </nav>

        <p
            v-if="media.data.length === 0"
            class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
        >
            No media here yet.
        </p>

        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <li
                v-for="item in media.data"
                :key="item.id"
                class="flex flex-col overflow-hidden rounded-xl border"
                :data-test="`media-${item.id}`"
            >
                <a
                    :href="item.url"
                    target="_blank"
                    rel="noopener"
                    class="block aspect-video bg-muted"
                >
                    <img
                        v-if="item.thumbnail_url"
                        :src="item.thumbnail_url"
                        :alt="item.alt_text ?? item.original_name"
                        class="h-full w-full object-cover"
                        loading="lazy"
                    />
                    <div
                        v-else
                        class="flex h-full items-center justify-center text-muted-foreground"
                    >
                        <Video v-if="item.kind === 'video'" class="size-10" />
                        <Music
                            v-else-if="item.kind === 'audio'"
                            class="size-10"
                        />
                        <FileText v-else class="size-10" />
                    </div>
                </a>

                <div class="flex flex-1 flex-col gap-2 p-3">
                    <div class="flex items-start justify-between gap-2">
                        <span
                            class="truncate text-sm font-medium"
                            :title="item.original_name"
                            >{{ item.original_name }}</span
                        >
                        <Badge variant="secondary">{{ item.kind }}</Badge>
                    </div>
                    <span class="text-xs text-muted-foreground">
                        {{ formatSize(item.size_bytes) }}
                        <template v-if="item.width && item.height"
                            >· {{ item.width }}×{{ item.height }}</template
                        >
                        <template v-if="formatDuration(item.duration_seconds)"
                            >·
                            {{
                                formatDuration(item.duration_seconds)
                            }}</template
                        >
                    </span>

                    <audio
                        v-if="item.kind === 'audio'"
                        :src="item.url"
                        controls
                        preload="none"
                        class="w-full"
                    />

                    <div
                        v-if="editingAltId === item.id"
                        class="flex flex-col gap-2"
                    >
                        <Input
                            v-model="altDraft"
                            maxlength="1000"
                            placeholder="Describe this for screen readers"
                        />
                        <div class="flex gap-2">
                            <Button size="sm" @click="saveAlt(item)"
                                >Save</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="editingAltId = null"
                                >Cancel</Button
                            >
                        </div>
                    </div>
                    <p
                        v-else-if="item.alt_text"
                        class="line-clamp-2 text-xs text-muted-foreground"
                    >
                        Alt: {{ item.alt_text }}
                    </p>

                    <div class="mt-auto flex gap-2 pt-1">
                        <Button
                            v-if="
                                editingAltId !== item.id &&
                                item.kind !== 'audio'
                            "
                            size="sm"
                            variant="outline"
                            @click="startEditingAlt(item)"
                        >
                            {{
                                item.alt_text ? 'Edit alt text' : 'Add alt text'
                            }}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            class="text-destructive"
                            @click="destroy(item)"
                            >Delete</Button
                        >
                    </div>
                </div>
            </li>
        </ul>

        <nav v-if="media.links.length > 3" class="flex flex-wrap gap-1">
            <template v-for="link in media.links" :key="link.label">
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
