<script setup lang="ts">
import { Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useMediaUpload } from '@/composables/useMediaUpload';
import type {
    EditorAccount,
    LibraryMedia,
    PlatformSetting,
    SettingValue,
} from '@/types/posts';

const IMAGE_ACCEPT = '.jpg,.jpeg,.png';

const props = defineProps<{
    account: EditorAccount;
    library: LibraryMedia[];
    disabled: boolean;
}>();

const settings = defineModel<Record<string, SettingValue>>('settings', {
    required: true,
});

const entries = computed(
    () =>
        Object.entries(props.account.rules?.settings ?? {}) as [
            string,
            PlatformSetting,
        ][],
);

const images = computed(() =>
    props.library.filter((item) => item.kind === 'image'),
);

const imageInput = ref<HTMLInputElement | null>(null);
const uploadingKey = ref<string | null>(null);
const uploader = useMediaUpload();

function update(key: string, value: SettingValue): void {
    settings.value = { ...settings.value, [key]: value };
}

function fieldId(key: string): string {
    return `setting-${props.account.id}-${key}`;
}

function textValue(key: string): string {
    const value = settings.value[key];

    return value === null || value === undefined ? '' : String(value);
}

function selectedThumbnail(key: string): LibraryMedia | undefined {
    return images.value.find((item) => item.id === Number(settings.value[key]));
}

function chooseImage(key: string): void {
    uploadingKey.value = key;
    imageInput.value?.click();
}

function uploadImage(files: FileList | null): void {
    const key = uploadingKey.value;

    if (key === null) {
        return;
    }

    uploader.upload(files, (mediaIds) => {
        if (mediaIds.length > 0) {
            update(key, mediaIds[0]);
        }
    });

    if (imageInput.value) {
        imageInput.value.value = '';
    }
}
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <input
            ref="imageInput"
            type="file"
            class="hidden"
            :accept="IMAGE_ACCEPT"
            @change="uploadImage(($event.target as HTMLInputElement).files)"
        />

        <template v-for="[key, setting] in entries" :key="key">
            <div
                v-if="setting.type === 'boolean'"
                class="flex flex-col gap-0.5 sm:col-span-2"
            >
                <label class="flex items-center gap-2 text-sm">
                    <input
                        :id="fieldId(key)"
                        :name="fieldId(key)"
                        type="checkbox"
                        :checked="Boolean(settings[key])"
                        :disabled="disabled"
                        :data-test="fieldId(key)"
                        @change="
                            update(
                                key,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                    {{ setting.label }}
                </label>
                <span
                    v-if="setting.help"
                    class="pl-6 text-xs text-muted-foreground"
                    >{{ setting.help }}</span
                >
            </div>

            <div
                v-else
                class="flex flex-col gap-1"
                :class="
                    setting.type === 'text' ||
                    setting.type === 'tags' ||
                    setting.type === 'image'
                        ? 'sm:col-span-2'
                        : ''
                "
            >
                <Label :for="fieldId(key)"
                    >{{ setting.label
                    }}<span v-if="setting.required" class="text-destructive">
                        *</span
                    ></Label
                >

                <template
                    v-if="setting.type === 'text' || setting.type === 'tags'"
                >
                    <input
                        :id="fieldId(key)"
                        :name="fieldId(key)"
                        type="text"
                        autocomplete="off"
                        class="h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50"
                        :value="textValue(key)"
                        :maxlength="
                            setting.type === 'text'
                                ? setting.max_length
                                : undefined
                        "
                        :placeholder="
                            setting.type === 'tags'
                                ? 'laravel, php, tutorial'
                                : ''
                        "
                        :disabled="disabled"
                        :data-test="fieldId(key)"
                        @input="
                            update(
                                key,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <span
                        v-if="setting.max_length"
                        class="self-end text-xs text-muted-foreground"
                        >{{ textValue(key).length }} /
                        {{ setting.max_length }}</span
                    >
                </template>

                <template v-else-if="setting.type === 'image'">
                    <div class="flex flex-wrap items-center gap-3">
                        <img
                            v-if="selectedThumbnail(key)?.thumbnail_url"
                            :src="selectedThumbnail(key)!.thumbnail_url!"
                            alt=""
                            class="aspect-video w-28 rounded border object-cover"
                        />
                        <select
                            :id="fieldId(key)"
                            :name="fieldId(key)"
                            :value="settings[key] ?? ''"
                            class="h-9 min-w-48 flex-1 rounded-md border bg-transparent px-3 text-sm"
                            :disabled="disabled"
                            :data-test="fieldId(key)"
                            @change="
                                update(
                                    key,
                                    ($event.target as HTMLSelectElement)
                                        .value === ''
                                        ? null
                                        : Number(
                                              (
                                                  $event.target as HTMLSelectElement
                                              ).value,
                                          ),
                                )
                            "
                        >
                            <option value="">
                                {{
                                    images.length === 0
                                        ? 'No images yet'
                                        : 'YouTube picks a frame'
                                }}
                            </option>
                            <option
                                v-for="image in images"
                                :key="image.id"
                                :value="image.id"
                            >
                                {{ image.original_name }}
                            </option>
                        </select>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="disabled || uploader.form.processing"
                            :data-test="`${fieldId(key)}-upload`"
                            @click="chooseImage(key)"
                        >
                            <Upload class="size-4" />
                            {{
                                uploader.form.processing && uploadingKey === key
                                    ? `Uploading… ${uploader.form.progress?.percentage ?? 0}%`
                                    : 'Upload image'
                            }}
                        </Button>
                    </div>
                    <p
                        v-for="message in uploader.errors.value"
                        :key="message"
                        class="text-xs text-destructive"
                    >
                        {{ message }}
                    </p>
                </template>

                <select
                    v-else
                    :id="fieldId(key)"
                    :name="fieldId(key)"
                    :value="settings[key]"
                    class="h-9 rounded-md border bg-transparent px-3 text-sm"
                    :disabled="disabled"
                    :data-test="fieldId(key)"
                    @change="
                        update(key, ($event.target as HTMLSelectElement).value)
                    "
                >
                    <option
                        v-for="(label, value) in setting.options"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>

                <span
                    v-if="setting.help"
                    class="text-xs text-muted-foreground"
                    >{{ setting.help }}</span
                >
            </div>
        </template>
    </div>
</template>
