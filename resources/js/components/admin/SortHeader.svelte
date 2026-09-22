<script lang="ts">
    /**
     * A column header that sorts the table. Shows the current direction on
     * the active column and a faint hint on the others so sortable columns
     * are discoverable.
     */
    import ArrowDown from 'lucide-svelte/icons/arrow-down';
    import ArrowUp from 'lucide-svelte/icons/arrow-up';
    import ChevronsUpDown from 'lucide-svelte/icons/chevrons-up-down';
    import { thClass } from '@/components/admin/table';
    import { cn } from '@/lib/utils';

    export type SortDirection = 'asc' | 'desc';

    let {
        column,
        label,
        sort,
        direction,
        align = 'left',
        class: className = '',
        onSort,
    }: {
        column: string;
        label: string;
        /** The column the table is currently sorted by. */
        sort: string;
        direction: SortDirection;
        align?: 'left' | 'right';
        class?: string;
        onSort: (column: string) => void;
    } = $props();

    const active = $derived(sort === column);
</script>

<th
    class={cn(thClass, align === 'right' && 'text-right', className)}
    aria-sort={active
        ? direction === 'asc'
            ? 'ascending'
            : 'descending'
        : 'none'}
>
    <button
        type="button"
        class={cn(
            'group inline-flex items-center gap-1 rounded-sm uppercase transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
            active && 'text-foreground',
            align === 'right' && 'flex-row-reverse',
        )}
        onclick={() => onSort(column)}
    >
        {label}
        {#if active}
            {#if direction === 'asc'}
                <ArrowUp class="size-3" />
            {:else}
                <ArrowDown class="size-3" />
            {/if}
        {:else}
            <ChevronsUpDown
                class="size-3 opacity-0 transition-opacity group-hover:opacity-60"
            />
        {/if}
    </button>
</th>
