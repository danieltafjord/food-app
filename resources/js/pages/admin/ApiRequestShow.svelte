<script module lang="ts">
    import { analytics as analyticsRoute } from '@/routes/admin';
    import { index as apiRequestsRoute } from '@/routes/admin/api-requests';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Admin',
                href: analyticsRoute(),
            },
            {
                title: 'API requests',
                href: apiRequestsRoute(),
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
    import StatusDot from '@/components/admin/StatusDot.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { show as showAiRequest } from '@/routes/admin/ai-requests';
    import {
        index as usersRoute,
        show as showUser,
    } from '@/routes/admin/users';

    type Detail = {
        id: number;
        channel: string;
        method: string;
        path: string;
        route: string | null;
        status: number;
        duration_ms: number;
        has_error: boolean;
        user: { id: number; name: string; email: string } | null;
        household: { id: number; name: string } | null;
        token_name: string | null;
        ip: string | null;
        user_agent: string | null;
        request_body: string | null;
        response_body: string | null;
        error: string | null;
        created_at: string | null;
        retained_until: string | null;
        request_id: string | null;
        ai_requests: { id: number; feature: string; status: string }[];
    };

    let {
        request,
        channels,
    }: { request: Detail; channels: Record<string, string> } = $props();

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

    const statusTone = $derived<'ok' | 'warning' | 'danger'>(
        request.status >= 500
            ? 'danger'
            : request.status >= 400
              ? 'warning'
              : 'ok',
    );

    const labelClass = 'text-xs font-medium text-muted-foreground';
</script>

<AppHead title="API request #{request.id}" />

<AdminPage title="API request #{request.id}">
    {#snippet actions()}
        <Link
            href={apiRequestsRoute().url}
            class="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" />
            All API requests
        </Link>
    {/snippet}

    <div class="flex flex-col gap-4">
        <AdminPanel>
            <p class="flex flex-wrap items-baseline gap-2 font-mono text-sm">
                <span class="font-semibold text-muted-foreground"
                    >{request.method}</span
                >
                <span class="break-all">/{request.path}</span>
                {#if request.route}
                    <span class="text-xs text-muted-foreground"
                        >{request.route}</span
                    >
                {/if}
            </p>
            <dl
                class="mt-5 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-4"
            >
                <div>
                    <dt class={labelClass}>Status</dt>
                    <dd class="mt-1">
                        <StatusDot tone={statusTone}>
                            <span class="tabular-nums">{request.status}</span>
                            {#if request.has_error}
                                <span class="text-xs text-muted-foreground"
                                    >exception</span
                                >
                            {/if}
                        </StatusDot>
                        <span class="block text-xs text-muted-foreground"
                            >{request.duration_ms.toLocaleString()} ms</span
                        >
                    </dd>
                </div>
                <div>
                    <dt class={labelClass}>When</dt>
                    <dd class="mt-1 tabular-nums">
                        {formatDateTime(request.created_at)}
                        <span class="block text-xs text-muted-foreground"
                            >Kept until {formatDate(
                                request.retained_until,
                            )}</span
                        >
                    </dd>
                </div>
                <div>
                    <dt class={labelClass}>Channel</dt>
                    <dd class="mt-1">
                        {channels[request.channel] ?? request.channel}
                        {#if request.token_name}
                            <span class="block text-xs text-muted-foreground"
                                >Token: {request.token_name}</span
                            >
                        {/if}
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
                                >Not authenticated</span
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
                    <dt class={labelClass}>Client</dt>
                    <dd class="mt-1 text-xs break-all text-muted-foreground">
                        {request.ip ?? '–'}
                        {#if request.user_agent}
                            · {request.user_agent}
                        {/if}
                    </dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <dt class={labelClass}>Request id</dt>
                    <dd class="mt-1 flex flex-wrap items-center gap-3">
                        <code class="font-mono text-xs break-all"
                            >{request.request_id ?? 'Not recorded'}</code
                        >
                        {#each request.ai_requests as aiRequest (aiRequest.id)}
                            <Link
                                href={showAiRequest(aiRequest.id).url}
                                class="text-xs font-medium text-muted-foreground hover:text-foreground hover:underline"
                                >AI request #{aiRequest.id} ({aiRequest.feature},
                                {aiRequest.status})</Link
                            >
                        {/each}
                        <span class="text-xs text-muted-foreground"
                            >Sent to clients as the X-Request-Id header.</span
                        >
                    </dd>
                </div>
            </dl>
        </AdminPanel>

        {#if request.error}
            <AdminPanel
                title="Exception"
                description="The exception the server raised while handling this request, with its stack trace."
            >
                <CodeBlock value={request.error} tone="danger" />
            </AdminPanel>
        {/if}

        <div class="grid gap-4 lg:grid-cols-2">
            <AdminPanel
                title="Request"
                description="The body and query string the client sent. Passwords, tokens and other secrets are redacted before storage."
            >
                <CodeBlock
                    value={request.request_body}
                    empty="The request had no body or query string."
                />
            </AdminPanel>
            <AdminPanel
                title="Response"
                description="What the client received. Bodies over 16 KB are truncated."
            >
                <CodeBlock
                    value={request.response_body}
                    empty="The response had no body."
                />
            </AdminPanel>
        </div>
    </div>
</AdminPage>
