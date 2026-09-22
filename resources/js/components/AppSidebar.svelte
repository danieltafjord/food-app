<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import BookOpen from 'lucide-svelte/icons/book-open';
    import BrainCircuit from 'lucide-svelte/icons/brain-circuit';
    import ChartColumn from 'lucide-svelte/icons/chart-column';
    import KeyRound from 'lucide-svelte/icons/key-round';
    import LayoutGrid from 'lucide-svelte/icons/layout-grid';
    import Users from 'lucide-svelte/icons/users';
    import type { Snippet } from 'svelte';
    import AppLogo from '@/components/AppLogo.svelte';
    import NavFooter from '@/components/NavFooter.svelte';
    import NavMain from '@/components/NavMain.svelte';
    import NavUser from '@/components/NavUser.svelte';
    import {
        Sidebar,
        SidebarContent,
        SidebarFooter,
        SidebarHeader,
        SidebarMenu,
        SidebarMenuButton,
        SidebarMenuItem,
    } from '@/components/ui/sidebar';
    import { toUrl } from '@/lib/utils';
    import { dashboard } from '@/routes';
    import { analytics } from '@/routes/admin';
    import { edit as aiSettings } from '@/routes/admin/ai';
    import { index as adminUsers } from '@/routes/admin/users';
    import { index as apiTokens } from '@/routes/api-tokens';
    import { ui as apiDocs } from '@/routes/scramble/docs';
    import type { NavItem } from '@/types';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'API tokens',
            href: apiTokens(),
            icon: KeyRound,
        },
    ];

    const adminNavItems: NavItem[] = [
        {
            title: 'Analytics',
            href: analytics(),
            icon: ChartColumn,
        },
        {
            title: 'Users',
            href: adminUsers(),
            icon: Users,
        },
        {
            title: 'AI models',
            href: aiSettings(),
            icon: BrainCircuit,
        },
    ];

    const isAdmin = $derived(page.props.auth.user?.is_admin === true);

    const footerNavItems: NavItem[] = [
        {
            title: 'API reference',
            href: apiDocs(),
            icon: BookOpen,
        },
    ];
</script>

<Sidebar collapsible="icon" variant="inset">
    <SidebarHeader>
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" asChild>
                    {#snippet children(props)}
                        <Link
                            {...props}
                            href={toUrl(dashboard())}
                            class={props.class}
                        >
                            <AppLogo />
                        </Link>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarHeader>

    <SidebarContent>
        <NavMain items={mainNavItems} />
        {#if isAdmin}
            <NavMain items={adminNavItems} label="Admin" />
        {/if}
    </SidebarContent>

    <SidebarFooter>
        <NavFooter items={footerNavItems} />
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
