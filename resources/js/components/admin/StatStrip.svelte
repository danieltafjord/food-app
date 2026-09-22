<script lang="ts">
    /**
     * A row of softly rounded stat tiles, each carrying one figure and a
     * one-line hint so the numbers read as a calm set of facts.
     */
    export type Stat = {
        label: string;
        value: string;
        hint?: string;
        tone?: 'default' | 'danger' | 'warning';
    };

    let {
        stats,
        columns = 6,
    }: {
        stats: Stat[];
        columns?: 3 | 4 | 6;
    } = $props();

    const columnClass: Record<3 | 4 | 6, string> = {
        3: 'sm:grid-cols-3',
        4: 'sm:grid-cols-2 lg:grid-cols-4',
        6: 'sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6',
    };

    const toneClass: Record<NonNullable<Stat['tone']>, string> = {
        default: '',
        danger: 'text-red-600 dark:text-red-400',
        warning: 'text-amber-600 dark:text-amber-400',
    };
</script>

<dl class="grid gap-3 {columnClass[columns]}">
    {#each stats as stat (stat.label)}
        <div class="rounded-2xl border border-border/80 bg-panel px-5 py-4">
            <dt class="text-xs font-medium text-muted-foreground">
                {stat.label}
            </dt>
            <dd
                class="mt-2 text-2xl font-semibold tracking-tight tabular-nums {toneClass[
                    stat.tone ?? 'default'
                ]}"
            >
                {stat.value}
            </dd>
            {#if stat.hint}
                <dd class="mt-1 text-xs text-muted-foreground">{stat.hint}</dd>
            {/if}
        </div>
    {/each}
</dl>
