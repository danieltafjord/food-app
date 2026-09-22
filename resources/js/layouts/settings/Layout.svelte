<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';
    import { currentUrlState } from '@/lib/currentUrl.svelte';
    import { cn, toUrl } from '@/lib/utils';
    import { edit as editAiAssistance } from '@/routes/ai-assistance';
    import { index as apiTokens } from '@/routes/api-tokens';
    import { edit as editAppearance } from '@/routes/appearance';
    import { edit as editProfile } from '@/routes/profile';
    import { edit as editSecurity } from '@/routes/security';
    import type { NavItem } from '@/types';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const sidebarNavItems: NavItem[] = [
        {
            title: 'Profile',
            href: editProfile(),
        },
        {
            title: 'Security',
            href: editSecurity(),
        },
        {
            title: 'AI assistance',
            href: editAiAssistance(),
        },
        {
            title: 'API tokens',
            href: apiTokens(),
        },
        {
            title: 'Appearance',
            href: editAppearance(),
        },
    ];

    const url = currentUrlState();
</script>

<div class="px-6 py-10">
    <Heading
        title="Settings"
        description="Manage your profile and account settings"
    />

    <div class="flex flex-col lg:flex-row lg:space-x-12">
        <!-- Phones and tablets: a horizontally scrolling pill strip. -->
        <nav
            class="-mx-6 mb-6 flex gap-1 overflow-x-auto px-6 pb-1 lg:hidden"
            aria-label="Settings"
        >
            {#each sidebarNavItems as item (toUrl(item.href))}
                {@const active = url.isCurrentUrl(item.href, url.currentUrl)}
                <Link
                    href={toUrl(item.href)}
                    class={cn(
                        'shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium whitespace-nowrap transition-colors',
                        active
                            ? 'bg-muted text-foreground'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                    aria-current={active ? 'page' : undefined}
                >
                    {item.title}
                </Link>
            {/each}
        </nav>

        <!-- Desktop: the vertical list on the left. -->
        <aside class="hidden w-48 shrink-0 lg:block">
            <nav class="flex flex-col space-y-1" aria-label="Settings">
                {#each sidebarNavItems as item (toUrl(item.href))}
                    {@const active = url.isCurrentUrl(
                        item.href,
                        url.currentUrl,
                    )}
                    <Button
                        variant="ghost"
                        class={cn(
                            'w-full justify-start rounded-full',
                            active && 'bg-muted',
                        )}
                        asChild
                    >
                        {#snippet children(props)}
                            <Link
                                href={toUrl(item.href)}
                                class={props.class}
                                aria-current={active ? 'page' : undefined}
                            >
                                {item.title}
                            </Link>
                        {/snippet}
                    </Button>
                {/each}
            </nav>
        </aside>

        <div class="flex-1 md:max-w-2xl">
            <section class="max-w-xl space-y-12">
                {@render children?.()}
            </section>
        </div>
    </div>
</div>
