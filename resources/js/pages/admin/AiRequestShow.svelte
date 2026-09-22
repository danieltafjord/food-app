<script module lang="ts">
    import { analytics as analyticsRoute } from '@/routes/admin';
    import { index as aiRequestsRoute } from '@/routes/admin/ai-requests';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Admin',
                href: analyticsRoute(),
            },
            {
                title: 'AI requests',
                href: aiRequestsRoute(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import ArrowLeft from 'lucide-svelte/icons/arrow-left';
    import AdminPage from '@/components/admin/AdminPage.svelte';
    import AdminPanel from '@/components/admin/AdminPanel.svelte';
    import CodeBlock from '@/components/admin/CodeBlock.svelte';
    import StatStrip from '@/components/admin/StatStrip.svelte';
    import type { Stat } from '@/components/admin/StatStrip.svelte';
    import StatusDot from '@/components/admin/StatusDot.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { show as showApiRequest } from '@/routes/admin/api-requests';
    import {
        index as usersRoute,
        show as showUser,
    } from '@/routes/admin/users';

    type Detail = {
        id: number;
        feature: string;
        model: string;
        status: 'ok' | 'failed' | 'cached';
        duration_ms: number;
        input_tokens: number;
        output_tokens: number;
        cost: number;
        request: Record<string, unknown> | null;
        response: Record<string, unknown> | null;
        error: string | null;
        user: { id: number; name: string; email: string } | null;
        household: { id: number; name: string } | null;
        created_at: string | null;
        bodies_retained_until: string | null;
        request_id: string | null;
        api_request: { id: number; status: number } | null;
    };

    let {
        request,
        features,
    }: { request: Detail; features: Record<string, string> } = $props();

    const formatDateTime = (value: string | null) =>
        value
            ? new Date(value).toLocaleString(undefined, {
                  year: 'numeric',
                  month: 'short',
                  day: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
                  second: '2-digit',
              })
            : '–';
    const formatDate = (value: string | null) =>
        value
            ? new Date(value).toLocaleDateString(undefined, {
                  year: 'numeric',
                  month: 'short',
                  day: 'numeric',
              })
            : '–';
    const money = (value: number) =>
        value === 0 ? '$0' : `$${value.toFixed(value < 0.01 ? 5 : 3)}`;

    const statusTone: Record<Detail['status'], 'ok' | 'danger' | 'neutral'> = {
        ok: 'ok',
        failed: 'danger',
        cached: 'neutral',
    };
    const statusLabel: Record<Detail['status'], string> = {
        ok: 'Succeeded',
        failed: 'Failed',
        cached: 'Served from cache',
    };

    const stats = $derived<Stat[]>([
        {
            label: 'Latency',
            value:
                request.status === 'cached'
                    ? '–'
                    : `${request.duration_ms.toLocaleString()} ms`,
            hint: request.status === 'cached' ? 'No provider call' : undefined,
        },
        {
            label: 'Tokens',
            value: (
                request.input_tokens + request.output_tokens
            ).toLocaleString(),
            hint: `${request.input_tokens.toLocaleString()} in, ${request.output_tokens.toLocaleString()} out`,
        },
        {
            label: 'Reported cost',
            value: money(request.cost),
        },
        {
            label: 'Bodies kept until',
            value: formatDate(request.bodies_retained_until),
            hint: 'Cleared by the nightly prune afterwards',
        },
    ]);

    const bodiesCleared = $derived(
        request.request === null &&
            request.response === null &&
            request.error === null,
    );

    const labelClass = 'text-xs font-medium text-muted-foreground';
</script>

<AppHead title="AI request #{request.id}" />

<AdminPage title="AI request #{request.id}">
    {#snippet actions()}
        <Link
            href={aiRequestsRoute().url}
            class="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" />
            All AI requests
        </Link>
    {/snippet}

    <div class="flex flex-col gap-4">
        <AdminPanel>
            <dl
                class="grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-4"
            >
                <div>
                    <dt class={labelClass}>Status</dt>
                    <dd class="mt-1">
                        <StatusDot tone={statusTone[request.status]}
                            >{statusLabel[request.status]}</StatusDot
                        >
                    </dd>
                </div>
                <div>
                    <dt class={labelClass}>When</dt>
                    <dd class="mt-1 tabular-nums">
                        {formatDateTime(request.created_at)}
                    </dd>
                </div>
                <div>
                    <dt class={labelClass}>Feature</dt>
                    <dd class="mt-1">
                        {features[request.feature] ?? request.feature}
                        <span
                            class="block font-mono text-[11px] text-muted-foreground"
                            >{request.model}</span
                        >
                    </dd>
                </div>
                <div>
                    <dt class={labelClass}>User and household</dt>
                    <dd class="mt-1">
                        {#if request.user}
                            <Link
                                href={showUser(request.user.id).url}
                                class="hover:underline"
                                >{request.user.name}</Link
                            >
                            <span class="block text-xs text-muted-foreground"
                                >{request.user.email}</span
                            >
                        {:else}
                            <span class="text-muted-foreground"
                                >Deleted user</span
                            >
                        {/if}
                        {#if request.household}
                            <Link
                                href={usersRoute({
                                    query: { household: request.household.id },
                                }).url}
                                class="block text-xs text-muted-foreground hover:underline"
                                >{request.household.name}</Link
                            >
                        {/if}
                    </dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <dt class={labelClass}>Request id</dt>
                    <dd class="mt-1 flex flex-wrap items-center gap-3">
                        <code class="font-mono text-xs break-all"
                            >{request.request_id ?? 'Not recorded'}</code
                        >
                        {#if request.api_request}
                            <Link
                                href={showApiRequest(request.api_request.id)
                                    .url}
                                class="text-xs font-medium text-muted-foreground hover:text-foreground hover:underline"
                                >API request #{request.api_request.id} ({request
                                    .api_request.status})</Link
                            >
                        {/if}
                    </dd>
                </div>
            </dl>
        </AdminPanel>

        <StatStrip {stats} columns={4} />

        {#if bodiesCleared}
            <AdminPanel>
                <p class="text-sm text-muted-foreground">
                    The request and response bodies for this call have been
                    cleared. Bodies are kept for 30 days; the usage numbers
                    stay.
                </p>
            </AdminPanel>
        {:else}
            {#if request.error}
                <AdminPanel
                    title="Error"
                    description="The provider exception, with the server key redacted."
                >
                    <CodeBlock value={request.error} tone="danger" />
                </AdminPanel>
            {/if}

            <div class="grid gap-4 lg:grid-cols-2">
                <AdminPanel
                    title="Request"
                    description="The context the user's app sent, after normalisation. This is what the cache key and the prompt are built from."
                >
                    <CodeBlock value={request.request} />
                </AdminPanel>
                <AdminPanel
                    title="Response"
                    description={request.status === 'cached'
                        ? 'The cached result that was returned without a provider call.'
                        : 'data is what the app received; raw is what the provider answered before validation.'}
                >
                    <CodeBlock
                        value={request.response}
                        empty={request.status === 'failed'
                            ? 'The provider call failed before a response was recorded.'
                            : 'Nothing recorded.'}
                    />
                </AdminPanel>
            </div>
        {/if}
    </div>
</AdminPage>
