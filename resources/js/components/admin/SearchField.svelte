<script lang="ts">
    /**
     * The debounced search box at the head of every admin list.
     */
    import Search from 'lucide-svelte/icons/search';
    import { onDestroy } from 'svelte';
    import { Input } from '@/components/ui/input';
    import { cn } from '@/lib/utils';

    let {
        value = $bindable(''),
        placeholder,
        label,
        class: className = '',
        onSearch,
    }: {
        value?: string;
        placeholder: string;
        label: string;
        class?: string;
        onSearch: (value: string) => void;
    } = $props();

    let timer: ReturnType<typeof setTimeout> | undefined;

    function onInput() {
        clearTimeout(timer);
        timer = setTimeout(() => onSearch(value), 300);
    }

    // A search typed just before leaving the page must not navigate back to it.
    onDestroy(() => clearTimeout(timer));
</script>

<div class={cn('relative w-full sm:w-72', className)}>
    <Search
        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
    />
    <Input
        type="search"
        bind:value
        oninput={onInput}
        {placeholder}
        class="h-9 rounded-full bg-panel pr-4 pl-10 shadow-none"
        aria-label={label}
    />
</div>
