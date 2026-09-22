<script lang="ts">
    import type { Snippet } from 'svelte';
    import { cn } from '@/lib/utils';

    let {
        title = '',
        description = '',
        padded = true,
        class: className = '',
        actions,
        footer,
        children,
    }: {
        title?: string;
        description?: string;
        /** Set to false for tables that want to run edge to edge. */
        padded?: boolean;
        class?: string;
        actions?: Snippet;
        footer?: Snippet;
        children?: Snippet;
    } = $props();
</script>

<div
    class={cn(
        'flex flex-col overflow-hidden rounded-3xl border border-border/80 bg-panel text-card-foreground',
        className,
    )}
>
    {#if title || actions}
        <div
            class="flex flex-wrap items-start justify-between gap-3 px-6 pt-6 pb-4"
        >
            <div>
                {#if title}
                    <h3 class="text-[15px] font-semibold tracking-tight">
                        {title}
                    </h3>
                {/if}
                {#if description}
                    <p class="mt-1 text-sm text-muted-foreground">
                        {description}
                    </p>
                {/if}
            </div>
            {#if actions}
                <div class="flex items-center gap-2">{@render actions()}</div>
            {/if}
        </div>
    {/if}

    <div class={cn('flex-1', padded && (title ? 'px-6 pb-6' : 'p-6'))}>
        {@render children?.()}
    </div>

    {#if footer}
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-t border-border/80 bg-canvas px-6 py-4"
        >
            {@render footer()}
        </div>
    {/if}
</div>
