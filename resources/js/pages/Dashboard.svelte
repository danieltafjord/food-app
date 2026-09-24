<script module lang="ts">
    import AppLayout from '@/layouts/AppLayout.svelte';
    import { dashboard } from '@/routes';

    export const layout = [
        AppLayout,
        {
            breadcrumbs: [
                {
                    title: 'Dashboard',
                    href: dashboard(),
                },
            ],
        },
    ];
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import BookOpen from 'lucide-svelte/icons/book-open';
    import Bot from 'lucide-svelte/icons/bot';
    import KeyRound from 'lucide-svelte/icons/key-round';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import {
        Card,
        CardDescription,
        CardHeader,
        CardTitle,
    } from '@/components/ui/card';
    import { toUrl } from '@/lib/utils';
    import { index as apiTokens } from '@/routes/api-tokens';
    import { ui as apiDocs } from '@/routes/scramble/docs';

    const cards = [
        {
            title: 'API tokens',
            description:
                'Create a token to connect Home Assistant, scripts or an AI agent to your household, and manage connected apps.',
            href: toUrl(apiTokens()),
            icon: KeyRound,
            external: false,
        },
        {
            title: 'API reference',
            description:
                'Browse every endpoint of the public API: shopping lists, dinners, dinner plans and ingredients.',
            href: toUrl(apiDocs()),
            icon: BookOpen,
            external: true,
        },
        {
            title: 'MCP server',
            description:
                'Let an AI assistant plan dinners and manage your shopping list. The server address is shown on the API tokens page.',
            href: toUrl(apiTokens()),
            icon: Bot,
            external: false,
        },
    ];
</script>

<AppHead title="Dashboard" />

<div class="flex h-full flex-1 flex-col gap-8 overflow-x-auto p-6 md:p-10">
    <Heading
        title="Developer access"
        description="Use your household's data from your own tools"
    />

    <div class="grid auto-rows-min gap-4 md:grid-cols-3">
        {#each cards as card (card.title)}
            {#snippet content()}
                <Card
                    class="h-full rounded-3xl border-border/80 shadow-none transition-colors hover:bg-muted/50"
                >
                    <CardHeader>
                        <div
                            class="mb-2 flex h-11 w-11 items-center justify-center rounded-2xl bg-muted"
                        >
                            <card.icon class="h-5 w-5 text-muted-foreground" />
                        </div>
                        <CardTitle>{card.title}</CardTitle>
                        <CardDescription>{card.description}</CardDescription>
                    </CardHeader>
                </Card>
            {/snippet}

            {#if card.external}
                <a href={card.href} target="_blank" rel="noopener">
                    {@render content()}
                </a>
            {:else}
                <Link href={card.href}>
                    {@render content()}
                </Link>
            {/if}
        {/each}
    </div>
</div>
