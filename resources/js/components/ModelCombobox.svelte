<script lang="ts">
    /**
     * A model picker: a button showing the current choice, which opens a
     * panel with a search box, quick filters and the whole OpenRouter
     * catalogue grouped by provider. Typing an id that is not in the
     * catalogue offers it as a custom entry.
     */
    import Brain from 'lucide-svelte/icons/brain';
    import Check from 'lucide-svelte/icons/check';
    import ChevronsUpDown from 'lucide-svelte/icons/chevrons-up-down';
    import RefreshCw from 'lucide-svelte/icons/refresh-cw';
    import Search from 'lucide-svelte/icons/search';
    import X from 'lucide-svelte/icons/x';
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
        pinned = [],
        pinnedLabel = 'In use elsewhere',
        placeholder = 'Choose a model',
        disabled = false,
        catalogue,
        refreshing = false,
        onRefresh,
    }: {
        id: string;
        value: string;
        /** Undefined while the catalogue is still loading. */
        models: ModelOption[] | undefined;
        /** Ids shown in their own group at the top while browsing. */
        pinned?: string[];
        pinnedLabel?: string;
        placeholder?: string;
        disabled?: boolean;
        /** When the catalogue was fetched and whether that fetch failed. */
        catalogue?: { fetched_at: string | null; failed: boolean };
        refreshing?: boolean;
        onRefresh?: () => void;
    } = $props();

    let open = $state(false);
    let query = $state('');
    let reasoningOnly = $state(false);
    let freeOnly = $state(false);
    let highlighted = $state(0);
    let listElement = $state<HTMLUListElement | null>(null);
    let searchElement = $state<HTMLInputElement | null>(null);
    let triggerElement = $state<HTMLButtonElement | null>(null);

    /** Viewport coordinates for the panel, so no ancestor can clip it. */
    let panelStyle = $state('');
    const panelMaxHeight = 460;
    const panelMinWidth = 560;
    const viewportGutter = 8;

    function positionPanel() {
        if (!triggerElement) {
            return;
        }

        const rect = triggerElement.getBoundingClientRect();
        const width = Math.min(
            Math.max(rect.width, panelMinWidth),
            window.innerWidth - viewportGutter * 2,
        );
        const left = Math.min(
            Math.max(rect.left, viewportGutter),
            window.innerWidth - width - viewportGutter,
        );
        const spaceBelow = window.innerHeight - rect.bottom - viewportGutter;
        const spaceAbove = rect.top - viewportGutter;
        const openUpward =
            spaceBelow < Math.min(panelMaxHeight, 280) &&
            spaceAbove > spaceBelow;
        const maxHeight = Math.min(
            panelMaxHeight,
            openUpward ? spaceAbove : spaceBelow,
        );

        panelStyle = openUpward
            ? `left:${left}px;width:${width}px;bottom:${window.innerHeight - rect.top + 6}px;max-height:${maxHeight}px`
            : `left:${left}px;width:${width}px;top:${rect.bottom + 6}px;max-height:${maxHeight}px`;
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

    const needle = $derived(query.trim().toLowerCase());
    const terms = $derived(needle === '' ? [] : needle.split(/\s+/));
    const browsing = $derived(needle === '' && !reasoningOnly && !freeOnly);

    const filtered = $derived.by(() => {
        if (!models) {
            return [];
        }

        const matches = models.filter((model) => {
            if (reasoningOnly && !model.supports_reasoning) {
                return false;
            }

            if (freeOnly && !isFree(model)) {
                return false;
            }

            if (terms.length === 0) {
                return true;
            }

            const haystack = `${model.id} ${model.name}`.toLowerCase();

            return terms.every((term) => haystack.includes(term));
        });

        if (needle !== '') {
            // Exact id and id-prefix matches float to the top.
            matches.sort((a, b) => rank(a, needle) - rank(b, needle));
        }

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
        const pinnedGroup: ProviderGroup = {
            key: '~pinned',
            label: pinnedLabel,
            models: [],
        };

        filtered.forEach((model, index) => {
            if (browsing && pinned.includes(model.id)) {
                pinnedGroup.models.push({ model, index });

                return;
            }

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
        if (needle === '') {
            result.sort((a, b) =>
                a.label.localeCompare(b.label, undefined, {
                    sensitivity: 'base',
                }),
            );
        }

        return pinnedGroup.models.length > 0
            ? [pinnedGroup, ...result]
            : result;
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

    function isFree(model: ModelOption): boolean {
        return model.prompt_price === 0 && model.completion_price === 0;
    }

    function rank(model: ModelOption, needle: string): number {
        const modelId = model.id.toLowerCase();
        const name = model.name.toLowerCase();
        const short = shortName(model).toLowerCase();

        if (modelId === needle) {
            return 0;
        }

        if (modelId.startsWith(needle)) {
            return 1;
        }

        if (short.startsWith(needle) || name.startsWith(needle)) {
            return 2;
        }

        if (modelId.includes(needle)) {
            return 3;
        }

        return 4;
    }

    type Segment = { text: string; hit: boolean };

    /** Split text into plain and matched runs so matches can be emphasised. */
    function segments(text: string): Segment[] {
        if (terms.length === 0) {
            return [{ text, hit: false }];
        }

        const lower = text.toLowerCase();
        const hits = new Array<boolean>(text.length).fill(false);

        for (const term of terms) {
            let from = 0;

            while (from <= lower.length - term.length) {
                const at = lower.indexOf(term, from);

                if (at === -1) {
                    break;
                }

                hits.fill(true, at, at + term.length);
                from = at + term.length;
            }
        }

        const result: Segment[] = [];

        for (let index = 0; index < text.length; index++) {
            const last = result[result.length - 1];

            if (last && last.hit === hits[index]) {
                last.text += text[index];
            } else {
                result.push({ text: text[index], hit: hits[index] });
            }
        }

        return result;
    }

    function timeAgo(value: string | null): string {
        if (!value) {
            return '';
        }

        const minutes = Math.max(
            0,
            Math.round((Date.now() - new Date(value).getTime()) / 60_000),
        );

        if (minutes < 1) {
            return 'just now';
        }

        if (minutes < 60) {
            return `${minutes} min ago`;
        }

        return `${Math.round(minutes / 60)} h ago`;
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

    function priceLabel(model: ModelOption): string {
        if (isFree(model)) {
            return 'Free';
        }

        return `${formatPrice(model.prompt_price)} / ${formatPrice(model.completion_price)} per M`;
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
        reasoningOnly = false;
        freeOnly = false;
        open = true;
        highlighted = Math.max(
            0,
            models?.findIndex((model) => model.id === value) ?? 0,
        );

        await tick();
        searchElement?.focus();
        scrollHighlightedIntoView('center');
    }

    function close(refocus = false) {
        open = false;

        if (refocus) {
            triggerElement?.focus();
        }
    }

    function chooseRow(index: number, refocus = false) {
        if (index < filtered.length) {
            value = filtered[index].id;
        } else if (customCandidate !== null) {
            value = customCandidate;
        }

        close(refocus);
    }

    function scrollHighlightedIntoView(
        block: ScrollLogicalPosition = 'nearest',
    ) {
        document
            .getElementById(`${id}-option-${highlighted}`)
            ?.scrollIntoView({ block });
    }

    function moveHighlight(to: number) {
        highlighted = Math.min(Math.max(to, 0), Math.max(0, rowCount - 1));
        scrollHighlightedIntoView();
    }

    async function resetHighlight() {
        highlighted = 0;
        await tick();
        listElement?.scrollTo({ top: 0 });
    }

    async function toggleFilter(filter: 'reasoning' | 'free') {
        if (filter === 'reasoning') {
            reasoningOnly = !reasoningOnly;
        } else {
            freeOnly = !freeOnly;
        }

        await resetHighlight();
        searchElement?.focus();
    }

    async function clearQuery() {
        query = '';
        await resetHighlight();
        searchElement?.focus();
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
                moveHighlight(highlighted + 1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                moveHighlight(highlighted - 1);
                break;
            case 'PageDown':
                event.preventDefault();
                moveHighlight(highlighted + 8);
                break;
            case 'PageUp':
                event.preventDefault();
                moveHighlight(highlighted - 8);
                break;
            case 'Home':
                event.preventDefault();
                moveHighlight(0);
                break;
            case 'End':
                event.preventDefault();
                moveHighlight(rowCount - 1);
                break;
            case 'Enter':
                event.preventDefault();

                if (rowCount > 0) {
                    chooseRow(highlighted, true);
                }

                break;
            case 'Escape':
                event.preventDefault();
                close(true);
                break;
            case 'Tab':
                close();
                break;
        }
    }

    function onWindowPointerDown(event: PointerEvent) {
        const target = event.target as HTMLElement | null;

        if (open && !target?.closest(`[data-model-picker="${id}"]`)) {
            close();
        }
    }

    const chipClass = (active: boolean) =>
        cn(
            'inline-flex h-7 items-center gap-1 rounded-full border px-2.5 text-xs font-medium transition-colors',
            active
                ? 'border-foreground bg-foreground text-background'
                : 'border-border/80 text-muted-foreground hover:bg-muted hover:text-foreground',
        );
    const rowClass = (active: boolean) =>
        cn(
            'flex cursor-default items-center gap-3 rounded-xl px-2.5 py-2 text-sm select-none',
            active && 'bg-accent text-accent-foreground',
        );
</script>

{#snippet emphasised(text: string)}
    {#each segments(text) as segment, index (index)}
        {#if segment.hit}
            <mark
                class="rounded-sm bg-amber-200/70 text-inherit dark:bg-amber-400/30"
                >{segment.text}</mark
            >
        {:else}
            {segment.text}
        {/if}
    {/each}
{/snippet}

{#snippet meta(model: ModelOption)}
    <span
        class="hidden shrink-0 flex-col items-end gap-0.5 text-xs text-muted-foreground tabular-nums sm:flex"
    >
        <span
            class={cn(
                'whitespace-nowrap',
                isFree(model) && 'text-emerald-700 dark:text-emerald-400',
            )}
        >
            {priceLabel(model)}
        </span>
        <span class="flex items-center gap-1.5 whitespace-nowrap">
            {formatContext(model.context_length)}
            {#if model.supports_reasoning}
                <span
                    class="inline-flex items-center gap-0.5 rounded-full bg-muted px-1.5 py-px text-[10px] font-medium text-foreground/80"
                    title="Supports reasoning"
                >
                    <Brain class="size-2.5" />
                    reasoning
                </span>
            {/if}
        </span>
    </span>
{/snippet}

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
            'flex h-auto min-h-12 w-full items-center justify-between gap-3 rounded-2xl border border-input bg-background px-4 py-2 text-left text-sm transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50',
            open && 'ring-2 ring-ring ring-offset-2',
        )}
    >
        {#if value === ''}
            <span class="text-muted-foreground">{placeholder}</span>
        {:else}
            <span class="flex min-w-0 flex-1 flex-col">
                <span class="flex min-w-0 items-center gap-2">
                    <span class="truncate font-medium">
                        {selected ? shortName(selected) : value}
                    </span>
                    {#if selected}
                        <span class="shrink-0 text-xs text-muted-foreground"
                            >{providerOf(selected).label}</span
                        >
                    {:else if isCustom}
                        <span
                            class="shrink-0 rounded-full border border-amber-500/40 bg-amber-500/10 px-1.5 py-px text-[10px] font-medium text-amber-700 dark:text-amber-400"
                            >Custom id</span
                        >
                    {/if}
                </span>
                <span class="truncate font-mono text-xs text-muted-foreground"
                    >{value}</span
                >
            </span>
            {#if selected}
                {@render meta(selected)}
            {/if}
        {/if}
        <ChevronsUpDown class="size-4 shrink-0 text-muted-foreground" />
    </button>

    {#if open}
        <div
            class="fixed z-50 flex flex-col overflow-hidden rounded-2xl border bg-popover text-popover-foreground shadow-xl"
            style={panelStyle}
        >
            <div class="shrink-0 border-b">
                <div class="relative">
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
                        placeholder="Search by name, provider or id"
                        bind:this={searchElement}
                        bind:value={query}
                        oninput={resetHighlight}
                        onkeydown={onSearchKeydown}
                        class="h-11 w-full bg-transparent pr-10 pl-10 text-sm outline-none placeholder:text-muted-foreground"
                    />
                    {#if query !== ''}
                        <button
                            type="button"
                            class="absolute top-1/2 right-2.5 flex size-6 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
                            aria-label="Clear search"
                            onclick={clearQuery}
                        >
                            <X class="size-3.5" />
                        </button>
                    {/if}
                </div>
                {#if models !== undefined && models.length > 0}
                    <div class="flex items-center gap-1.5 px-2.5 pb-2.5">
                        <button
                            type="button"
                            class={chipClass(reasoningOnly)}
                            aria-pressed={reasoningOnly}
                            onclick={() => toggleFilter('reasoning')}
                        >
                            <Brain class="size-3" />
                            Reasoning
                        </button>
                        <button
                            type="button"
                            class={chipClass(freeOnly)}
                            aria-pressed={freeOnly}
                            onclick={() => toggleFilter('free')}
                        >
                            Free
                        </button>
                        <span
                            class="ml-auto text-xs text-muted-foreground tabular-nums"
                        >
                            {#if browsing}
                                {models.length.toLocaleString()} models
                            {:else}
                                {filtered.length.toLocaleString()} of {models.length.toLocaleString()}
                            {/if}
                        </span>
                    </div>
                {/if}
            </div>

            {#if models === undefined}
                <div class="flex flex-col gap-1 p-1.5" aria-busy="true">
                    {#each Array.from({ length: 5 }, (_, i) => i) as i (i)}
                        <div class="flex items-center gap-3 px-2.5 py-2">
                            <span class="size-4"></span>
                            <span class="flex flex-1 flex-col gap-1.5">
                                <span
                                    class="h-3.5 w-40 animate-pulse rounded bg-primary/10"
                                ></span>
                                <span
                                    class="h-3 w-56 animate-pulse rounded bg-primary/10"
                                ></span>
                            </span>
                            <span
                                class="h-3 w-24 animate-pulse rounded bg-primary/10"
                            ></span>
                        </div>
                    {/each}
                </div>
            {:else if rowCount === 0}
                <div
                    class="flex flex-col items-center gap-3 px-3 py-8 text-center text-sm text-muted-foreground"
                >
                    {#if models.length === 0}
                        <p>
                            The OpenRouter catalogue could not be loaded. Type a
                            model id to use it anyway.
                        </p>
                        {#if onRefresh}
                            <button
                                type="button"
                                class={chipClass(false)}
                                disabled={refreshing}
                                onclick={onRefresh}
                            >
                                <RefreshCw
                                    class={cn(
                                        'size-3',
                                        refreshing && 'animate-spin',
                                    )}
                                />
                                Try again
                            </button>
                        {/if}
                    {:else}
                        <p>
                            No models match. Type a full model id to use a
                            custom one.
                        </p>
                    {/if}
                </div>
            {:else}
                <ul
                    id="{id}-listbox"
                    role="listbox"
                    aria-label="Models"
                    bind:this={listElement}
                    class="min-h-0 flex-1 overflow-y-auto overscroll-contain"
                >
                    {#each groups as group (group.key)}
                        <li role="presentation">
                            <p
                                class="sticky top-0 z-10 border-b border-border/60 bg-popover px-4 py-1.5 text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase"
                            >
                                {group.label}
                                <span class="ml-1 font-normal tabular-nums"
                                    >{group.models.length}</span
                                >
                            </p>
                            <ul
                                role="group"
                                aria-label={group.label}
                                class="p-1.5"
                            >
                                {#each group.models as { model, index } (model.id)}
                                    <li
                                        id="{id}-option-{index}"
                                        role="option"
                                        aria-selected={model.id === value}
                                        class={rowClass(index === highlighted)}
                                        onpointermove={() =>
                                            (highlighted = index)}
                                        onpointerdown={(event) => {
                                            event.preventDefault();
                                            chooseRow(index);
                                        }}
                                    >
                                        <span
                                            class="flex size-4 shrink-0 items-center justify-center"
                                        >
                                            {#if model.id === value}
                                                <Check class="size-4" />
                                            {/if}
                                        </span>
                                        <span
                                            class="flex min-w-0 flex-1 flex-col"
                                        >
                                            <span
                                                class={cn(
                                                    'truncate',
                                                    model.id === value &&
                                                        'font-medium',
                                                )}
                                                >{@render emphasised(
                                                    shortName(model),
                                                )}</span
                                            >
                                            <span
                                                class="truncate font-mono text-xs text-muted-foreground"
                                                >{@render emphasised(
                                                    model.id,
                                                )}</span
                                            >
                                        </span>
                                        {@render meta(model)}
                                    </li>
                                {/each}
                            </ul>
                        </li>
                    {/each}
                    {#if customCandidate !== null}
                        {@const index = filtered.length}
                        <li
                            role="presentation"
                            class={cn(
                                'p-1.5',
                                filtered.length > 0 && 'border-t',
                            )}
                        >
                            <div
                                id="{id}-option-{index}"
                                role="option"
                                tabindex="-1"
                                aria-selected={customCandidate === value}
                                class={rowClass(index === highlighted)}
                                onpointermove={() => (highlighted = index)}
                                onpointerdown={(event) => {
                                    event.preventDefault();
                                    chooseRow(index);
                                }}
                            >
                                <span
                                    class="flex size-4 shrink-0 items-center justify-center"
                                >
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
                                <span
                                    class="hidden shrink-0 text-xs text-muted-foreground sm:block"
                                    >Not in the catalogue</span
                                >
                            </div>
                        </li>
                    {/if}
                </ul>
            {/if}

            <p
                class="flex shrink-0 items-center gap-3 border-t px-3 py-1.5 text-[11px] text-muted-foreground"
            >
                <span><kbd class="font-sans">↑↓</kbd> navigate</span>
                <span><kbd class="font-sans">↵</kbd> select</span>
                <span><kbd class="font-sans">esc</kbd> close</span>
                <span class="ml-auto flex items-center gap-2 tabular-nums">
                    {#if catalogue?.fetched_at}
                        <span
                            class={cn(
                                catalogue.failed &&
                                    'text-amber-700 dark:text-amber-400',
                            )}
                            title={new Date(
                                catalogue.fetched_at,
                            ).toLocaleString()}
                        >
                            {catalogue.failed
                                ? 'Catalogue fetch failed'
                                : `Catalogue updated ${timeAgo(catalogue.fetched_at)}`}
                        </span>
                    {:else}
                        <span>Prices are USD per million tokens</span>
                    {/if}
                    {#if onRefresh}
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 font-medium text-foreground hover:bg-muted disabled:opacity-50"
                            disabled={refreshing}
                            onclick={onRefresh}
                        >
                            <RefreshCw
                                class={cn(
                                    'size-3',
                                    refreshing && 'animate-spin',
                                )}
                            />
                            Refresh
                        </button>
                    {/if}
                </span>
            </p>
        </div>
    {/if}
</div>
