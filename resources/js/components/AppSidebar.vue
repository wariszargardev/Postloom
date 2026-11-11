<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    CalendarDays,
    Hand,
    Images,
    Layers,
    LayoutGrid,
    PenSquare,
    Share2,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as accountsIndex } from '@/routes/accounts';
import { index as calendarIndex } from '@/routes/calendar';
import { index as mediaIndex } from '@/routes/media';
import { index as postsIndex } from '@/routes/posts';
import { index as workspacesIndex } from '@/routes/workspaces';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Calendar',
        href: calendarIndex(),
        icon: CalendarDays,
    },
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Posts',
        href: postsIndex(),
        icon: PenSquare,
    },
    {
        title: 'To post',
        href: '/to-post',
        icon: Hand,
        badge: page.props.postsWaitingForYou ?? 0,
    },
    {
        title: 'Accounts',
        href: accountsIndex(),
        icon: Share2,
    },
    {
        title: 'Media',
        href: mediaIndex(),
        icon: Images,
    },
    {
        title: 'Workspaces',
        href: workspacesIndex(),
        icon: Layers,
    },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="calendarIndex()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <WorkspaceSwitcher />
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
