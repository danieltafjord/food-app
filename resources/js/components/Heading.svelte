<script lang="ts">
    let {
        title,
        description = '',
        variant = 'default',
    }: {
        title: string;
        description?: string;
        variant?: 'default' | 'small';
    } = $props();
</script>

<header class={variant === 'small' ? '' : 'mb-10 space-y-1'}>
    <h2
        class={variant === 'small'
            ? 'mb-1 text-base font-semibold tracking-tight'
            : 'text-2xl font-semibold tracking-tight'}
    >
        {title}
    </h2>
    {#if description}
        <p class="text-sm text-muted-foreground">{description}</p>
    {/if}
</header>
