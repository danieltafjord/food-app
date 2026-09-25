<script module lang="ts">
    import AppLayout from '@/layouts/AppLayout.svelte';
    import { edit } from '@/routes/admin/ai';

    export const layout = [
        AppLayout,
        {
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
        },
    ];
</script>

<script lang="ts">
    import { router, useForm, useHttp } from '@inertiajs/svelte';
    import FlaskConical from 'lucide-svelte/icons/flask-conical';
    import LoaderCircle from 'lucide-svelte/icons/loader-circle';
    import { onMount } from 'svelte';
    import AiSettingsController from '@/actions/App/Http/Controllers/Admin/AiSettingsController';
    import AdminPage from '@/components/admin/AdminPage.svelte';
    import AdminPanel from '@/components/admin/AdminPanel.svelte';
    import AdminSection from '@/components/admin/AdminSection.svelte';
    import AuditTrail from '@/components/admin/AuditTrail.svelte';
    import type { AuditEntry } from '@/components/admin/AuditTrail.svelte';
    import SegmentedControl from '@/components/admin/SegmentedControl.svelte';
    import StatusDot from '@/components/admin/StatusDot.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import ModelCombobox from '@/components/ModelCombobox.svelte';
    import type { ModelOption } from '@/components/ModelCombobox.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Skeleton } from '@/components/ui/skeleton';
    import { cn } from '@/lib/utils';
    import { show as showAiRequest } from '@/routes/admin/ai-requests';

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

    type FeatureUsage = {
        global_used: number;
        top_user_used: number;
        top_household_used: number;
        avg_input_tokens: number;
        avg_output_tokens: number;
        sample_size: number;
    };

    type Usage = {
        resets_at: string;
        features: Record<string, FeatureUsage>;
    };

    type TestResult = {
        status: 'ok' | 'failed';
        duration_ms: number;
        input_tokens: number;
        output_tokens: number;
        cost: number;
        data: Record<string, unknown> | null;
        raw: Record<string, unknown> | null;
        error: string | null;
        request_id: number;
    };

    let {
        features,
        limits,
        reasoningEfforts,
        available,
        enabled,
        providerConfigured,
        usage,
        models,
        catalogue,
        recentActions,
    }: {
        features: Feature[];
        limits: Limits[];
        reasoningEfforts: string[];
        available: boolean;
        enabled: boolean;
        providerConfigured: boolean;
        usage: Usage;
        /** Deferred: undefined until the OpenRouter catalogue has loaded. */
        models?: ModelOption[];
        catalogue?: { fetched_at: string | null; failed: boolean };
        recentActions: AuditEntry[];
    } = $props();

    const availabilityForm = useForm({ enabled: false });

    function toggleAvailability() {
        if (availabilityForm.processing) {
            return;
        }

        availabilityForm.enabled = !enabled;
        availabilityForm.patch(AiSettingsController.updateAvailability.url(), {
            preserveScroll: true,
        });
    }

    const scopes: {
        key: Scope;
        label: string;
        hint: string;
        usageLabel: string;
        used: (row: FeatureUsage) => number;
    }[] = [
        {
            key: 'user',
            label: 'Per user',
            hint: 'requests per account per day',
            usageLabel: 'busiest user today',
            used: (row) => row.top_user_used,
        },
        {
            key: 'household',
            label: 'Per household',
            hint: 'shared by all members',
            usageLabel: 'busiest household today',
            used: (row) => row.top_household_used,
        },
        {
            key: 'global',
            label: 'Whole app',
            hint: 'across every household',
            usageLabel: 'used today',
            used: (row) => row.global_used,
        },
    ];

    // Forms seed from the initial props on purpose; a save reloads the page.
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

    function resetLimits(index: number, row: Limits) {
        limitForms[index].user = row.defaults.user;
        limitForms[index].household = row.defaults.household;
        limitForms[index].global = row.defaults.global;
    }

    const effortDescriptions: Record<string, string> = {
        '': 'Whatever the provider does by default.',
        none: 'Reasoning off. Fastest and cheapest.',
        minimal: 'Roughly 10% of the token budget goes to thinking.',
        low: 'Roughly 20% of the token budget goes to thinking.',
        medium: 'Roughly 50% of the token budget goes to thinking.',
        high: 'Roughly 80% of the token budget goes to thinking.',
        xhigh: 'Roughly 95% of the token budget goes to thinking.',
        max: 'As much thinking as the model allows.',
    };

    const effortSegments = $derived([
        { value: '', label: 'Default' },
        ...reasoningEfforts.map((effort) => ({
            value: effort,
            label: effort === 'xhigh' ? 'x-high' : effort,
        })),
    ]);

    // svelte-ignore state_referenced_locally
    const forms = features.map((feature) =>
        useForm({
            feature: feature.key,
            model: feature.model,
            reasoning: feature.reasoning ?? '',
        }),
    );

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

    const anyDirty = $derived(
        features.some((feature, index) => isDirty(index, feature)) ||
            limits.some((row, index) => limitsDirty(index, row)),
    );

    /**
     * Each panel saves on its own, so leaving the page with edits in another
     * panel would silently drop them. Ask before navigating away.
     */
    onMount(() => {
        const message = 'You have unsaved changes. Leave without saving?';
        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            if (anyDirty) {
                event.preventDefault();
            }
        };
        const unsubscribe = router.on('before', (event) => {
            const visit = event.detail.visit;
            const isSave = visit.method !== 'get';

            if (anyDirty && !isSave && !window.confirm(message)) {
                event.preventDefault();
            }
        });
        window.addEventListener('beforeunload', onBeforeUnload);

        return () => {
            unsubscribe();
            window.removeEventListener('beforeunload', onBeforeUnload);
        };
    });

    /** Catalogue refresh: drop the server cache, then reload the deferred props. */
    let refreshing = $state(false);

    function refreshCatalogue() {
        refreshing = true;
        router.post(
            AiSettingsController.refreshCatalogue.url(),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                onFinish: () => (refreshing = false),
            },
        );
    }

    /** One real request per feature with the unsaved form values. */
    const tester = useHttp<
        {
            feature: string;
            model: string;
            reasoning: string | null;
            sample: string;
        },
        TestResult
    >({ feature: '', model: '', reasoning: null, sample: '' });
    let samples = $state<Record<string, string>>({});
    let testing = $state<string | null>(null);
    let testResults = $state<Record<string, TestResult | undefined>>({});
    let testErrors = $state<Record<string, string | undefined>>({});

    const samplePlaceholder: Record<string, string> = {
        categorization: 'pak choi',
        suggestions: 'Tacos',
    };

    function runTest(index: number, feature: Feature) {
        if (testing !== null) {
            return;
        }

        const form = forms[index];
        tester.feature = feature.key;
        tester.model = form.model;
        tester.reasoning = form.reasoning === '' ? null : form.reasoning;
        tester.sample = samples[feature.key] ?? '';
        testing = feature.key;
        testErrors[feature.key] = undefined;

        tester.post(AiSettingsController.test.url(), {
            onSuccess: (data) => {
                testResults[feature.key] = data;
            },
            onError: (errors) => {
                testErrors[feature.key] =
                    Object.values(errors)[0] ?? 'The test could not run.';
            },
            onHttpException: (response) => {
                let message = 'The test could not run.';

                try {
                    const body =
                        typeof response.data === 'string'
                            ? (JSON.parse(response.data) as {
                                  message?: string;
                              })
                            : (response.data as { message?: string });
                    message = body.message ?? message;
                } catch {
                    // Not JSON; keep the generic message.
                }

                testErrors[feature.key] = message;
            },
            onFinish: () => (testing = null),
        });
    }

    const money = (value: number, digits = 4) =>
        value === 0
            ? '$0'
            : `$${value.toFixed(digits).replace(/0+$/, '').replace(/\.$/, '')}`;

    /** Assumed tokens per request when there is no history yet. */
    const fallbackTokens = { input: 1000, output: 200 };

    function costPerRequest(
        model: ModelOption | undefined,
        featureUsage: FeatureUsage,
    ): number | null {
        if (
            !model ||
            model.prompt_price === null ||
            model.completion_price === null
        ) {
            return null;
        }

        const input =
            featureUsage.sample_size > 0
                ? featureUsage.avg_input_tokens
                : fallbackTokens.input;
        const output =
            featureUsage.sample_size > 0
                ? featureUsage.avg_output_tokens
                : fallbackTokens.output;

        return (
            (input * model.prompt_price + output * model.completion_price) /
            1_000_000
        );
    }

    function limitsFor(feature: string): Limits | undefined {
        return limits.find((row) => row.feature === feature);
    }

    const resetsAtLocal = $derived(
        new Date(usage.resets_at).toLocaleTimeString(undefined, {
            hour: '2-digit',
            minute: '2-digit',
        }),
    );

    const formatJson = (value: unknown) => JSON.stringify(value, null, 2);

    const fieldRow = 'grid gap-2 sm:grid-cols-[10rem_1fr] sm:gap-6';
    const noteClass = 'text-xs text-amber-700 dark:text-amber-400';
    const linkButton =
        'text-xs font-medium text-muted-foreground underline-offset-2 hover:text-foreground hover:underline disabled:opacity-50';
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
                <StatusDot tone="ok">Server-side AI enabled</StatusDot>
            {:else if enabled}
                <StatusDot tone="warning"
                    >AI enabled · API key missing</StatusDot
                >
            {:else}
                <StatusDot tone="warning">Server-side AI off</StatusDot>
            {/if}
        </span>
    {/snippet}

    <div class="flex max-w-3xl flex-col gap-6">
        <AdminPanel
            title="Server-side AI"
            description="Controls week planning, ingredient suggestions, categorization and dinner image generation. Changes apply to new requests in this environment."
        >
            <div class="flex items-center justify-between gap-4">
                <div class="space-y-1">
                    <p id="ai-enabled-label" class="text-sm font-medium">
                        Enable AI assistance
                    </p>
                    <p
                        id="ai-enabled-description"
                        class="text-xs text-muted-foreground"
                    >
                        {availabilityForm.processing
                            ? 'Saving…'
                            : enabled
                              ? 'Enabled'
                              : 'Disabled'}. Your choice is saved across
                        deployments.
                    </p>
                </div>
                <button
                    type="button"
                    role="switch"
                    aria-checked={enabled}
                    aria-labelledby="ai-enabled-label"
                    aria-describedby="ai-enabled-description"
                    disabled={availabilityForm.processing}
                    onclick={toggleAvailability}
                    class={cn(
                        'inline-flex h-7 w-12 shrink-0 items-center rounded-full border border-border p-0.5 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-50',
                        enabled ? 'bg-primary' : 'bg-muted',
                    )}
                >
                    <span
                        class={cn(
                            'size-5 rounded-full bg-background shadow-sm transition-transform',
                            enabled ? 'translate-x-5' : 'translate-x-0',
                        )}
                    ></span>
                </button>
            </div>
            <InputError message={availabilityForm.errors.enabled} />
            {#snippet footer()}
                <p class="text-xs text-muted-foreground">
                    {providerConfigured
                        ? 'OpenRouter API key configured.'
                        : 'OpenRouter API key missing.'}
                    This status does not test provider connectivity, quota or recipe
                    generation.
                </p>
            {/snippet}
        </AdminPanel>

        {#if !providerConfigured}
            <div
                class="flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200"
            >
                <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-amber-500"
                ></span>
                <p>
                    Add an <code class="font-mono text-xs"
                        >OPENROUTER_API_KEY</code
                    >
                    to this server's environment before AI requests can run. Enabling
                    the switch saves your preference, but cannot supply the key.
                </p>
            </div>
        {/if}

        {#if catalogue?.failed}
            <div
                class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200"
            >
                <p>
                    The OpenRouter catalogue could not be loaded, so the pickers
                    only accept typed ids for now.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="rounded-full shadow-none"
                    disabled={refreshing}
                    onclick={refreshCatalogue}
                >
                    Try again
                </Button>
            </div>
        {/if}

        {#each features as feature, index (feature.key)}
            {@const form = forms[index]}
            {@const chosen = selectedModel(index)}
            {@const featureUsage = usage.features[feature.key]}
            {@const budget = limitsFor(feature.key)}
            {@const perRequest = costPerRequest(chosen, featureUsage)}
            {@const result = testResults[feature.key]}
            <form onsubmit={(event) => save(index, event)}>
                <AdminPanel
                    title={feature.label}
                    description={feature.description}
                >
                    <div class="flex flex-col gap-6">
                        <div class={fieldRow}>
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
                                        {catalogue}
                                        {refreshing}
                                        onRefresh={refreshCatalogue}
                                        pinned={features
                                            .filter(
                                                (f) => f.key !== feature.key,
                                            )
                                            .map((f) => f.model)}
                                        pinnedLabel="Used by other features"
                                    />
                                {/if}
                                <InputError message={form.errors.model} />
                                {#if feature.key === 'categorization' && chosen}
                                    <p class={noteClass}>
                                        This is a chat-completions model from
                                        the OpenRouter catalogue. Categorization
                                        calls the System One endpoint, which
                                        only accepts System One models such as
                                        <code class="font-mono"
                                            >{feature.default_model}</code
                                        >. Requests will most likely fail.
                                    </p>
                                {/if}
                                <div
                                    class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
                                >
                                    <span>
                                        Any OpenRouter model id works, including
                                        ones not in the catalogue.
                                    </span>
                                    {#if form.model !== feature.default_model}
                                        <button
                                            type="button"
                                            class={linkButton}
                                            onclick={() =>
                                                (form.model =
                                                    feature.default_model)}
                                        >
                                            Use environment default
                                        </button>
                                    {/if}
                                </div>
                            </div>
                        </div>

                        {#if feature.supports_reasoning}
                            <div class={fieldRow}>
                                <Label
                                    for="{feature.key}-reasoning"
                                    class="sm:pt-2">Reasoning effort</Label
                                >
                                <div
                                    class="flex flex-col gap-1.5"
                                    id="{feature.key}-reasoning"
                                >
                                    <SegmentedControl
                                        segments={effortSegments}
                                        value={form.reasoning}
                                        label="Reasoning effort"
                                        onSelect={(value) =>
                                            (form.reasoning = String(value))}
                                    />
                                    <InputError
                                        message={form.errors.reasoning}
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        {effortDescriptions[form.reasoning] ??
                                            ''}
                                    </p>
                                    {#if chosen && !chosen.supports_reasoning && form.reasoning !== ''}
                                        <p class={noteClass}>
                                            OpenRouter does not list reasoning
                                            as a supported parameter for this
                                            model. The setting is sent anyway
                                            and may be ignored or rejected.
                                        </p>
                                    {/if}
                                </div>
                            </div>
                        {/if}

                        <div class={fieldRow}>
                            <span
                                class="text-sm font-medium text-muted-foreground sm:pt-0.5"
                                >Estimated cost</span
                            >
                            <div class="text-sm">
                                {#if perRequest === null}
                                    <p class="text-muted-foreground">
                                        {#if models === undefined}
                                            Waiting for the catalogue…
                                        {:else}
                                            No price known for this model, so no
                                            estimate.
                                        {/if}
                                    </p>
                                {:else}
                                    <p class="tabular-nums">
                                        About <strong
                                            >{money(perRequest, 5)}</strong
                                        >
                                        per request{#if budget}, at most
                                            <strong
                                                >{money(
                                                    perRequest * budget.global,
                                                    2,
                                                )}</strong
                                            > per day at the global budget{/if}.
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {#if featureUsage.sample_size > 0}
                                            Based on the average of {featureUsage.avg_input_tokens.toLocaleString()}
                                            in and {featureUsage.avg_output_tokens.toLocaleString()}
                                            out tokens over {featureUsage.sample_size.toLocaleString()}
                                            requests in the last 30 days.
                                        {:else}
                                            No history yet, so this assumes {fallbackTokens.input.toLocaleString()}
                                            in and {fallbackTokens.output} out tokens
                                            per request.
                                        {/if}
                                    </p>
                                {/if}
                            </div>
                        </div>

                        <div class={fieldRow}>
                            <Label for="{feature.key}-sample" class="sm:pt-2.5"
                                >Try it</Label
                            >
                            <div class="flex flex-col gap-3">
                                <div class="flex flex-wrap gap-2">
                                    <Input
                                        id="{feature.key}-sample"
                                        class="h-10 min-w-0 flex-1 rounded-full px-4 shadow-none sm:max-w-xs"
                                        placeholder={samplePlaceholder[
                                            feature.key
                                        ] ?? ''}
                                        bind:value={samples[feature.key]}
                                        disabled={!available ||
                                            testing !== null}
                                        onkeydown={(event) => {
                                            if (event.key === 'Enter') {
                                                event.preventDefault();
                                                runTest(index, feature);
                                            }
                                        }}
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        class="rounded-full shadow-none"
                                        disabled={!available ||
                                            testing !== null ||
                                            form.model.trim() === ''}
                                        onclick={() => runTest(index, feature)}
                                    >
                                        {#if testing === feature.key}
                                            <LoaderCircle
                                                class="size-4 animate-spin"
                                            />
                                            Testing…
                                        {:else}
                                            <FlaskConical class="size-4" />
                                            Test with these settings
                                        {/if}
                                    </Button>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Sends one real request with the model and
                                    effort above, before you save. It bypasses
                                    budgets and the cache and is logged as a
                                    test.
                                </p>
                                {#if testErrors[feature.key]}
                                    <p
                                        class="text-xs text-red-600 dark:text-red-400"
                                    >
                                        {testErrors[feature.key]}
                                    </p>
                                {/if}
                                {#if result}
                                    <div
                                        class={cn(
                                            'flex flex-col gap-3 rounded-2xl border px-4 py-3 text-sm',
                                            result.status === 'ok'
                                                ? 'border-border/80 bg-canvas'
                                                : 'border-red-200 bg-red-50 dark:border-red-900/50 dark:bg-red-950/30',
                                        )}
                                    >
                                        <div
                                            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground tabular-nums"
                                        >
                                            <StatusDot
                                                tone={result.status === 'ok'
                                                    ? 'ok'
                                                    : 'danger'}
                                            >
                                                <span
                                                    class="text-sm font-medium text-foreground"
                                                    >{result.status === 'ok'
                                                        ? 'Succeeded'
                                                        : 'Failed'}</span
                                                >
                                            </StatusDot>
                                            <span
                                                >{result.duration_ms.toLocaleString()}
                                                ms</span
                                            >
                                            {#if result.input_tokens + result.output_tokens > 0}
                                                <span
                                                    >{result.input_tokens.toLocaleString()}
                                                    in / {result.output_tokens.toLocaleString()}
                                                    out tokens</span
                                                >
                                            {/if}
                                            {#if result.cost > 0}
                                                <span
                                                    >{money(
                                                        result.cost,
                                                        6,
                                                    )}</span
                                                >
                                            {/if}
                                            <a
                                                href={showAiRequest(
                                                    result.request_id,
                                                ).url}
                                                class="ml-auto font-medium text-foreground hover:underline"
                                                >Open in the request log</a
                                            >
                                        </div>
                                        {#if result.status === 'ok'}
                                            <pre
                                                class="overflow-x-auto rounded-xl bg-panel p-3 font-mono text-xs leading-relaxed">{formatJson(
                                                    result.data,
                                                )}</pre>
                                        {:else}
                                            <pre
                                                class="overflow-x-auto font-mono text-xs leading-relaxed whitespace-pre-wrap text-red-800 dark:text-red-200">{result.error}</pre>
                                        {/if}
                                    </div>
                                {/if}
                            </div>
                        </div>

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

        <AdminSection
            title="Daily budgets"
            description="Requests per UTC day. Failed calls count too; cache hits do not. Set a budget to 0 to pause a feature without touching the models. Today's counters reset at {resetsAtLocal} your time."
        >
            {#each limits as row, index (row.feature)}
                {@const form = limitForms[index]}
                {@const featureUsage = usage.features[row.feature]}
                <form onsubmit={(event) => saveLimits(index, event)}>
                    <AdminPanel title={row.label}>
                        <div class="grid gap-5 sm:grid-cols-3">
                            {#each scopes as scope (scope.key)}
                                {@const used = scope.used(featureUsage)}
                                {@const cap = row[scope.key]}
                                {@const share =
                                    cap > 0
                                        ? Math.min(100, (used / cap) * 100)
                                        : used > 0
                                          ? 100
                                          : 0}
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
                                    <InputError
                                        message={form.errors[scope.key]}
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        {scope.hint}
                                    </p>
                                    <div class="mt-1 flex flex-col gap-1">
                                        <div
                                            class="h-1.5 overflow-hidden rounded-full bg-muted"
                                            role="progressbar"
                                            aria-label="{scope.label} {scope.usageLabel}"
                                            aria-valuemin={0}
                                            aria-valuemax={cap}
                                            aria-valuenow={used}
                                        >
                                            <div
                                                class={cn(
                                                    'h-full rounded-full transition-[width]',
                                                    share >= 100
                                                        ? 'bg-red-500'
                                                        : share >= 80
                                                          ? 'bg-amber-500'
                                                          : 'bg-emerald-500',
                                                )}
                                                style="width:{share}%"
                                            ></div>
                                        </div>
                                        <p
                                            class="text-xs text-muted-foreground tabular-nums"
                                        >
                                            <span
                                                class={cn(
                                                    'font-medium',
                                                    share >= 100
                                                        ? 'text-red-600 dark:text-red-400'
                                                        : 'text-foreground',
                                                )}>{used.toLocaleString()}</span
                                            >
                                            of {cap.toLocaleString()}
                                            {scope.usageLabel}
                                        </p>
                                    </div>
                                </div>
                            {/each}
                        </div>
                        <InputError message={form.errors.feature} />

                        {#snippet footer()}
                            <div
                                class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground tabular-nums"
                            >
                                <span>
                                    Environment defaults: {row.defaults.user} / {row
                                        .defaults.household} / {row.defaults
                                        .global}
                                </span>
                                {#if Number(form.user) !== row.defaults.user || Number(form.household) !== row.defaults.household || Number(form.global) !== row.defaults.global}
                                    <button
                                        type="button"
                                        class={linkButton}
                                        onclick={() => resetLimits(index, row)}
                                    >
                                        Use defaults
                                    </button>
                                {/if}
                            </div>
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
        </AdminSection>

        <AdminPanel
            title="Recent changes"
            description="The last ten changes to AI availability, models and budgets."
        >
            <AuditTrail entries={recentActions} emptyText="No changes yet." />
        </AdminPanel>
    </div>
</AdminPage>
