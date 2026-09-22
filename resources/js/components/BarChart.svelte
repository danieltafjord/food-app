<script lang="ts">
    /**
     * A small stacked bar chart over a list of days. Series colours are fixed
     * per entity (never cycled), text uses the theme's ink tokens, and a
     * legend plus hover tooltip keep every value readable without colour.
     */
    export type ChartSeries = {
        key: string;
        label: string;
        values: number[];
    };

    let {
        labels,
        series,
        title,
        valueLabel = 'requests',
        height = 180,
    }: {
        /** ISO dates, one per column. */
        labels: string[];
        /** At most three series; each `values` array matches `labels`. */
        series: ChartSeries[];
        title: string;
        valueLabel?: string;
        height?: number;
    } = $props();

    const width = 720;
    const padding = { top: 12, right: 8, bottom: 24, left: 36 };
    const gap = 2;

    const plotWidth = width - padding.left - padding.right;
    const plotHeight = $derived(height - padding.top - padding.bottom);

    const totals = $derived(
        labels.map((_, index) =>
            series.reduce((sum, s) => sum + (s.values[index] ?? 0), 0),
        ),
    );
    const max = $derived(Math.max(1, ...totals));
    const ticks = $derived(niceTicks(max));
    const scaledMax = $derived(ticks[ticks.length - 1] ?? max);
    const columnWidth = $derived(plotWidth / Math.max(1, labels.length));
    const barWidth = $derived(Math.max(2, columnWidth - gap));
    const grandTotal = $derived(totals.reduce((sum, value) => sum + value, 0));

    let hovered = $state<number | null>(null);

    function niceTicks(value: number): number[] {
        const rough = value / 3;
        const magnitude = 10 ** Math.floor(Math.log10(rough));
        const candidates = [1, 2, 5, 10].map((step) => step * magnitude);
        const step =
            candidates.find((candidate) => candidate >= rough) ??
            candidates[candidates.length - 1];
        const top = Math.ceil(value / step) * step;
        const result: number[] = [];

        for (let tick = 0; tick <= top; tick += step) {
            result.push(tick);
        }

        return result;
    }

    function y(value: number): number {
        return padding.top + plotHeight - (value / scaledMax) * plotHeight;
    }

    function shortDate(day: string): string {
        const date = new Date(`${day}T00:00:00Z`);

        return date.toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
            timeZone: 'UTC',
        });
    }

    const labelEvery = $derived(
        labels.length > 45 ? 14 : labels.length > 14 ? 7 : 1,
    );
</script>

<figure class="chart space-y-2">
    <figcaption class="flex flex-wrap items-baseline justify-between gap-2">
        <span class="text-sm font-medium">{title}</span>
        <span class="text-xs text-muted-foreground"
            >{grandTotal.toLocaleString()} {valueLabel} in total</span
        >
    </figcaption>

    {#if series.length > 1}
        <ul
            class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            {#each series as s, index (s.key)}
                <li class="flex items-center gap-1.5">
                    <span
                        class="inline-block size-2.5 rounded-sm"
                        style="background: var(--series-{index + 1})"
                    ></span>
                    {s.label}
                </li>
            {/each}
        </ul>
    {/if}

    <div class="relative">
        <svg
            viewBox="0 0 {width} {height}"
            class="h-auto w-full"
            role="img"
            aria-label={title}
            onpointerleave={() => (hovered = null)}
        >
            {#each ticks as tick (tick)}
                <line
                    x1={padding.left}
                    x2={width - padding.right}
                    y1={y(tick)}
                    y2={y(tick)}
                    class="grid"
                />
                <text
                    x={padding.left - 6}
                    y={y(tick)}
                    text-anchor="end"
                    dominant-baseline="middle"
                    class="axis">{tick.toLocaleString()}</text
                >
            {/each}

            {#each labels as day, index (day)}
                {@const x = padding.left + index * columnWidth + gap / 2}
                {@const stackBottom = padding.top + plotHeight}
                <g onpointerenter={() => (hovered = index)} role="presentation">
                    <rect
                        {x}
                        y={padding.top}
                        width={barWidth}
                        height={plotHeight}
                        fill="transparent"
                    />
                    {#each series as s, seriesIndex (s.key)}
                        {@const value = s.values[index] ?? 0}
                        {@const below = series
                            .slice(0, seriesIndex)
                            .reduce(
                                (sum, prev) => sum + (prev.values[index] ?? 0),
                                0,
                            )}
                        {#if value > 0}
                            {@const top = y(below + value)}
                            {@const bottom = y(below)}
                            <rect
                                {x}
                                y={top}
                                width={barWidth}
                                height={Math.max(
                                    1,
                                    bottom - top - (seriesIndex > 0 ? 2 : 0),
                                )}
                                rx={below + value === totals[index] ? 4 : 0}
                                style="fill: var(--series-{seriesIndex + 1})"
                                opacity={hovered === null || hovered === index
                                    ? 1
                                    : 0.55}
                            />
                        {/if}
                    {/each}
                    {#if totals[index] === 0}
                        <rect
                            {x}
                            y={stackBottom - 1}
                            width={barWidth}
                            height="1"
                            class="empty"
                        />
                    {/if}
                </g>
                {#if index % labelEvery === 0}
                    <text
                        x={x + barWidth / 2}
                        y={height - 6}
                        text-anchor="middle"
                        class="axis">{shortDate(day)}</text
                    >
                {/if}
            {/each}
        </svg>

        {#if hovered !== null}
            {@const left =
                ((padding.left + hovered * columnWidth + columnWidth / 2) /
                    width) *
                100}
            <div
                class="pointer-events-none absolute top-2 z-10 min-w-32 rounded-xl border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md"
                style="left: {left}%; transform: translateX({left > 70
                    ? '-100%'
                    : left < 30
                      ? '0'
                      : '-50%'})"
            >
                <p class="font-medium">{shortDate(labels[hovered])}</p>
                {#each series as s, index (s.key)}
                    <p class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-1.5">
                            <span
                                class="inline-block size-2 rounded-sm"
                                style="background: var(--series-{index + 1})"
                            ></span>
                            {s.label}
                        </span>
                        <span class="tabular-nums"
                            >{(s.values[hovered] ?? 0).toLocaleString()}</span
                        >
                    </p>
                {/each}
                {#if series.length > 1}
                    <p
                        class="mt-1 flex justify-between gap-3 border-t pt-1 text-muted-foreground"
                    >
                        <span>Total</span>
                        <span class="tabular-nums"
                            >{totals[hovered].toLocaleString()}</span
                        >
                    </p>
                {/if}
            </div>
        {/if}
    </div>
</figure>

<style>
    .chart {
        /* Green for the good path, neutral for cache hits, red for failures. */
        --series-1: #0f9d7a;
        --series-2: #a3a3a3;
        --series-3: #e5484d;
    }
    :global(.dark) .chart {
        --series-1: #1fb38a;
        --series-2: #6b6b6b;
        --series-3: #f0565b;
    }
    .grid {
        stroke: var(--border);
        stroke-width: 1;
    }
    .axis {
        fill: var(--muted-foreground);
        font-size: 10px;
    }
    .empty {
        fill: var(--border);
    }
</style>
