<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import SocialAccountController from '@/actions/App/Http/Controllers/SocialAccountController';
import Heading from '@/components/Heading.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
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
    connected_at: string | null;
};

type ProviderRow = {
    value: string;
    label: string;
    is_available: boolean;
    availability_note: string;
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

const statusVariant: Record<
    AccountRow['status'],
    'secondary' | 'outline' | 'destructive'
> = {
    connected: 'secondary',
    expiring_soon: 'outline',
    reconnect_needed: 'destructive',
};

function formatDate(value: string | null): string {
    return value === null
        ? 'No expiry date'
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

    <div class="flex max-w-4xl flex-col gap-8 p-4">
        <Heading
            title="Accounts"
            :description="`Social accounts connected to the ${currentWorkspaceName} workspace. You can connect several accounts per platform.`"
        />

        <section class="flex flex-col gap-4">
            <p
                v-if="accounts.length === 0"
                class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
            >
                No accounts connected to this workspace yet.
            </p>

            <div
                v-for="group in accountGroups"
                :key="group.provider.value"
                class="flex flex-col gap-3"
            >
                <h2 class="text-sm font-semibold">
                    {{ group.provider.label }}
                </h2>
                <ul class="flex flex-col gap-3">
                    <li
                        v-for="account in group.accounts"
                        :key="account.id"
                        class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
                        :data-test="`account-${account.id}`"
                    >
                        <div class="flex items-center gap-3">
                            <Avatar class="size-10">
                                <AvatarImage
                                    v-if="account.avatar_url"
                                    :src="account.avatar_url"
                                    :alt="account.name"
                                />
                                <AvatarFallback>{{
                                    initials(account.name)
                                }}</AvatarFallback>
                            </Avatar>
                            <div class="flex flex-col">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{
                                        account.name
                                    }}</span>
                                    <Badge
                                        :variant="statusVariant[account.status]"
                                        >{{ account.status_label }}</Badge
                                    >
                                </div>
                                <span class="text-sm text-muted-foreground">
                                    {{ account.account_type }}
                                    <template v-if="account.handle"
                                        >· @{{ account.handle }}</template
                                    >
                                    · Token:
                                    {{ formatDate(account.token_expires_at) }}
                                </span>
                            </div>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="disconnect(account)"
                            >Disconnect</Button
                        >
                    </li>
                </ul>
            </div>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold">Connect a platform</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="provider in providers"
                    :key="provider.value"
                    class="flex items-center justify-between gap-3 rounded-xl border p-4"
                >
                    <div class="flex flex-col">
                        <span class="font-medium">{{ provider.label }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            provider.availability_note
                        }}</span>
                    </div>
                    <Button size="sm" :disabled="!provider.is_available"
                        >Connect</Button
                    >
                </div>
            </div>
        </section>
    </div>
</template>
