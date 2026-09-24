<script module lang="ts">
    import AppLayout from '@/layouts/AppLayout.svelte';
    import { analytics as analyticsRoute } from '@/routes/admin';
    import {
        index as apiRequestsRoute,
        show as showApiRequest,
    } from '@/routes/admin/api-requests';

    export const layout = [
        AppLayout,
        {
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
        },
    ];
</script>

<script lang="ts">
    import { Link, router } from '@inertiajs/svelte';
    import AdminPage from '@/components/admin/AdminPage.svelte';
    import AdminPanel from '@/components/admin/AdminPanel.svelte';
    import FilterChip from '@/components/admin/FilterChip.svelte';
    import PaginationFooter from '@/components/admin/PaginationFooter.svelte';
    import type { Paginator } from '@/components/admin/PaginationFooter.svelte';
    import SearchField from '@/components/admin/SearchField.svelte';
    import SegmentedControl from '@/components/admin/SegmentedControl.svelte';
    import SortHeader from '@/components/admin/SortHeader.svelte';
    import type { SortDirection } from '@/components/admin/SortHeader.svelte';
    import StatusDot from '@/components/admin/StatusDot.svelte';
    import {
        numericClass,
        rowClass,
        tdClass,
        thClass,
        theadClass,
    } from '@/components/admin/table';
    import TableEmpty from '@/components/admin/TableEmpty.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { cn } from '@/lib/utils';
    import { show as showUser } from '@/routes/admin/users';

    type Row = {
        id: number;
        channel: string;
        method: string;
        path: string;
        route: string | null;
        status: number;
        duration_ms: number;
        has_error: boolean;
        user: { id: number; name: string; email: string } | null;
        created_at: string | null;
    };

    type ErrorGroup = {
        route: string;
        method: string;
        exception: string | null;
        count: number;
        latest_id: number;
        latest_at: string | null;
    };

    type Filters = {
        channel: string;
        outcome: string;
        method: string;
        user: { id: number; name: string } | null;
        search: string;
        sort: 'created' | 'status' | 'duration';
        direction: SortDirection;
        per_page: number;
    };

    let {
        requests,
        errorGroups,
        channels,
        filters,
        pageSizes,
    }: {
        requests: Paginator & { data: Row[] };
        errorGroups: ErrorGroup[];
        channels: Record<string, string>;
        filters: Filters;
        pageSizes: number[];
    } = $props();

    const outcomes = [
        { value: 'all', label: 'All' },
        { value: 'errors', label: 'Errors' },
        { value: 'server_errors', label: '5xx only' },
    ];
    const channelSegments = $derived([
        { value: 'all', label: 'All channels' },
        ...Object.entries(channels).map(([value, label]) => ({
            value,
            label,
        })),
    ]);
    const methods = ['', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    // svelte-ignore state_referenced_locally
    let search = $state(filters.search);

    const isFiltered = $derived(
        filters.channel !== 'all' ||
            filters.outcome !== 'all' ||
            filters.method !== '' ||
            filters.user !== null ||
            filters.search !== '',
    );

    function applyFilters(
        next: Partial<{
            channel: string;
            outcome: string;
            method: string;
            user: number | null;
            search: string;
            sort: Filters['sort'];
            direction: SortDirection;
            per_page: number;
        }>,
    ) {
        const query: Record<string, string> = {};
        const nextChannel = next.channel ?? filters.channel;
        const nextOutcome = next.outcome ?? filters.outcome;
        const nextMethod = next.method ?? filters.method;
        const nextUser =
            next.user === undefined ? (filters.user?.id ?? null) : next.user;
        const nextSearch = (next.search ?? search).trim();
        const nextSort = next.sort ?? filters.sort;
        const nextDirection = next.direction ?? filters.direction;
        const nextPerPage = next.per_page ?? filters.per_page;

        if (nextChannel !== 'all') {
            query.channel = nextChannel;
        }

        if (nextOutcome !== 'all') {
            query.outcome = nextOutcome;
        }

        if (nextMethod !== '') {
            query.method = nextMethod;
        }

        if (nextUser) {
            query.user = String(nextUser);
        }

        if (nextSearch !== '') {
            query.search = nextSearch;
        }

        if (nextSort !== 'created' || nextDirection !== 'desc') {
            query.sort = nextSort;
            query.direction = nextDirection;
        }

        if (nextPerPage !== pageSizes[1]) {
            query.per_page = String(nextPerPage);
        }

        router.get(apiRequestsRoute({ query }).url, undefined, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['requests', 'filters'],
        });
    }

    function clearFilters() {
        search = '';
        applyFilters({
            channel: 'all',
            outcome: 'all',
            method: '',
            user: null,
            search: '',
        });
    }

    function sortBy(column: string) {
        const sort = column as Filters['sort'];
        const direction: SortDirection =
            filters.sort === sort
                ? filters.direction === 'asc'
                    ? 'desc'
                    : 'asc'
                : 'desc';
        applyFilters({ sort, direction });
    }

    const formatDateTime = (value: string | null) =>
        value
            ? new Date(value).toLocaleString(undefined, {
                  month: 'short',
                  day: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
                  second: '2-digit',
              })
            : '–';

    const statusTone = (status: number): 'ok' | 'warning' | 'danger' =>
        status >= 500 ? 'danger' : status >= 400 ? 'warning' : 'ok';

    const methodClass: Record<string, string> = {
        GET: 'text-sky-700 dark:text-sky-300',
        POST: 'text-emerald-700 dark:text-emerald-300',
        PUT: 'text-amber-700 dark:text-amber-300',
        PATCH: 'text-amber-700 dark:text-amber-300',
        DELETE: 'text-red-700 dark:text-red-300',
    };
</script>

<AppHead title="API requests" />

<AdminPage
    title="API requests"
    description="Every call to the app API, the public API and the MCP server, newest first. Open one to see the request, the response and the exception when the server failed. Rows are kept for 30 days."
>
    {#snippet actions()}
        <p class="text-sm text-muted-foreground tabular-nums">
            {requests.total.toLocaleString()}
            {requests.total === 1 ? 'request' : 'requests'}
        </p>
    {/snippet}

    <div class="flex flex-col gap-4">
        {#if errorGroups.length > 0}
            <AdminPanel
                title="Server errors in the last 24 hours"
                description="Requests that failed with a 5xx or raised an exception, grouped by route and exception class. Open the newest example of each."
                padded={false}
            >
                <ul class="divide-y">
                    {#each errorGroups as group (group.method + group.route + group.exception)}
                        <li
                            class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 px-6 py-3 text-sm"
                        >
                            <div class="min-w-0">
                                <p class="font-mono text-xs">
                                    <span
                                        class="font-semibold text-muted-foreground"
                                        >{group.method}</span
                                    >
                                    {group.route}
                                </p>
                                <p
                                    class="truncate font-mono text-xs text-red-700 dark:text-red-300"
                                >
                                    {group.exception ?? 'No exception recorded'}
                                </p>
                            </div>
                            <div
                                class="flex items-center gap-4 text-xs whitespace-nowrap text-muted-foreground"
                            >
                                <span class="tabular-nums"
                                    >{group.count.toLocaleString()} ×</span
                                >
                                <Link
                                    href={showApiRequest(group.latest_id).url}
                                    class="font-medium text-foreground hover:underline"
                                    >Newest, {formatDateTime(
                                        group.latest_at,
                                    )}</Link
                                >
                            </div>
                        </li>
                    {/each}
                </ul>
            </AdminPanel>
        {/if}

        <div class="flex flex-wrap items-center gap-3">
            <SearchField
                bind:value={search}
                placeholder="Path, route, status, error or request id"
                label="Search requests"
                onSearch={(value) => applyFilters({ search: value })}
            />
            <SegmentedControl
                segments={channelSegments}
                value={filters.channel}
                label="Filter by channel"
                onSelect={(value) => applyFilters({ channel: String(value) })}
            />
            <SegmentedControl
                segments={outcomes}
                value={filters.outcome}
                label="Filter by outcome"
                onSelect={(value) => applyFilters({ outcome: String(value) })}
            />
            <select
                class="h-8 rounded-full border border-border/80 bg-panel pr-7 pl-3 text-xs font-medium text-foreground shadow-none"
                aria-label="Filter by method"
                value={filters.method}
                onchange={(event) =>
                    applyFilters({ method: event.currentTarget.value })}
            >
                {#each methods as method (method)}
                    <option value={method}
                        >{method === '' ? 'Any method' : method}</option
                    >
                {/each}
            </select>
            {#if filters.user}
                <FilterChip
                    label="User"
                    value={filters.user.name}
                    onRemove={() => applyFilters({ user: null })}
                />
            {/if}
        </div>

        <AdminPanel
            padded={false}
            footer={requests.total > 0 ? footer : undefined}
        >
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class={theadClass}>
                        <tr>
                            <SortHeader
                                column="created"
                                label="When"
                                sort={filters.sort}
                                direction={filters.direction}
                                onSort={sortBy}
                            />
                            <th class={thClass}>Channel</th>
                            <th class={thClass}>Request</th>
                            <SortHeader
                                column="status"
                                label="Status"
                                sort={filters.sort}
                                direction={filters.direction}
                                onSort={sortBy}
                            />
                            <th class={thClass}>User</th>
                            <SortHeader
                                column="duration"
                                label="Duration"
                                align="right"
                                sort={filters.sort}
                                direction={filters.direction}
                                onSort={sortBy}
                            />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        {#if requests.data.length === 0}
                            <TableEmpty
                                colspan={6}
                                message={isFiltered
                                    ? 'No API requests match these filters.'
                                    : 'No API requests have been logged yet.'}
                                filtered={isFiltered}
                                onClear={clearFilters}
                            />
                        {/if}
                        {#each requests.data as row (row.id)}
                            <tr class={rowClass}>
                                <td
                                    class={cn(
                                        tdClass,
                                        'whitespace-nowrap text-muted-foreground',
                                    )}
                                >
                                    <Link
                                        href={showApiRequest(row.id).url}
                                        class="tabular-nums hover:text-foreground hover:underline"
                                        >{formatDateTime(row.created_at)}</Link
                                    >
                                </td>
                                <td class={cn(tdClass, 'whitespace-nowrap')}
                                    >{channels[row.channel] ?? row.channel}</td
                                >
                                <td class={cn(tdClass, 'max-w-md')}>
                                    <Link
                                        href={showApiRequest(row.id).url}
                                        class="flex items-baseline gap-2 hover:underline"
                                    >
                                        <span
                                            class={cn(
                                                'w-12 shrink-0 font-mono text-[11px] font-semibold',
                                                methodClass[row.method] ??
                                                    'text-muted-foreground',
                                            )}>{row.method}</span
                                        >
                                        <span class="truncate font-mono text-xs"
                                            >/{row.path}</span
                                        >
                                    </Link>
                                    {#if row.route}
                                        <span
                                            class="block truncate pl-14 text-xs text-muted-foreground"
                                            >{row.route}</span
                                        >
                                    {/if}
                                </td>
                                <td class={tdClass}>
                                    <StatusDot tone={statusTone(row.status)}>
                                        <span class="tabular-nums"
                                            >{row.status}</span
                                        >
                                        {#if row.has_error}
                                            <span
                                                class="text-xs text-muted-foreground"
                                                >exception</span
                                            >
                                        {/if}
                                    </StatusDot>
                                </td>
                                <td class={cn(tdClass, 'max-w-48')}>
                                    {#if row.user}
                                        <Link
                                            href={showUser(row.user.id).url}
                                            class="block truncate hover:underline"
                                            >{row.user.name}</Link
                                        >
                                        <span
                                            class="block truncate text-xs text-muted-foreground"
                                            >{row.user.email}</span
                                        >
                                    {:else}
                                        <span class="text-muted-foreground"
                                            >–</span
                                        >
                                    {/if}
                                </td>
                                <td
                                    class={cn(
                                        tdClass,
                                        numericClass,
                                        row.duration_ms >= 1000 &&
                                            'text-amber-600 dark:text-amber-400',
                                    )}>{row.duration_ms.toLocaleString()} ms</td
                                >
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        </AdminPanel>
    </div>
</AdminPage>

{#snippet footer()}
    <PaginationFooter
        paginator={requests}
        {pageSizes}
        onPageSize={(perPage) => applyFilters({ per_page: perPage })}
        only={['requests', 'filters']}
    />
{/snippet}
