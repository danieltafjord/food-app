<script lang="ts">
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

<section class="flex flex-col gap-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold tracking-tight">{title}</h2>
            {#if description}
                <p class="mt-1 text-sm text-muted-foreground">{description}</p>
            {/if}
        </div>
        {#if actions}
            <div class="flex items-center gap-2">{@render actions()}</div>
        {/if}
    </div>
    {@render children?.()}
</section>
