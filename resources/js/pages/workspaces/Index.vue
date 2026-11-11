<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import WorkspaceController from '@/actions/App/Http/Controllers/WorkspaceController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as workspacesIndex } from '@/routes/workspaces';

type WorkspaceRow = {
    id: number;
    name: string;
    accounts_count: number;
    is_current: boolean;
    delete_block_reason: string | null;
};

defineProps<{
    workspaces: WorkspaceRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspaces', href: workspacesIndex() }],
    },
});

const renamingId = ref<number | null>(null);

function switchTo(workspace: WorkspaceRow): void {
    router.post(
        WorkspaceController.switch(workspace.id).url,
        {},
        { preserveScroll: true },
    );
}

function destroy(workspace: WorkspaceRow): void {
    if (!confirm(`Delete the workspace "${workspace.name}"?`)) {
        return;
    }

    router.delete(WorkspaceController.destroy(workspace.id).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Workspaces" />

    <div class="page-narrow">
        <Heading
            title="Workspaces"
            description="Group your social accounts, for example Personal or a brand. Posts and accounts belong to one workspace."
        />

        <Form
            v-bind="WorkspaceController.store.form()"
            reset-on-success
            class="flex flex-col gap-2 sm:flex-row sm:items-start"
            v-slot="{ errors, processing }"
        >
            <div class="grid flex-1 gap-2">
                <Label for="new-workspace-name" class="sr-only"
                    >New workspace name</Label
                >
                <Input
                    id="new-workspace-name"
                    name="name"
                    required
                    maxlength="60"
                    placeholder="New workspace name"
                />
                <InputError :message="errors.name" />
            </div>
            <Button :disabled="processing" data-test="create-workspace-button"
                >Add workspace</Button
            >
        </Form>

        <ul class="flex flex-col gap-3">
            <li
                v-for="workspace in workspaces"
                :key="workspace.id"
                class="surface flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                :data-test="`workspace-${workspace.id}`"
            >
                <Form
                    v-if="renamingId === workspace.id"
                    v-bind="WorkspaceController.update.form(workspace.id)"
                    class="flex flex-1 flex-col gap-2 sm:flex-row sm:items-start"
                    :on-success="() => (renamingId = null)"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid flex-1 gap-2">
                        <Input
                            name="name"
                            :default-value="workspace.name"
                            required
                            maxlength="60"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="flex gap-2">
                        <Button :disabled="processing" size="sm">Save</Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="renamingId = null"
                            >Cancel</Button
                        >
                    </div>
                </Form>

                <div v-else class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ workspace.name }}</span>
                        <Badge v-if="workspace.is_current" variant="secondary"
                            >Current</Badge
                        >
                    </div>
                    <span class="text-sm text-muted-foreground">
                        {{ workspace.accounts_count }}
                        {{
                            workspace.accounts_count === 1
                                ? 'account'
                                : 'accounts'
                        }}
                    </span>
                </div>

                <div
                    v-if="renamingId !== workspace.id"
                    class="flex flex-wrap gap-2"
                >
                    <Button
                        v-if="!workspace.is_current"
                        variant="outline"
                        size="sm"
                        @click="switchTo(workspace)"
                    >
                        Switch to
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="renamingId = workspace.id"
                        >Rename</Button
                    >
                    <Button
                        variant="destructive"
                        size="sm"
                        :disabled="workspace.delete_block_reason !== null"
                        :title="
                            workspace.delete_block_reason ??
                            'Delete this workspace'
                        "
                        @click="destroy(workspace)"
                    >
                        Delete
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
