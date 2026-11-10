<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Settings2 } from '@lucide/vue';
import { computed } from 'vue';
import WorkspaceController from '@/actions/App/Http/Controllers/WorkspaceController';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { index as workspacesIndex } from '@/routes/workspaces';

const page = usePage();

const workspaces = computed(() => page.props.workspaces);

const currentWorkspace = computed(() =>
    workspaces.value?.list.find(
        (workspace) => workspace.id === workspaces.value?.current_id,
    ),
);

function switchTo(workspaceId: number): void {
    if (workspaceId === workspaces.value?.current_id) {
        return;
    }

    router.post(
        WorkspaceController.switch(workspaceId).url,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <SidebarMenu v-if="workspaces">
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        class="justify-between"
                        data-test="workspace-switcher"
                    >
                        <span class="truncate">{{
                            currentWorkspace?.name ?? 'Workspace'
                        }}</span>
                        <ChevronsUpDown class="size-4 opacity-60" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="min-w-56">
                    <DropdownMenuLabel>Workspaces</DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="workspace in workspaces.list"
                        :key="workspace.id"
                        @select="switchTo(workspace.id)"
                    >
                        <span class="flex-1 truncate">{{
                            workspace.name
                        }}</span>
                        <Check
                            v-if="workspace.id === workspaces.current_id"
                            class="size-4"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem as-child>
                        <Link
                            :href="workspacesIndex()"
                            class="flex items-center gap-2"
                        >
                            <Settings2 class="size-4" />
                            Manage workspaces
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
