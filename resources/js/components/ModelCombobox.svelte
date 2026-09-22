<script lang="ts">
    /**
     * A model picker: a button showing the current choice, which opens a
     * panel with a search box on top and the whole OpenRouter catalogue in a
     * scrollable list below. Typing an id that is not in the catalogue offers
     * it as a custom entry.
     */
    import Check from 'lucide-svelte/icons/check';
    import ChevronsUpDown from 'lucide-svelte/icons/chevrons-up-down';
    import Search from 'lucide-svelte/icons/search';
    import { tick } from 'svelte';
    import { cn } from '@/lib/utils';

    export type ModelOption = {
        id: string;
        name: string;
        context_length: number | null;
        prompt_price: number | null;
        completion_price: number | null;
        supports_reasoning: boolean;
    };

    let {
        id,
        value = $bindable(''),
        models,
        placeholder = 'Choose a model',
        disabled = false,
    }: {
        id: string;
        value: string;
        /** Undefined while the catalogue is still loading. */
        models: ModelOption[] | undefined;
        placeholder?: string;
        disabled?: boolean;
    } = $props();

    let open = $state(false);
    let query = $state('');
    let highlighted = $state(0);
    let listElement = $state<HTMLUListElement | null>(null);
    let searchElement = $state<HTMLInputElement | null>(null);
    let triggerElement = $state<HTMLButtonElement | null>(null);

    /** Viewport coordinates for the panel, so no ancestor can clip it. */
    let panelStyle = $state('');
    const panelMaxHeight = 400;
    const viewportGutter = 8;

    function positionPanel() {
        if (!triggerElement) {
            return;
        }

        const rect = triggerElement.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom - viewportGutter;
        const spaceAbove = rect.top - viewportGutter;
        const openUpward =
            spaceBelow < Math.min(panelMaxHeight, 240) &&
            spaceAbove > spaceBelow;
        const maxHeight = Math.min(
            panelMaxHeight,
            openUpward ? spaceAbove : spaceBelow,
        );

        panelStyle = openUpward
            ? `left:${rect.left}px;width:${rect.width}px;bottom:${window.innerHeight - rect.top + 4}px;max-height:${maxHeight}px`
            : `left:${rect.left}px;width:${rect.width}px;top:${rect.bottom + 4}px;max-height:${maxHeight}px`;
    }

    $effect(() => {
        if (!open) {
            return;
        }

        positionPanel();
        window.addEventListener('scroll', positionPanel, true);
        window.addEventListener('resize', positionPanel);

        return () => {
            window.removeEventListener('scroll', positionPanel, true);
            window.removeEventListener('resize', positionPanel);
        };
    });

    const filtered = $derived.by(() => {
        if (!models) {
            return [];
        }

        const needle = query.trim().toLowerCase();

        if (needle === '') {
            return models;
        }

        const terms = needle.split(/\s+/);
        const matches = models.filter((model) => {
            const haystack = `${model.id} ${model.name}`.toLowerCase();

            return terms.every((term) => haystack.includes(term));
        });
        // Exact id and id-prefix matches float to the top.
        matches.sort((a, b) => rank(a, needle) - rank(b, needle));

        return matches;
    });

    type ProviderGroup = {
        key: string;
        label: string;
        /** Each entry keeps its index in `filtered`, which is the keyboard order. */
        models: { model: ModelOption; index: number }[];
    };

    /**
     * OpenRouter names models "Provider: Model"; the id is "provider/slug".
     * The name gives a readable label, the id a stable key.
     */
    function providerOf(model: ModelOption): { key: string; label: string } {
        const key = model.id.includes('/') ? model.id.split('/')[0] : 'other';
        const separator = model.name.indexOf(': ');
        const label =
            separator > 0
                ? model.name.slice(0, separator)
                : key === 'other'
                  ? 'Other'
                  : key;

        return { key, label };
    }

    function shortName(model: ModelOption): string {
        const separator = model.name.indexOf(': ');

        return separator > 0 ? model.name.slice(separator + 2) : model.name;
    }

    const groups = $derived.by<ProviderGroup[]>(() => {
        const byKey: Record<string, ProviderGroup> = {};

        filtered.forEach((model, index) => {
            const provider = providerOf(model);
            let group = byKey[provider.key];

            if (!group) {
                group = { ...provider, models: [] };
                byKey[provider.key] = group;
            }

            group.models.push({ model, index });
        });

        const result = Object.values(byKey);

        // Browsing the full list reads best alphabetically; while searching,
        // keep the ranked order so the best match stays on top.
        if (query.trim() === '') {
            result.sort((a, b) =>
                a.label.localeCompare(b.label, undefined, {
                    sensitivity: 'base',
                }),
            );
        }

        return result;
    });

    const selected = $derived(models?.find((model) => model.id === value));
    const isCustom = $derived(
        value !== '' && models !== undefined && selected === undefined,
    );

    /** Offer the typed text as a custom id when it is not an exact catalogue id. */
    const customCandidate = $derived.by(() => {
        const typed = query.trim();

        if (typed === '' || typed.includes(' ')) {
            return null;
        }

        return models?.some((model) => model.id === typed) ? null : typed;
    });

    /** Rows in keyboard order: catalogue matches first, custom id last. */
    const rowCount = $derived(
        filtered.length + (customCandidate === null ? 0 : 1),
    );

    function rank(model: ModelOption, needle: string): number {
        const modelId = model.id.toLowerCase();

        if (modelId === needle) {
            return 0;
        }

        if (modelId.startsWith(needle)) {
            return 1;
        }

        if (model.name.toLowerCase().startsWith(needle)) {
            return 2;
        }

        return 3;
    }

    function formatPrice(price: number | null): string {
        if (price === null) {
            return '–';
        }

        if (price === 0) {
            return 'free';
        }

        return `$${price < 1 ? price.toFixed(3).replace(/0+$/, '').replace(/\.$/, '') : price.toFixed(2)}`;
    }

    function formatContext(length: number | null): string {
        if (length === null) {
            return '';
        }

        return length >= 1_000_000
            ? `${(length / 1_000_000).toFixed(1).replace(/\.0$/, '')}M ctx`
            : `${Math.round(length / 1000)}k ctx`;
    }

    async function openPanel() {
        if (disabled || open) {
            return;
        }

        query = '';
        open = true;
        highlighted = Math.max(
            0,
            models?.findIndex((model) => model.id === value) ?? 0,
        );

        await tick();
        searchElement?.focus();
        scrollHighlightedIntoView('center');
    }

    function close() {
        open = false;
    }

    function chooseRow(index: number) {
        if (index < filtered.length) {
            value = filtered[index].id;
        } else if (customCandidate !== null) {
            value = customCandidate;
        }

        close();
    }

    function scrollHighlightedIntoView(
        block: ScrollLogicalPosition = 'nearest',
    ) {
        document
            .getElementById(`${id}-option-${highlighted}`)
            ?.scrollIntoView({ block });
    }

    function onTriggerKeydown(event: KeyboardEvent) {
        if (
            event.key === 'ArrowDown' ||
            event.key === 'Enter' ||
            event.key === ' '
        ) {
            event.preventDefault();
            openPanel();
        }
    }

    function onSearchKeydown(event: KeyboardEvent) {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                highlighted = Math.min(highlighted + 1, rowCount - 1);
                scrollHighlightedIntoView();
                break;
            case 'ArrowUp':
                event.preventDefault();
                highlighted = Math.max(highlighted - 1, 0);
                scrollHighlightedIntoView();
                break;
            case 'Home':
                event.preventDefault();
                highlighted = 0;
                scrollHighlightedIntoView();
                break;
            case 'End':
                event.preventDefault();
                highlighted = Math.max(0, rowCount - 1);
                scrollHighlightedIntoView();
                break;
            case 'Enter':
                event.preventDefault();

                if (rowCount > 0) {
                    chooseRow(highlighted);
                }

                break;
            case 'Escape':
            case 'Tab':
                close();
                break;
        }
    }

    function onInput() {
        highlighted = 0;
        listElement?.scrollTo({ top: 0 });
    }

    function onWindowPointerDown(event: PointerEvent) {
        const target = event.target as HTMLElement | null;

        if (open && !target?.closest(`[data-model-picker="${id}"]`)) {
            close();
        }
    }
</script>

<svelte:window onpointerdown={onWindowPointerDown} />

<div class="relative" data-model-picker={id}>
    <button
        {id}
        type="button"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls="{id}-listbox"
        {disabled}
        bind:this={triggerElement}
        onclick={openPanel}
        onkeydown={onTriggerKeydown}
        class={cn(
            'flex h-auto min-h-12 w-full items-center justify-between gap-2 rounded-2xl border border-input bg-background px-4 py-2 text-left text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50',
            open && 'ring-2 ring-ring ring-offset-2',
        )}
    >
        {#if value === ''}
            <span class="text-muted-foreground">{placeholder}</span>
        {:else}
            <span class="flex min-w-0 flex-col">
                <span class="truncate font-mono text-sm">{value}</span>
                {#if selected}
                    <span class="truncate text-xs text-muted-foreground"
                        >{selected.name}</span
                    >
                {:else if isCustom}
                    <span class="truncate text-xs text-muted-foreground"
                        >Custom id, not in the OpenRouter catalogue</span
                    >
                {/if}
            </span>
        {/if}
        <ChevronsUpDown class="size-4 shrink-0 text-muted-foreground" />
    </button>

    {#if open}
        <div
            class="fixed z-50 flex flex-col overflow-hidden rounded-2xl border bg-popover text-popover-foreground shadow-lg"
            style={panelStyle}
        >
            <div class="relative shrink-0 border-b">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    type="text"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="{id}-listbox"
                    aria-autocomplete="list"
                    aria-activedescendant={rowCount > 0
                        ? `${id}-option-${highlighted}`
                        : undefined}
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="Search by name or id"
                    bind:this={searchElement}
                    bind:value={query}
                    oninput={onInput}
                    onkeydown={onSearchKeydown}
                    class="h-11 w-full bg-transparent pr-3 pl-10 text-sm outline-none placeholder:text-muted-foreground"
                />
            </div>

            {#if models === undefined}
                <p class="px-3 py-6 text-center text-sm text-muted-foreground">
                    Loading the OpenRouter catalogue…
                </p>
            {:else if rowCount === 0}
                <p class="px-3 py-6 text-center text-sm text-muted-foreground">
                    {#if models.length === 0}
                        The catalogue could not be loaded. Type a model id to
                        use it anyway.
                    {:else}
                        No models match. Type a full model id to use a custom
                        one.
                    {/if}
                </p>
            {:else}
                <ul
                    id="{id}-listbox"
                    role="listbox"
                    aria-label="Models"
                    bind:this={listElement}
                    class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-1.5"
                >
                    {#each groups as group (group.key)}
                        <li role="presentation">
                            <p
                                class="sticky top-0 z-10 bg-popover px-2 pt-2 pb-1 text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase"
                            >
                                {group.label}
                            </p>
                            <ul role="group" aria-label={group.label}>
                                {#each group.models as { model, index } (model.id)}
                                    <li
                                        id="{id}-option-{index}"
                                        role="option"
                                        aria-selected={model.id === value}
                                        class={cn(
                                            'flex cursor-default items-center gap-2 rounded-xl px-2.5 py-2 text-sm select-none',
                                            index === highlighted &&
                                                'bg-accent text-accent-foreground',
                                        )}
                                        onpointermove={() =>
                                            (highlighted = index)}
                                        onpointerdown={(event) => {
                                            event.preventDefault();
                                            chooseRow(index);
                                        }}
                                    >
                                        <span
                                            class="flex size-4 shrink-0 items-center"
                                        >
                                            {#if model.id === value}
                                                <Check class="size-4" />
                                            {/if}
                                        </span>
                                        <span
                                            class="flex min-w-0 flex-1 flex-col"
                                        >
                                            <span class="truncate"
                                                >{shortName(model)}</span
                                            >
                                            <span
                                                class="truncate font-mono text-xs text-muted-foreground"
                                                >{model.id}</span
                                            >
                                        </span>
                                        <span
                                            class="hidden shrink-0 flex-col items-end text-xs text-muted-foreground sm:flex"
                                        >
                                            <span
                                                >{formatPrice(
                                                    model.prompt_price,
                                                )} / {formatPrice(
                                                    model.completion_price,
                                                )} per M</span
                                            >
                                            <span
                                                >{formatContext(
                                                    model.context_length,
                                                )}{model.supports_reasoning
                                                    ? ' · reasoning'
                                                    : ''}</span
                                            >
                                        </span>
                                    </li>
                                {/each}
                            </ul>
                        </li>
                    {/each}
                    {#if customCandidate !== null}
                        {@const index = filtered.length}
                        <li
                            id="{id}-option-{index}"
                            role="option"
                            aria-selected={customCandidate === value}
                            class={cn(
                                'flex cursor-default items-center gap-2 rounded-xl px-2.5 py-2 text-sm select-none',
                                filtered.length > 0 && 'mt-1 border-t pt-2',
                                index === highlighted &&
                                    'bg-accent text-accent-foreground',
                            )}
                            onpointermove={() => (highlighted = index)}
                            onpointerdown={(event) => {
                                event.preventDefault();
                                chooseRow(index);
                            }}
                        >
                            <span class="flex size-4 shrink-0 items-center">
                                {#if customCandidate === value}
                                    <Check class="size-4" />
                                {/if}
                            </span>
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="truncate">Use custom id</span>
                                <span
                                    class="truncate font-mono text-xs text-muted-foreground"
                                    >{customCandidate}</span
                                >
                            </span>
                        </li>
                    {/if}
                </ul>
            {/if}

            {#if models !== undefined && models.length > 0}
                <p
                    class="shrink-0 border-t px-3 py-1.5 text-xs text-muted-foreground tabular-nums"
                >
                    {#if query.trim() === ''}
                        {models.length.toLocaleString()} models from {groups.length.toLocaleString()}
                        providers
                    {:else}
                        {filtered.length.toLocaleString()} of {models.length.toLocaleString()}
                        models
                    {/if}
                </p>
            {/if}
        </div>
    {/if}
</div>
