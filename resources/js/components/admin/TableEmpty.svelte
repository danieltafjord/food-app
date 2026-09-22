<script lang="ts">
    /**
     * The empty state row every admin table shows when the current filters
     * match nothing, with a one-click way back to the unfiltered list.
     */
    import SearchX from 'lucide-svelte/icons/search-x';
    import { Button } from '@/components/ui/button';

    let {
        colspan,
        message,
        filtered = false,
        onClear,
    }: {
        colspan: number;
        message: string;
        /** Whether any filter is active, which enables the clear button. */
        filtered?: boolean;
        onClear?: () => void;
    } = $props();
</script>

<tr>
    <td {colspan} class="px-5 py-16">
        <div class="flex flex-col items-center gap-3 text-center">
            <span
                class="flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground"
            >
                <SearchX class="size-5" />
            </span>
            <p class="text-sm text-muted-foreground">{message}</p>
            {#if filtered && onClear}
                <Button
                    variant="outline"
                    size="sm"
                    class="rounded-full px-4 shadow-none"
                    onclick={onClear}
                >
                    Clear filters
                </Button>
            {/if}
        </div>
    </td>
</tr>
