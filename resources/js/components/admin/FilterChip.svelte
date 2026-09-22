<script lang="ts">
    /**
     * A removable pill showing a filter that came from a link (a user or
     * household), so the admin can see and drop it.
     */
    import X from 'lucide-svelte/icons/x';

    let {
        label,
        value,
        onRemove,
    }: {
        label: string;
        value: string;
        onRemove: () => void;
    } = $props();
</script>

<button
    type="button"
    class="inline-flex h-8 items-center gap-1.5 rounded-full border border-border/80 bg-panel pr-2.5 pl-3 text-xs font-medium transition-colors hover:bg-muted"
    onclick={onRemove}
    aria-label="Stop filtering by {label.toLowerCase()} {value}"
>
    <span class="text-muted-foreground">{label}:</span>
    <span class="max-w-40 truncate">{value}</span>
    <X class="size-3.5 text-muted-foreground" />
</button>
