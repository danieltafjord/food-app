<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import { cn } from '@/lib/utils';

    export type Segment = {
        value: string | number;
        label: string;
        /** When set the segment navigates instead of calling onSelect. */
        href?: string;
    };

    let {
        segments,
        value,
        label,
        onSelect,
    }: {
        segments: Segment[];
        value: string | number;
        /** Accessible name for the whole control. */
        label: string;
        onSelect?: (value: string | number) => void;
    } = $props();

    const segmentClass = (active: boolean) =>
        cn(
            'rounded-full px-3.5 py-1.5 transition-colors',
            active
                ? 'bg-panel text-foreground shadow-xs'
                : 'text-muted-foreground hover:text-foreground',
        );
</script>

<nav
    class="inline-flex rounded-full bg-muted p-1 text-xs font-medium"
    aria-label={label}
>
    {#each segments as segment (segment.value)}
        {@const active = segment.value === value}
        {#if segment.href}
            <Link
                href={segment.href}
                class={segmentClass(active)}
                aria-current={active ? 'page' : undefined}
                preserveScroll
            >
                {segment.label}
            </Link>
        {:else}
            <button
                type="button"
                class={segmentClass(active)}
                aria-pressed={active}
                onclick={() => onSelect?.(segment.value)}
            >
                {segment.label}
            </button>
        {/if}
    {/each}
</nav>
