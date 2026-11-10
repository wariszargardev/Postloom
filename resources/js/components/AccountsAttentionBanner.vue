<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { index as accountsIndex } from '@/routes/accounts';

const page = usePage();

const count = computed(() => page.props.accountsNeedingAttention ?? 0);
</script>

<template>
    <div
        v-if="count > 0"
        class="px-4 pt-4"
        data-test="accounts-attention-banner"
    >
        <Alert>
            <TriangleAlert class="size-4" />
            <AlertTitle>
                {{ count === 1 ? '1 account needs' : `${count} accounts need` }}
                attention
            </AlertTitle>
            <AlertDescription>
                <span>
                    A login token has expired or expires within 7 days.
                    <Link
                        :href="accountsIndex()"
                        class="font-medium underline underline-offset-4"
                        >Open Accounts to reconnect</Link
                    >.
                </span>
            </AlertDescription>
        </Alert>
    </div>
</template>
