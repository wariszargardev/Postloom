<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import SocialAccountController from '@/actions/App/Http/Controllers/SocialAccountController';
import Heading from '@/components/Heading.vue';
import PlatformIcon from '@/components/PlatformIcon.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { index as accountsIndex } from '@/routes/accounts';

type AccountRow = {
    id: number;
    provider: string;
    account_type: string;
    name: string;
    handle: string | null;
    avatar_url: string | null;
    status: 'connected' | 'expiring_soon' | 'reconnect_needed';
    status_label: string;
    token_expires_at: string | null;
    token_renews_automatically: boolean;
    connected_at: string | null;
};

type ProviderRow = {
    value: string;
    label: string;
    is_available: boolean;
    availability_note: string;
    connect_url: string | null;
};

const props = defineProps<{
    accounts: AccountRow[];
    providers: ProviderRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Accounts', href: accountsIndex() }],
    },
});

const page = usePage();

const currentWorkspaceName = computed(() => {
    const workspaces = page.props.workspaces;

    return (
        workspaces?.list.find(
            (workspace) => workspace.id === workspaces.current_id,
        )?.name ?? ''
    );
});

const accountGroups = computed(() =>
    props.providers
        .map((provider) => ({
            provider,
            accounts: props.accounts.filter(
                (account) => account.provider === provider.value,
            ),
        }))
        .filter((group) => group.accounts.length > 0),
);

function formatDate(value: string | null): string {
    return value === null
        ? "doesn't expire"
        : new Date(value).toLocaleDateString();
}

function initials(name: string): string {
    return name
        .split(' ')
        .map((part) => part.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

function disconnect(account: AccountRow): void {
    if (
        !confirm(
            `Disconnect ${account.name}? Postloom will forget its access token.`,
        )
    ) {
        return;
    }

    router.delete(SocialAccountController.destroy(account.id).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Accounts" />

    <div class="page-narrow">
        <Heading
            title="Accounts"
            :description="`Social accounts connected to the ${currentWorkspaceName} workspace. You can connect several accounts per platform.`"
        />

        <section class="flex flex-col gap-5">
            <p v-if="accounts.length === 0" class="empty-state">
                No accounts connected to this workspace yet. Pick a platform
                below.
            </p>

            <div
                v-for="group in accountGroups"
                :key="group.provider.value"
                class="flex flex-col gap-3"
            >
                <h2 class="section-title">
                    <PlatformIcon :provider="group.provider.value" size="md" />
                    {{ group.provider.label }}
                </h2>
                <ul class="flex flex-col gap-3">
                    <li
                        v-for="account in group.accounts"
                        :key="account.id"
                        class="surface flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                        :data-test="`account-${account.id}`"
                    >
                        <div class="flex items-center gap-3">
                            <Avatar class="size-11 ring-2 ring-border">
                                <AvatarImage
                                    v-if="account.avatar_url"
                                    :src="account.avatar_url"
                                    :alt="account.name"
                                />
                                <AvatarFallback class="font-mono">{{
                                    initials(account.name)
                                }}</AvatarFallback>
                            </Avatar>
                            <div class="flex flex-col gap-0.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{
                                        account.name
                                    }}</span>
                                    <StatusBadge
                                        :status="account.status"
                                        :label="account.status_label"
                                    />
                                </div>
                                <span
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ account.account_type }}
                                    <template v-if="account.handle"
                                        >· @{{ account.handle }}</template
                                    >
                                    · Token:
                                    {{
                                        account.token_renews_automatically
                                            ? 'renews automatically'
                                            : formatDate(
                                                  account.token_expires_at,
                                              )
                                    }}
                                </span>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="
                                    account.status !== 'connected' &&
                                    group.provider.connect_url
                                "
                                size="sm"
                                as-child
                            >
                                <a :href="group.provider.connect_url"
                                    >Reconnect</a
                                >
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="disconnect(account)"
                                >Disconnect</Button
                            >
                        </div>
                    </li>
                </ul>
            </div>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="section-title">Connect a platform</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="provider in providers"
                    :key="provider.value"
                    class="surface flex items-center justify-between gap-3 p-4"
                    :class="provider.connect_url ? '' : 'opacity-60'"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <PlatformIcon :provider="provider.value" size="md" />
                        <div class="flex min-w-0 flex-col">
                            <span class="font-semibold">{{
                                provider.label
                            }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                provider.availability_note
                            }}</span>
                        </div>
                    </div>
                    <Button v-if="provider.connect_url" size="sm" as-child>
                        <a
                            :href="provider.connect_url"
                            :data-test="`connect-${provider.value}`"
                            >Connect</a
                        >
                    </Button>
                    <Button v-else size="sm" variant="outline" disabled
                        >Soon</Button
                    >
                </div>
            </div>
        </section>
    </div>
</template>
