<script lang="ts">
    /**
     * A scrollable, wrapped block for request and response payloads. Objects
     * are pretty-printed as JSON; strings that happen to be JSON are
     * re-indented so they read the same way.
     */
    let {
        value,
        empty = 'Nothing recorded.',
        tone = 'default',
    }: {
        value: unknown;
        empty?: string;
        tone?: 'default' | 'danger';
    } = $props();

    const text = $derived.by(() => {
        if (value === null || value === undefined || value === '') {
            return null;
        }

        if (typeof value === 'string') {
            try {
                return JSON.stringify(JSON.parse(value), null, 2);
            } catch {
                return value;
            }
        }

        return JSON.stringify(value, null, 2);
    });
</script>

{#if text === null}
    <p class="text-sm text-muted-foreground">{empty}</p>
{:else}
    <pre
        class="max-h-[32rem] overflow-auto rounded-2xl border border-border/80 bg-canvas px-4 py-3 font-mono text-xs leading-relaxed break-all whitespace-pre-wrap {tone ===
        'danger'
            ? 'text-red-700 dark:text-red-300'
            : 'text-foreground'}">{text}</pre>
{/if}
