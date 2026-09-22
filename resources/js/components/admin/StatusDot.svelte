<script lang="ts">
    import type { Snippet } from 'svelte';

    let {
        tone = 'neutral',
        children,
    }: {
        tone?: 'ok' | 'warning' | 'danger' | 'neutral';
        children?: Snippet;
    } = $props();

    const dotClass: Record<NonNullable<typeof tone>, string> = {
        ok: 'bg-emerald-500',
        warning: 'bg-amber-500',
        danger: 'bg-red-500',
        neutral: 'bg-muted-foreground/50',
    };
</script>

<span class="inline-flex items-center gap-1.5 text-sm whitespace-nowrap">
    <span class="size-1.5 shrink-0 rounded-full {dotClass[tone]}"></span>
    {@render children?.()}
</span>
