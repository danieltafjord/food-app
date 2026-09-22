<script module lang="ts">
    import { edit } from '@/routes/admin/ai';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Admin',
                href: edit(),
            },
            {
                title: 'AI models',
                href: edit(),
            },
        ],
    };
</script>

<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import AiSettingsController from '@/actions/App/Http/Controllers/Admin/AiSettingsController';
    import AdminPage from '@/components/admin/AdminPage.svelte';
    import AdminPanel from '@/components/admin/AdminPanel.svelte';
    import AuditTrail from '@/components/admin/AuditTrail.svelte';
    import type { AuditEntry } from '@/components/admin/AuditTrail.svelte';
    import StatusDot from '@/components/admin/StatusDot.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import ModelCombobox from '@/components/ModelCombobox.svelte';
    import type { ModelOption } from '@/components/ModelCombobox.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Skeleton } from '@/components/ui/skeleton';

    type Feature = {
        key: string;
        label: string;
        description: string;
        model: string;
        reasoning: string | null;
        supports_reasoning: boolean;
        default_model: string;
    };

    type Scope = 'user' | 'household' | 'global';

    type Limits = {
        feature: string;
        label: string;
        user: number;
        household: number;
        global: number;
        defaults: Record<Scope, number>;
    };

    let {
        features,
        limits,
        reasoningEfforts,
        available,
        models,
        recentActions,
    }: {
        features: Feature[];
        limits: Limits[];
        reasoningEfforts: string[];
        available: boolean;
        /** Deferred: undefined until the OpenRouter catalogue has loaded. */
        models?: ModelOption[];
        recentActions: AuditEntry[];
    } = $props();

    const scopes: { key: Scope; label: string; hint: string }[] = [
        {
            key: 'user',
            label: 'Per user',
            hint: 'requests per account per day',
        },
        {
            key: 'household',
            label: 'Per household',
            hint: 'shared by all members',
        },
        { key: 'global', label: 'Whole app', hint: 'across every household' },
    ];

    // svelte-ignore state_referenced_locally
    const limitForms = limits.map((row) =>
        useForm({
            feature: row.feature,
            user: row.user,
            household: row.household,
            global: row.global,
        }),
    );

    function saveLimits(index: number, event: SubmitEvent) {
        event.preventDefault();
        limitForms[index].patch(AiSettingsController.updateLimits.url(), {
            preserveScroll: true,
        });
    }

    function limitsDirty(index: number, row: Limits): boolean {
        const form = limitForms[index];

        return (
            Number(form.user) !== row.user ||
            Number(form.household) !== row.household ||
            Number(form.global) !== row.global
        );
    }

    const effortDescriptions: Record<string, string> = {
        none: 'Reasoning off',
        minimal: 'Minimal, roughly 10% of the token budget',
        low: 'Low, roughly 20%',
        medium: 'Medium, roughly 50%',
        high: 'High, roughly 80%',
        xhigh: 'Extra high, roughly 95%',
        max: 'Maximum, roughly 95%',
    };

    // Forms seed from the initial props on purpose; a save reloads the page.
    // svelte-ignore state_referenced_locally
    const forms = features.map((feature) =>
        useForm({
            feature: feature.key,
            model: feature.model,
            reasoning: feature.reasoning ?? '',
        }),
    );

    const selectClass =
        'flex h-10 w-full rounded-full border border-input bg-background px-4 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2';

    function save(index: number, event: SubmitEvent) {
        event.preventDefault();
        forms[index].transform((data) => ({
            ...data,
            reasoning: data.reasoning === '' ? null : data.reasoning,
        }));
        forms[index].patch(AiSettingsController.update.url(), {
            preserveScroll: true,
        });
    }

    function selectedModel(index: number): ModelOption | undefined {
        return models?.find((model) => model.id === forms[index].model);
    }

    function isDirty(index: number, feature: Feature): boolean {
        return (
            forms[index].model !== feature.model ||
            forms[index].reasoning !== (feature.reasoning ?? '')
        );
    }
</script>

<AppHead title="AI models" />

<AdminPage
    title="AI models"
    description="Choose which OpenRouter model and reasoning effort each AI feature uses. Changes apply to the next request."
>
    {#snippet actions()}
        <span
            class="rounded-full border border-border/80 bg-panel px-3 py-1.5 text-xs font-medium"
        >
            {#if available}
                <StatusDot tone="ok">Server-side AI on</StatusDot>
            {:else}
                <StatusDot tone="warning">Server-side AI off</StatusDot>
            {/if}
        </span>
    {/snippet}

    <div class="flex max-w-3xl flex-col gap-6">
        {#if !available}
            <div
                class="flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200"
            >
                <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-amber-500"
                ></span>
                <p>
                    Requests are not sent anywhere until <code
                        class="font-mono text-xs">AI_ENABLED=true</code
                    >
                    and an
                    <code class="font-mono text-xs">OPENROUTER_API_KEY</code> are
                    set in the environment. Model choices are saved regardless.
                </p>
            </div>
        {/if}

        {#each features as feature, index (feature.key)}
            {@const form = forms[index]}
            {@const chosen = selectedModel(index)}
            <form onsubmit={(event) => save(index, event)}>
                <AdminPanel
                    title={feature.label}
                    description={feature.description}
                >
                    <div class="flex flex-col gap-6">
                        <div
                            class="grid gap-2 sm:grid-cols-[10rem_1fr] sm:gap-6"
                        >
                            <Label for="{feature.key}-model" class="sm:pt-2.5"
                                >Model</Label
                            >
                            <div class="flex flex-col gap-1.5">
                                {#if models === undefined}
                                    <Skeleton class="h-12 w-full rounded-2xl" />
                                {:else}
                                    <ModelCombobox
                                        id="{feature.key}-model"
                                        bind:value={form.model}
                                        {models}
                                    />
                                {/if}
                                <InputError message={form.errors.model} />
                                <p class="text-xs text-muted-foreground">
                                    Any OpenRouter model id works, including
                                    ones not in the catalogue.
                                </p>
                            </div>
                        </div>

                        {#if feature.supports_reasoning}
                            <div
                                class="grid gap-2 sm:grid-cols-[10rem_1fr] sm:gap-6"
                            >
                                <Label
                                    for="{feature.key}-reasoning"
                                    class="sm:pt-2.5">Reasoning effort</Label
                                >
                                <div class="flex flex-col gap-1.5">
                                    <select
                                        id="{feature.key}-reasoning"
                                        class={selectClass}
                                        bind:value={form.reasoning}
                                    >
                                        <option value=""
                                            >Provider default</option
                                        >
                                        {#each reasoningEfforts as effort (effort)}
                                            <option value={effort}
                                                >{effort} · {effortDescriptions[
                                                    effort
                                                ] ?? ''}</option
                                            >
                                        {/each}
                                    </select>
                                    <InputError
                                        message={form.errors.reasoning}
                                    />
                                    {#if chosen && !chosen.supports_reasoning && form.reasoning !== ''}
                                        <p
                                            class="text-xs text-amber-700 dark:text-amber-400"
                                        >
                                            OpenRouter does not list reasoning
                                            as a supported parameter for this
                                            model. The setting is sent anyway
                                            and may be ignored or rejected.
                                        </p>
                                    {/if}
                                </div>
                            </div>
                        {/if}

                        <InputError message={form.errors.feature} />
                    </div>

                    {#snippet footer()}
                        <p class="text-xs text-muted-foreground">
                            Environment default:
                            <code class="font-mono"
                                >{feature.default_model}</code
                            >
                        </p>
                        <div class="flex items-center gap-3">
                            {#if form.recentlySuccessful}
                                <span class="text-xs text-muted-foreground"
                                    >Saved</span
                                >
                            {:else if isDirty(index, feature)}
                                <span class="text-xs text-muted-foreground"
                                    >Unsaved changes</span
                                >
                            {/if}
                            <Button
                                type="submit"
                                size="sm"
                                class="rounded-full px-4"
                                disabled={form.processing}
                            >
                                Save changes
                            </Button>
                        </div>
                    {/snippet}
                </AdminPanel>
            </form>
        {/each}

        <h2 class="mt-4 text-lg font-semibold tracking-tight">Daily budgets</h2>
        <p class="-mt-4 text-sm text-muted-foreground">
            Requests per UTC day. Failed calls count too; cache hits do not. Set
            a budget to 0 to pause a feature without touching the models.
        </p>

        {#each limits as row, index (row.feature)}
            {@const form = limitForms[index]}
            <form onsubmit={(event) => saveLimits(index, event)}>
                <AdminPanel title={row.label}>
                    <div class="grid gap-4 sm:grid-cols-3">
                        {#each scopes as scope (scope.key)}
                            <div class="grid gap-1.5">
                                <Label for="{row.feature}-{scope.key}"
                                    >{scope.label}</Label
                                >
                                <Input
                                    id="{row.feature}-{scope.key}"
                                    type="number"
                                    min="0"
                                    step="1"
                                    inputmode="numeric"
                                    class="h-10 rounded-full px-4 shadow-none tabular-nums"
                                    bind:value={form[scope.key]}
                                />
                                <InputError message={form.errors[scope.key]} />
                                <p class="text-xs text-muted-foreground">
                                    {scope.hint}
                                </p>
                            </div>
                        {/each}
                    </div>
                    <InputError message={form.errors.feature} />

                    {#snippet footer()}
                        <p class="text-xs text-muted-foreground tabular-nums">
                            Environment defaults: {row.defaults.user} / {row
                                .defaults.household} / {row.defaults.global}
                        </p>
                        <div class="flex items-center gap-3">
                            {#if form.recentlySuccessful}
                                <span class="text-xs text-muted-foreground"
                                    >Saved</span
                                >
                            {:else if limitsDirty(index, row)}
                                <span class="text-xs text-muted-foreground"
                                    >Unsaved changes</span
                                >
                            {/if}
                            <Button
                                type="submit"
                                size="sm"
                                class="rounded-full px-4"
                                disabled={form.processing}
                            >
                                Save budgets
                            </Button>
                        </div>
                    {/snippet}
                </AdminPanel>
            </form>
        {/each}

        <AdminPanel
            title="Recent changes"
            description="The last ten changes to models and budgets."
        >
            <AuditTrail entries={recentActions} emptyText="No changes yet." />
        </AdminPanel>
    </div>
</AdminPage>
