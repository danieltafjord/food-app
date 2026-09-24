<script lang="ts">
    /**
     * The footer under every paginated admin table: the "x–y of z" line, a
     * page-size picker and first / previous / numbered / next / last links.
     */
    import { Link } from '@inertiajs/svelte';
    import ChevronLeft from 'lucide-svelte/icons/chevron-left';
    import ChevronRight from 'lucide-svelte/icons/chevron-right';
    import ChevronsLeft from 'lucide-svelte/icons/chevrons-left';
    import ChevronsRight from 'lucide-svelte/icons/chevrons-right';
    import { cn } from '@/lib/utils';

    export type Paginator = {
        total: number;
        from: number | null;
        to: number | null;
        current_page: number;
        last_page: number;
        per_page: number;
        first_page_url: string;
        last_page_url: string;
        prev_page_url: string | null;
        next_page_url: string | null;
        links: { url: string | null; label: string; active: boolean }[];
    };

    let {
        paginator,
        pageSizes = [],
        onPageSize,
        only,
    }: {
        paginator: Paginator;
        /** When given with onPageSize, shows the rows-per-page picker. */
        pageSizes?: number[];
        onPageSize?: (perPage: number) => void;
        /** Props a page change reloads; the rest of the page stays as it is. */
        only?: string[];
    } = $props();

    /**
     * Page numbers to show: the first, the last, and a window around the
     * current page, with `null` where pages are skipped.
     */
    const pages = $derived.by((): (number | null)[] => {
        const last = paginator.last_page;
        const current = paginator.current_page;

        if (last <= 7) {
            return Array.from({ length: last }, (_, index) => index + 1);
        }

        const shown = [1, last];

        for (let page = current - 1; page <= current + 1; page++) {
            if (page > 1 && page < last) {
                shown.push(page);
            }
        }

        const ordered = shown.sort((a, b) => a - b);
        const result: (number | null)[] = [];

        ordered.forEach((page, index) => {
            if (index > 0 && page - (ordered[index - 1] as number) > 1) {
                result.push(null);
            }

            result.push(page);
        });

        return result;
    });

    const pageUrl = (page: number): string =>
        paginator.links.find((link) => link.label === String(page))?.url ?? '#';

    const buttonClass =
        'inline-flex size-8 items-center justify-center rounded-full text-xs font-medium tabular-nums transition-colors';
    const enabledClass =
        'text-muted-foreground hover:bg-muted hover:text-foreground';
    const disabledClass = 'pointer-events-none text-muted-foreground/40';
</script>

<div class="flex flex-wrap items-center gap-x-4 gap-y-2">
    <p class="text-xs text-muted-foreground tabular-nums">
        Showing {paginator.from ?? 0}–{paginator.to ?? 0} of {paginator.total.toLocaleString()}
    </p>
    {#if pageSizes.length > 0 && onPageSize}
        <label class="flex items-center gap-2 text-xs text-muted-foreground">
            Rows per page
            <select
                class="h-8 rounded-full border border-border/80 bg-panel pr-7 pl-3 text-xs font-medium text-foreground shadow-none"
                value={String(paginator.per_page)}
                onchange={(event) =>
                    onPageSize(Number(event.currentTarget.value))}
            >
                {#each pageSizes as size (size)}
                    <option value={String(size)}>{size}</option>
                {/each}
            </select>
        </label>
    {/if}
</div>

{#if paginator.last_page > 1}
    <nav class="flex items-center gap-0.5" aria-label="Pagination">
        <Link
            href={paginator.first_page_url}
            class={cn(
                buttonClass,
                paginator.current_page === 1 ? disabledClass : enabledClass,
            )}
            aria-label="First page"
            preserveScroll
            {only}
        >
            <ChevronsLeft class="size-4" />
        </Link>
        <Link
            href={paginator.prev_page_url ?? '#'}
            class={cn(
                buttonClass,
                paginator.prev_page_url ? enabledClass : disabledClass,
            )}
            aria-label="Previous page"
            preserveScroll
            {only}
        >
            <ChevronLeft class="size-4" />
        </Link>
        {#each pages as page, index (page ?? `gap-${index}`)}
            {#if page === null}
                <span
                    class="inline-flex size-8 items-center justify-center text-xs text-muted-foreground/60"
                    aria-hidden="true">…</span
                >
            {:else if page === paginator.current_page}
                <span
                    class={cn(buttonClass, 'bg-foreground text-background')}
                    aria-current="page">{page}</span
                >
            {:else}
                <Link
                    href={pageUrl(page)}
                    class={cn(buttonClass, enabledClass)}
                    aria-label="Page {page}"
                    preserveScroll
                    {only}>{page}</Link
                >
            {/if}
        {/each}
        <Link
            href={paginator.next_page_url ?? '#'}
            class={cn(
                buttonClass,
                paginator.next_page_url ? enabledClass : disabledClass,
            )}
            aria-label="Next page"
            preserveScroll
            {only}
        >
            <ChevronRight class="size-4" />
        </Link>
        <Link
            href={paginator.last_page_url}
            class={cn(
                buttonClass,
                paginator.current_page === paginator.last_page
                    ? disabledClass
                    : enabledClass,
            )}
            aria-label="Last page"
            preserveScroll
            {only}
        >
            <ChevronsRight class="size-4" />
        </Link>
    </nav>
{/if}
