<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Check,
    FileText,
    Music,
    Video,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { index as mediaIndex } from '@/routes/media';
import type { LibraryMedia } from '@/types/posts';

const props = defineProps<{
    library: LibraryMedia[];
    disabled?: boolean;
}>();

const selectedIds = defineModel<number[]>({ required: true });

const isOpen = ref(false);
const draftIds = ref<number[]>([]);

const selectedMedia = computed(() =>
    selectedIds.value
        .map((id) => props.library.find((item) => item.id === id))
        .filter((item): item is LibraryMedia => item !== undefined),
);

function openPicker(open: boolean): void {
    isOpen.value = open;

    if (open) {
        draftIds.value = [...selectedIds.value];
    }
}

function toggle(id: number): void {
    draftIds.value = draftIds.value.includes(id)
        ? draftIds.value.filter((selectedId) => selectedId !== id)
        : [...draftIds.value, id];
}

function confirmSelection(): void {
    selectedIds.value = [...draftIds.value];
    isOpen.value = false;
}

function move(index: number, direction: -1 | 1): void {
    const ids = [...selectedIds.value];
    const target = index + direction;

    if (target < 0 || target >= ids.length) {
        return;
    }

    [ids[index], ids[target]] = [ids[target], ids[index]];
    selectedIds.value = ids;
}

function remove(id: number): void {
    selectedIds.value = selectedIds.value.filter(
        (selectedId) => selectedId !== id,
    );
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <ul
            v-if="selectedMedia.length > 0"
            class="flex flex-col gap-2"
            data-test="selected-media"
        >
            <li
                v-for="(item, index) in selectedMedia"
                :key="item.id"
                class="flex items-center gap-3 rounded-lg border p-2"
            >
                <div
                    class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded bg-muted"
                >
                    <img
                        v-if="item.thumbnail_url"
                        :src="item.thumbnail_url"
                        alt=""
                        class="h-full w-full object-cover"
                    />
                    <Video v-else-if="item.kind === 'video'" class="size-5" />
                    <Music v-else-if="item.kind === 'audio'" class="size-5" />
                    <FileText v-else class="size-5" />
                </div>
                <span class="flex-1 truncate text-sm">{{
                    item.original_name
                }}</span>
                <div class="flex gap-1">
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        :disabled="disabled || index === 0"
                        @click="move(index, -1)"
                    >
                        <ArrowUp class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        :disabled="
                            disabled || index === selectedMedia.length - 1
                        "
                        @click="move(index, 1)"
                    >
                        <ArrowDown class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        :disabled="disabled"
                        @click="remove(item.id)"
                    >
                        <X class="size-4" />
                    </Button>
                </div>
            </li>
        </ul>

        <Dialog :open="isOpen" @update:open="openPicker">
            <DialogTrigger as-child>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="disabled"
                    data-test="open-media-picker"
                >
                    {{
                        selectedMedia.length > 0
                            ? 'Change media'
                            : 'Add media from library'
                    }}
                </Button>
            </DialogTrigger>
            <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Choose media</DialogTitle>
                    <DialogDescription>
                        Click to select, in the order you want them shown.
                        <Link
                            :href="mediaIndex()"
                            class="underline underline-offset-4"
                            >Upload new files in Media</Link
                        >.
                    </DialogDescription>
                </DialogHeader>

                <p
                    v-if="library.length === 0"
                    class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground"
                >
                    The library is empty. Upload files on the Media page first.
                </p>

                <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <li v-for="item in library" :key="item.id">
                        <button
                            type="button"
                            class="relative block w-full overflow-hidden rounded-lg border text-left"
                            :class="
                                draftIds.includes(item.id)
                                    ? 'ring-2 ring-primary'
                                    : ''
                            "
                            :data-test="`pick-media-${item.id}`"
                            @click="toggle(item.id)"
                        >
                            <div
                                class="flex aspect-video items-center justify-center bg-muted"
                            >
                                <img
                                    v-if="item.thumbnail_url"
                                    :src="item.thumbnail_url"
                                    alt=""
                                    class="h-full w-full object-cover"
                                />
                                <Video
                                    v-else-if="item.kind === 'video'"
                                    class="size-6"
                                />
                                <Music
                                    v-else-if="item.kind === 'audio'"
                                    class="size-6"
                                />
                                <FileText v-else class="size-6" />
                            </div>
                            <span class="block truncate p-2 text-xs">{{
                                item.original_name
                            }}</span>
                            <span
                                v-if="draftIds.includes(item.id)"
                                class="absolute top-2 right-2 flex size-6 items-center justify-center rounded-full bg-primary text-xs text-primary-foreground"
                            >
                                {{ draftIds.indexOf(item.id) + 1 }}
                            </span>
                        </button>
                    </li>
                </ul>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="openPicker(false)"
                        >Cancel</Button
                    >
                    <Button
                        type="button"
                        data-test="confirm-media"
                        @click="confirmSelection"
                    >
                        <Check class="size-4" /> Use {{ draftIds.length }}
                        {{ draftIds.length === 1 ? 'file' : 'files' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
