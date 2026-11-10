<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    EditorAccount,
    LibraryMedia,
    PlatformSetting,
    SettingValue,
} from '@/types/posts';

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
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <template v-for="[key, setting] in entries" :key="key">
            <label
                v-if="setting.type === 'boolean'"
                class="flex items-center gap-2 text-sm sm:col-span-2"
            >
                <input
                    :id="fieldId(key)"
                    type="checkbox"
                    :checked="Boolean(settings[key])"
                    :disabled="disabled"
                    :data-test="fieldId(key)"
                    @change="
                        update(key, ($event.target as HTMLInputElement).checked)
                    "
                />
                {{ setting.label }}
            </label>

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
                    <Input
                        :id="fieldId(key)"
                        :model-value="textValue(key)"
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
                        @update:model-value="update(key, String($event))"
                    />
                    <span
                        v-if="setting.max_length"
                        class="self-end text-xs text-muted-foreground"
                        >{{ textValue(key).length }} /
                        {{ setting.max_length }}</span
                    >
                </template>

                <div
                    v-else-if="setting.type === 'image'"
                    class="flex items-center gap-3"
                >
                    <img
                        v-if="selectedThumbnail(key)?.thumbnail_url"
                        :src="selectedThumbnail(key)!.thumbnail_url!"
                        alt=""
                        class="aspect-video w-28 rounded border object-cover"
                    />
                    <select
                        :id="fieldId(key)"
                        :value="settings[key] ?? ''"
                        class="h-9 flex-1 rounded-md border bg-transparent px-3 text-sm"
                        :disabled="disabled"
                        :data-test="fieldId(key)"
                        @change="
                            update(
                                key,
                                ($event.target as HTMLSelectElement).value ===
                                    ''
                                    ? null
                                    : Number(
                                          ($event.target as HTMLSelectElement)
                                              .value,
                                      ),
                            )
                        "
                    >
                        <option value="">
                            {{
                                images.length === 0
                                    ? 'Upload an image in Media first'
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
                </div>

                <select
                    v-else
                    :id="fieldId(key)"
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
            </div>
        </template>
    </div>
</template>
