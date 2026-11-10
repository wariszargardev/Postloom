<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PostController from '@/actions/App/Http/Controllers/PostController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import MediaPicker from '@/components/posts/MediaPicker.vue';
import PostPreview from '@/components/posts/PostPreview.vue';
import ScheduleTimePicker from '@/components/posts/ScheduleTimePicker.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as accountsIndex } from '@/routes/accounts';
import { index as postsIndex } from '@/routes/posts';
import type {
    EditorAccount,
    EditorPost,
    LibraryMedia,
    TargetForm,
} from '@/types/posts';

const props = defineProps<{
    post: EditorPost | null;
    accounts: EditorAccount[];
    library: LibraryMedia[];
    defaultTimezone: string;
    timezones: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Posts', href: postsIndex() }],
    },
});

const form = useForm<{
    intent: 'draft' | 'schedule';
    title: string;
    content: string;
    timezone: string;
    scheduled_at: string;
    targets: TargetForm[];
    media_ids: number[];
}>({
    intent: 'draft',
    title: props.post?.title ?? '',
    content: props.post?.content ?? '',
    timezone: props.post?.timezone ?? props.defaultTimezone,
    scheduled_at: props.post?.scheduled_at ?? '',
    targets: (props.post?.targets ?? []).map((target) => ({
        social_account_id: target.social_account_id,
        content_override: target.content_override,
        settings: { ...target.settings },
    })),
    media_ids: [...(props.post?.media_ids ?? [])],
});

const isEditable = computed(() => props.post?.is_editable ?? true);
const customizingAccountId = ref<number | null>(null);

const selectedAccounts = computed(() =>
    form.targets
        .map((target) =>
            props.accounts.find(
                (account) => account.id === target.social_account_id,
            ),
        )
        .filter((account): account is EditorAccount => account !== undefined),
);

const selectedMedia = computed(() =>
    form.media_ids
        .map((id) => props.library.find((item) => item.id === id))
        .filter((item): item is LibraryMedia => item !== undefined),
);

const textLimit = computed(() => {
    const limits = selectedAccounts.value
        .map((account) => account.rules?.max_text_length)
        .filter((limit): limit is number => limit !== undefined);

    return limits.length > 0 ? Math.min(...limits) : null;
});

const generalErrors = computed(() =>
    Object.entries(form.errors)
        .filter(
            ([key]) =>
                key === 'post' ||
                key === 'targets' ||
                key === 'content' ||
                /^targets\.\d+$/.test(key),
        )
        .map(([, message]) => message),
);

function targetFor(accountId: number): TargetForm | undefined {
    return form.targets.find(
        (target) => target.social_account_id === accountId,
    );
}

function isSelected(accountId: number): boolean {
    return targetFor(accountId) !== undefined;
}

function toggleAccount(account: EditorAccount): void {
    if (isSelected(account.id)) {
        form.targets = form.targets.filter(
            (target) => target.social_account_id !== account.id,
        );

        if (customizingAccountId.value === account.id) {
            customizingAccountId.value = null;
        }

        return;
    }

    const defaults = Object.fromEntries(
        Object.entries(account.rules?.settings ?? {}).map(([key, setting]) => [
            key,
            setting.default,
        ]),
    );

    form.targets = [
        ...form.targets,
        {
            social_account_id: account.id,
            content_override: null,
            settings: defaults,
        },
    ];
}

function textFor(accountId: number): string {
    const override = targetFor(accountId)?.content_override;

    return override !== null && override !== undefined && override !== ''
        ? override
        : form.content;
}

function toggleCustomText(accountId: number): void {
    const target = targetFor(accountId);

    if (!target) {
        return;
    }

    target.content_override =
        target.content_override === null ? form.content : null;
}

function submit(intent: 'draft' | 'schedule'): void {
    form.intent = intent;

    const options = { preserveScroll: true };

    if (props.post) {
        form.put(PostController.update(props.post.id).url, options);
    } else {
        form.post(PostController.store().url, options);
    }
}

function unschedule(): void {
    if (props.post) {
        router.post(
            PostController.unschedule(props.post.id).url,
            {},
            { preserveScroll: true },
        );
    }
}

function destroy(): void {
    if (props.post && confirm('Delete this post?')) {
        router.delete(PostController.destroy(props.post.id).url);
    }
}

function counterClass(
    length: number,
    limit: number | null | undefined,
): string {
    return limit && length > limit
        ? 'text-destructive font-medium'
        : 'text-muted-foreground';
}

const initials = (name: string): string =>
    name
        .split(' ')
        .map((part) => part.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase();
</script>

<template>
    <Head :title="post ? 'Edit post' : 'New post'" />

    <div class="grid gap-6 p-4 xl:grid-cols-[minmax(0,1fr)_420px]">
        <form class="flex flex-col gap-6" @submit.prevent="submit('draft')">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Heading
                    :title="post ? 'Edit post' : 'New post'"
                    description="Write once, choose accounts, then save a draft or schedule it."
                />
                <Badge v-if="post" variant="secondary">{{
                    post.status_label
                }}</Badge>
            </div>

            <p
                v-if="!isEditable"
                class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
            >
                This post is being published right now, so it can't be changed.
            </p>

            <div
                v-if="generalErrors.length > 0"
                class="flex flex-col gap-1 rounded-lg border border-destructive/40 bg-destructive/10 p-3"
                data-test="post-errors"
            >
                <InputError
                    v-for="message in generalErrors"
                    :key="message"
                    :message="message"
                />
            </div>

            <section class="flex flex-col gap-3">
                <h2 class="text-sm font-semibold">1. Accounts</h2>
                <p
                    v-if="accounts.length === 0"
                    class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
                >
                    No accounts in this workspace yet.
                    <Link
                        :href="accountsIndex()"
                        class="underline underline-offset-4"
                        >Connect one on Accounts</Link
                    >.
                </p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="account in accounts"
                        :key="account.id"
                        type="button"
                        class="flex items-center gap-3 rounded-lg border p-3 text-left transition-colors"
                        :class="
                            isSelected(account.id)
                                ? 'border-primary bg-primary/5'
                                : ''
                        "
                        :disabled="!isEditable"
                        :data-test="`account-toggle-${account.id}`"
                        @click="toggleAccount(account)"
                    >
                        <Avatar class="size-9">
                            <AvatarImage
                                v-if="account.avatar_url"
                                :src="account.avatar_url"
                                :alt="account.name"
                            />
                            <AvatarFallback>{{
                                initials(account.name)
                            }}</AvatarFallback>
                        </Avatar>
                        <div class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate text-sm font-medium">{{
                                account.name
                            }}</span>
                            <span class="text-xs text-muted-foreground">
                                {{ account.provider_label }}
                                <template
                                    v-if="account.status === 'reconnect_needed'"
                                    >· needs reconnect</template
                                >
                                <template v-else-if="!account.rules"
                                    >· posting comes later</template
                                >
                            </span>
                        </div>
                        <span
                            class="flex size-5 items-center justify-center rounded border text-xs"
                            :class="
                                isSelected(account.id)
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : ''
                            "
                            >{{ isSelected(account.id) ? '✓' : '' }}</span
                        >
                    </button>
                </div>
            </section>

            <section class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <Label for="content" class="text-sm font-semibold"
                        >2. Text</Label
                    >
                    <span
                        class="text-xs"
                        :class="counterClass(form.content.length, textLimit)"
                        data-test="char-counter"
                    >
                        {{ form.content.length.toLocaleString()
                        }}<template v-if="textLimit">
                            / {{ textLimit.toLocaleString() }}</template
                        >
                    </span>
                </div>
                <textarea
                    id="content"
                    v-model="form.content"
                    rows="10"
                    class="w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    placeholder="What do you want to share?"
                    :disabled="!isEditable"
                />
                <InputError :message="form.errors.content" />
            </section>

            <section class="flex flex-col gap-2">
                <h2 class="text-sm font-semibold">3. Media</h2>
                <p
                    v-for="account in selectedAccounts.filter(
                        (item) => item.rules,
                    )"
                    :key="account.id"
                    class="text-xs text-muted-foreground"
                >
                    {{ account.provider_label }}:
                    {{ account.rules?.media_rules }}
                </p>
                <MediaPicker
                    v-model="form.media_ids"
                    :library="library"
                    :disabled="!isEditable"
                />
            </section>

            <section
                v-if="selectedAccounts.length > 0"
                class="flex flex-col gap-3"
            >
                <h2 class="text-sm font-semibold">4. Per account</h2>
                <div
                    v-for="account in selectedAccounts"
                    :key="account.id"
                    class="flex flex-col gap-3 rounded-lg border p-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium"
                            >{{ account.name }} ·
                            {{ account.provider_label }}</span
                        >
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="
                                customizingAccountId =
                                    customizingAccountId === account.id
                                        ? null
                                        : account.id
                            "
                        >
                            {{
                                customizingAccountId === account.id
                                    ? 'Close'
                                    : 'Customize'
                            }}
                        </Button>
                    </div>
                    <InputError
                        :message="form.errors[`targets.${account.id}`]"
                    />

                    <template
                        v-if="
                            customizingAccountId === account.id &&
                            targetFor(account.id)
                        "
                    >
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                :checked="
                                    targetFor(account.id)?.content_override !==
                                    null
                                "
                                :disabled="!isEditable"
                                @change="toggleCustomText(account.id)"
                            />
                            Use different text for this account
                        </label>
                        <div
                            v-if="
                                targetFor(account.id)?.content_override !== null
                            "
                            class="flex flex-col gap-1"
                        >
                            <textarea
                                v-model="
                                    targetFor(account.id)!.content_override
                                "
                                rows="6"
                                class="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                :disabled="!isEditable"
                            />
                            <span
                                class="self-end text-xs"
                                :class="
                                    counterClass(
                                        (
                                            targetFor(account.id)
                                                ?.content_override ?? ''
                                        ).length,
                                        account.rules?.max_text_length,
                                    )
                                "
                            >
                                {{
                                    (
                                        targetFor(account.id)
                                            ?.content_override ?? ''
                                    ).length.toLocaleString()
                                }}
                                <template v-if="account.rules">
                                    /
                                    {{
                                        account.rules.max_text_length.toLocaleString()
                                    }}</template
                                >
                            </span>
                        </div>

                        <div
                            v-for="(setting, key) in account.rules?.settings ??
                            {}"
                            :key="key"
                            class="flex flex-col gap-1"
                        >
                            <Label :for="`setting-${account.id}-${key}`">{{
                                setting.label
                            }}</Label>
                            <select
                                :id="`setting-${account.id}-${key}`"
                                v-model="targetFor(account.id)!.settings[key]"
                                class="h-9 rounded-md border bg-transparent px-3 text-sm"
                                :disabled="!isEditable"
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
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-sm font-semibold">5. When</h2>
                <ScheduleTimePicker
                    v-model="form.scheduled_at"
                    :timezone="form.timezone"
                    :disabled="!isEditable"
                />
                <InputError :message="form.errors.scheduled_at" />
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1">
                        <Label for="timezone">Timezone</Label>
                        <select
                            id="timezone"
                            v-model="form.timezone"
                            class="h-9 rounded-md border bg-transparent px-3 text-sm"
                            :disabled="!isEditable"
                        >
                            <option
                                v-for="zone in timezones"
                                :key="zone"
                                :value="zone"
                            >
                                {{ zone }}
                            </option>
                        </select>
                        <InputError :message="form.errors.timezone" />
                    </div>
                </div>
                <div class="flex flex-col gap-1">
                    <Label for="title">Internal name (optional)</Label>
                    <Input
                        id="title"
                        v-model="form.title"
                        maxlength="255"
                        placeholder="Only you see this"
                        :disabled="!isEditable"
                    />
                </div>
            </section>

            <div class="flex flex-wrap gap-2 border-t pt-4">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing || !isEditable"
                    data-test="save-draft"
                    @click="submit('draft')"
                >
                    Save draft
                </Button>
                <Button
                    type="button"
                    :disabled="form.processing || !isEditable"
                    data-test="schedule"
                    @click="submit('schedule')"
                >
                    Schedule
                </Button>
                <Button
                    v-if="post?.status === 'scheduled'"
                    type="button"
                    variant="ghost"
                    :disabled="form.processing"
                    @click="unschedule"
                >
                    Move to drafts
                </Button>
                <Button
                    v-if="post && isEditable"
                    type="button"
                    variant="ghost"
                    class="ml-auto text-destructive"
                    @click="destroy"
                >
                    Delete
                </Button>
            </div>
        </form>

        <aside class="flex flex-col gap-4 xl:sticky xl:top-4 xl:self-start">
            <h2 class="text-sm font-semibold">Preview</h2>
            <p
                v-if="selectedAccounts.length === 0"
                class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
            >
                Pick an account to see the preview.
            </p>
            <PostPreview
                v-for="account in selectedAccounts"
                :key="account.id"
                :account="account"
                :text="textFor(account.id)"
                :media="selectedMedia"
                :visibility="targetFor(account.id)?.settings.visibility"
            />
        </aside>
    </div>
</template>
