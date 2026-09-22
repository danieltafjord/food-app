<script lang="ts">
    /**
     * Shell for every backoffice page: a generous title block with optional
     * actions on the right, and a calm white canvas below where softly
     * rounded panels sit on hairline borders.
     */
    import type { Snippet } from 'svelte';

    let {
        title,
        description = '',
        actions,
        children,
    }: {
        title: string;
        description?: string;
        actions?: Snippet;
        children?: Snippet;
    } = $props();
</script>

<div class="flex flex-1 flex-col bg-background">
    <div
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-10 px-6 py-10"
    >
        <header
            class="flex flex-wrap items-end justify-between gap-x-8 gap-y-4"
        >
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight">{title}</h1>
                {#if description}
                    <p
                        class="mt-1.5 max-w-2xl text-sm leading-relaxed text-muted-foreground"
                    >
                        {description}
                    </p>
                {/if}
            </div>
            {#if actions}
                <div class="flex shrink-0 items-center gap-2">
                    {@render actions()}
                </div>
            {/if}
        </header>

        {@render children?.()}
    </div>
</div>
