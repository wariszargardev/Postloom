<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Download, FileSpreadsheet, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import PostImportController from '@/actions/App/Http/Controllers/PostImportController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PlatformIcon from '@/components/PlatformIcon.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { index as postsIndex } from '@/routes/posts';

type PreviewRow = {
    row: number;
    status: string;
    status_label: string;
    scheduled_at: string | null;
    timezone: string;
    title: string | null;
    caption: string;
    accounts: { id: number; name: string; provider: string }[];
    media: string[];
    errors: string[];
};

const props = defineProps<{
    preview: {
        import_id: string;
        file_name: string;
        rows: PreviewRow[];
        valid_count: number;
    } | null;
    columns: { required: string[]; optional: string[] };
    maxRows: number;
    defaultTimezone: string;
    upload: { accept: string; max_files: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Posts', href: postsIndex() },
            { title: 'Import CSV', href: PostImportController.create() },
        ],
    },
});

const form = useForm<{ csv: File | null; files: File[] }>({
    csv: null,
    files: [],
});
const csvInput = ref<HTMLInputElement | null>(null);
const mediaInput = ref<HTMLInputElement | null>(null);
const importing = ref(false);

const invalidCount = computed(() =>
    props.preview ? props.preview.rows.length - props.preview.valid_count : 0,
);

const mediaError = computed(
    () =>
        form.errors.files ??
        Object.entries(form.errors).find(([key]) =>
            key.startsWith('files.'),
        )?.[1],
);

function pickCsv(event: Event): void {
    form.csv = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function pickMedia(event: Event): void {
    form.files = Array.from((event.target as HTMLInputElement).files ?? []);
}

function submitPreview(): void {
    form.post(PostImportController.preview().url, {
        forceFormData: true,
        onSuccess: () => {
            form.reset();

            if (csvInput.value) {
                csvInput.value.value = '';
            }

            if (mediaInput.value) {
                mediaInput.value.value = '';
            }
        },
    });
}

function runImport(): void {
    if (!props.preview) {
        return;
    }

    router.post(
        PostImportController.store().url,
        { import_id: props.preview.import_id },
        {
            onStart: () => (importing.value = true),
            onFinish: () => (importing.value = false),
        },
    );
}

function formatWhen(row: PreviewRow): string {
    if (!row.scheduled_at) {
        return 'No time';
    }

    return new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: row.timezone,
    }).format(new Date(row.scheduled_at));
}
</script>

<template>
    <Head title="Import CSV" />

    <div class="page">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Import CSV"
                description="Create many posts at once. Check the preview, then import."
            />
            <Button variant="outline" as-child>
                <a
                    :href="PostImportController.template().url"
                    data-test="download-template"
                    ><Download class="size-4" /> Download template</a
                >
            </Button>
        </div>

        <form
            class="surface flex flex-col gap-5 p-5"
            data-test="import-form"
            @submit.prevent="submitPreview"
        >
            <div class="grid gap-5 md:grid-cols-2">
                <label class="flex flex-col gap-2">
                    <span class="section-title"
                        ><span class="step-number">1</span> CSV file</span
                    >
                    <input
                        ref="csvInput"
                        type="file"
                        accept=".csv,text/csv"
                        class="field-input py-1.5"
                        data-test="csv-input"
                        @change="pickCsv"
                    />
                    <span class="text-xs text-muted-foreground"
                        >Up to {{ maxRows }} rows, 1 MB.</span
                    >
                    <InputError :message="form.errors.csv" />
                </label>

                <label class="flex flex-col gap-2">
                    <span class="section-title"
                        ><span class="step-number">2</span> Media files
                        (optional)</span
                    >
                    <input
                        ref="mediaInput"
                        type="file"
                        multiple
                        :accept="upload.accept"
                        class="field-input py-1.5"
                        data-test="media-input"
                        @change="pickMedia"
                    />
                    <span class="text-xs text-muted-foreground"
                        >Up to {{ upload.max_files }} files. They go into the
                        media library; the <code>media</code> column names them.
                        Files already in the library don't need uploading.</span
                    >
                    <InputError :message="mediaError" />
                </label>
            </div>

            <div v-if="form.processing" class="w-full max-w-sm">
                <div class="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full bg-primary transition-all"
                        :style="{
                            width: `${form.progress?.percentage ?? 0}%`,
                        }"
                    />
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    Uploading… {{ form.progress?.percentage ?? 0 }}%
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    :disabled="!form.csv || form.processing"
                    data-test="preview-button"
                >
                    <Upload class="size-4" /> Check CSV
                </Button>
                <span class="text-xs text-muted-foreground"
                    >Nothing is saved until you click Import.</span
                >
            </div>
        </form>

        <section
            v-if="preview"
            class="flex flex-col gap-4"
            data-test="import-preview"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <FileSpreadsheet class="size-5 text-muted-foreground" />
                    <span class="font-medium">{{ preview.file_name }}</span>
                    <span class="text-sm text-muted-foreground"
                        >{{ preview.valid_count }} ready<template
                            v-if="invalidCount > 0"
                            >, {{ invalidCount }} with errors
                            (skipped)</template
                        ></span
                    >
                </div>
                <div class="flex gap-2">
                    <Button variant="ghost" as-child>
                        <Link :href="PostImportController.create()"
                            >Cancel</Link
                        >
                    </Button>
                    <Button
                        :disabled="preview.valid_count === 0 || importing"
                        data-test="import-button"
                        @click="runImport"
                    >
                        Import {{ preview.valid_count }}
                        {{ preview.valid_count === 1 ? 'post' : 'posts' }}
                    </Button>
                </div>
            </div>

            <div class="surface overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm">
                    <thead
                        class="border-b text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">Row</th>
                            <th class="px-3 py-2 font-medium">When</th>
                            <th class="px-3 py-2 font-medium">Accounts</th>
                            <th class="px-3 py-2 font-medium">Post</th>
                            <th class="px-3 py-2 font-medium">Media</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in preview.rows"
                            :key="row.row"
                            class="border-b align-top last:border-0"
                            :class="
                                row.errors.length
                                    ? 'bg-status-failed/5'
                                    : undefined
                            "
                            :data-test="`import-row-${row.row}`"
                        >
                            <td class="px-3 py-3 font-mono text-xs">
                                {{ row.row }}
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex flex-col gap-1">
                                    <StatusBadge
                                        :status="
                                            row.errors.length
                                                ? 'failed'
                                                : row.status
                                        "
                                        :label="
                                            row.errors.length
                                                ? 'Error'
                                                : row.status_label
                                        "
                                    />
                                    <span class="whitespace-nowrap">{{
                                        formatWhen(row)
                                    }}</span>
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{ row.timezone }}</span
                                    >
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <ul class="flex flex-col gap-1">
                                    <li
                                        v-for="account in row.accounts"
                                        :key="account.id"
                                        class="flex items-center gap-1.5"
                                    >
                                        <PlatformIcon
                                            :provider="account.provider"
                                        />
                                        <span class="truncate">{{
                                            account.name
                                        }}</span>
                                    </li>
                                </ul>
                            </td>
                            <td class="max-w-sm px-3 py-3">
                                <p v-if="row.title" class="font-medium">
                                    {{ row.title }}
                                </p>
                                <p
                                    class="line-clamp-3 whitespace-pre-line text-muted-foreground"
                                >
                                    {{ row.caption || '(no caption)' }}
                                </p>
                                <ul
                                    v-if="row.errors.length"
                                    class="mt-2 flex flex-col gap-1 text-xs text-status-failed"
                                >
                                    <li
                                        v-for="error in row.errors"
                                        :key="error"
                                    >
                                        {{ error }}
                                    </li>
                                </ul>
                            </td>
                            <td class="px-3 py-3 text-xs">
                                <span
                                    v-for="name in row.media"
                                    :key="name"
                                    class="block truncate font-mono"
                                    >{{ name }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="surface flex flex-col gap-3 p-5 text-sm">
            <h2 class="section-title">Columns</h2>
            <p class="text-muted-foreground">
                Required:
                <code
                    v-for="column in columns.required"
                    :key="column"
                    class="mr-1 font-mono text-foreground"
                    >{{ column }}</code
                >
                · Optional:
                <code
                    v-for="column in columns.optional"
                    :key="column"
                    class="mr-1 font-mono text-foreground"
                    >{{ column }}</code
                >
            </p>
            <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                <li>
                    <code>date</code> as YYYY-MM-DD and <code>time</code> as
                    24-hour HH:MM. <code>timezone</code> defaults to
                    {{ defaultTimezone }}.
                </li>
                <li>
                    <code>status</code>: <code>schedule</code> (default) or
                    <code>draft</code>. Drafts don't need a time.
                </li>
                <li>
                    <code>accounts</code>: names or @handles separated by
                    <code>;</code>. Add a platform to be exact, for example
                    <code>youtube:My Channel;tiktok:@myhandle</code>.
                </li>
                <li>
                    <code>media</code>: file names from the media library,
                    separated by <code>;</code>.
                </li>
                <li>
                    <code>youtube_title</code> (defaults to <code>name</code>),
                    <code>youtube_privacy</code> (public, unlisted, private),
                    <code>facebook_format</code> (post, reel),
                    <code>tiktok_mode</code> (manual, direct, inbox; default
                    manual: you post it yourself).
                </li>
            </ul>
        </section>
    </div>
</template>
