<script module lang="ts">
    import { analytics as analyticsRoute } from '@/routes/admin';
    import {
        index as aiRequestsRoute,
        show as showAiRequest,
    } from '@/routes/admin/ai-requests';

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
        feature: string;
        model: string;
        status: 'ok' | 'failed' | 'cached';
        duration_ms: number;
        tokens: number;
        cost: number;
        summary: string | null;
        has_error: boolean;
        user: { id: number; name: string; email: string } | null;
        household: { id: number; name: string } | null;
        created_at: string | null;
    };

    type ErrorGroup = {
        feature: string;
        model: string;
        exception: string | null;
        count: number;
        latest_id: number;
        latest_at: string | null;
    };

    type Filters = {
        status: string;
        feature: string;
        user: { id: number; name: string } | null;
        household: { id: number; name: string } | null;
        search: string;
        sort: 'created' | 'duration' | 'tokens' | 'cost';
        direction: SortDirection;
        per_page: number;
    };

    let {
        requests,
        errorGroups,
        features,
        filters,
        pageSizes,
    }: {
        requests: Paginator & { data: Row[] };
        errorGroups: ErrorGroup[];
        features: Record<string, string>;
        filters: Filters;
        pageSizes: number[];
    } = $props();

    const statuses = [
        { value: 'all', label: 'All' },
        { value: 'ok', label: 'Succeeded' },
        { value: 'failed', label: 'Failed' },
        { value: 'cached', label: 'Cached' },
    ];
    const featureSegments = $derived([
        { value: 'all', label: 'All features' },
        ...Object.entries(features).map(([value, label]) => ({
            value,
            label,
        })),
    ]);

    // svelte-ignore state_referenced_locally
    let search = $state(filters.search);

    const isFiltered = $derived(
        filters.status !== 'all' ||
            filters.feature !== 'all' ||
            filters.user !== null ||
            filters.household !== null ||
            filters.search !== '',
    );

    function applyFilters(
        next: Partial<{
            status: string;
            feature: string;
            user: number | null;
            household: number | null;
            search: string;
            sort: Filters['sort'];
            direction: SortDirection;
            per_page: number;
        }>,
    ) {
        const query: Record<string, string> = {};
        const nextStatus = next.status ?? filters.status;
        const nextFeature = next.feature ?? filters.feature;
        const nextUser =
            next.user === undefined ? (filters.user?.id ?? null) : next.user;
        const nextHousehold =
            next.household === undefined
                ? (filters.household?.id ?? null)
                : next.household;
        const nextSearch = (next.search ?? search).trim();
        const nextSort = next.sort ?? filters.sort;
        const nextDirection = next.direction ?? filters.direction;
        const nextPerPage = next.per_page ?? filters.per_page;

        if (nextStatus !== 'all') {
            query.status = nextStatus;
        }

        if (nextFeature !== 'all') {
            query.feature = nextFeature;
        }

        if (nextUser) {
            query.user = String(nextUser);
        }

        if (nextHousehold) {
            query.household = String(nextHousehold);
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

        router.get(aiRequestsRoute({ query }).url, undefined, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['requests', 'filters'],
        });
    }

    function clearFilters() {
        search = '';
        applyFilters({
            status: 'all',
            feature: 'all',
            user: null,
            household: null,
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
    const money = (value: number) =>
        value === 0 ? '–' : `$${value.toFixed(value < 0.01 ? 5 : 3)}`;
    const latency = (row: Row) =>
        row.status === 'cached'
            ? '–'
            : `${row.duration_ms.toLocaleString()} ms`;

    const statusTone: Record<Row['status'], 'ok' | 'danger' | 'neutral'> = {
        ok: 'ok',
        failed: 'danger',
        cached: 'neutral',
    };
    const statusLabel: Record<Row['status'], string> = {
        ok: 'Succeeded',
        failed: 'Failed',
        cached: 'Cached',
    };
</script>

<AppHead title="AI requests" />

<AdminPage
    title="AI requests"
    description="Every AI request, newest first. Open one to see exactly what was sent to the provider and what came back. Bodies are kept for 30 days."
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
                title="Failures in the last 24 hours"
                description="Failed provider calls grouped by feature, model and exception. Open the newest example of each."
                padded={false}
            >
                <ul class="divide-y">
                    {#each errorGroups as group (group.feature + group.model + group.exception)}
                        <li
                            class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 px-6 py-3 text-sm"
                        >
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {features[group.feature] ?? group.feature}
                                    <span
                                        class="ml-2 font-mono text-[11px] text-muted-foreground"
                                        >{group.model}</span
                                    >
                                </p>
                                <p
                                    class="truncate font-mono text-xs text-red-700 dark:text-red-300"
                                >
                                    {group.exception ?? 'Unknown error'}
                                </p>
                            </div>
                            <div
                                class="flex items-center gap-4 text-xs whitespace-nowrap text-muted-foreground"
                            >
                                <span class="tabular-nums"
                                    >{group.count.toLocaleString()} ×</span
                                >
                                <Link
                                    href={showAiRequest(group.latest_id).url}
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
                placeholder="Ingredient, dinner, error, model or request id"
                label="Search requests"
                onSearch={(value) => applyFilters({ search: value })}
            />
            <SegmentedControl
                segments={statuses}
                value={filters.status}
                label="Filter by status"
                onSelect={(value) => applyFilters({ status: String(value) })}
            />
            <SegmentedControl
                segments={featureSegments}
                value={filters.feature}
                label="Filter by feature"
                onSelect={(value) => applyFilters({ feature: String(value) })}
            />
            {#if filters.user}
                <FilterChip
                    label="User"
                    value={filters.user.name}
                    onRemove={() => applyFilters({ user: null })}
                />
            {/if}
            {#if filters.household}
                <FilterChip
                    label="Household"
                    value={filters.household.name}
                    onRemove={() => applyFilters({ household: null })}
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
                            <th class={thClass}>Feature</th>
                            <th class={thClass}>About</th>
                            <th class={thClass}>User</th>
                            <th class={thClass}>Status</th>
                            <SortHeader
                                column="duration"
                                label="Latency"
                                align="right"
                                sort={filters.sort}
                                direction={filters.direction}
                                onSort={sortBy}
                            />
                            <SortHeader
                                column="tokens"
                                label="Tokens"
                                align="right"
                                sort={filters.sort}
                                direction={filters.direction}
                                onSort={sortBy}
                            />
                            <SortHeader
                                column="cost"
                                label="Cost"
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
                                colspan={8}
                                message={isFiltered
                                    ? 'No AI requests match these filters.'
                                    : 'No AI requests have been made yet.'}
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
                                        href={showAiRequest(row.id).url}
                                        class="tabular-nums hover:text-foreground hover:underline"
                                        >{formatDateTime(row.created_at)}</Link
                                    >
                                </td>
                                <td class={cn(tdClass, 'whitespace-nowrap')}>
                                    <span
                                        >{features[row.feature] ??
                                            row.feature}</span
                                    >
                                    <span
                                        class="block font-mono text-[11px] text-muted-foreground"
                                        >{row.model}</span
                                    >
                                </td>
                                <td class={cn(tdClass, 'max-w-64')}>
                                    <Link
                                        href={showAiRequest(row.id).url}
                                        class="block truncate font-medium hover:underline"
                                        >{row.summary ?? '–'}</Link
                                    >
                                    {#if row.household}
                                        <span
                                            class="block truncate text-xs text-muted-foreground"
                                            >{row.household.name}</span
                                        >
                                    {/if}
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
                                            >Deleted user</span
                                        >
                                    {/if}
                                </td>
                                <td class={tdClass}>
                                    <StatusDot tone={statusTone[row.status]}>
                                        {statusLabel[row.status]}
                                    </StatusDot>
                                </td>
                                <td
                                    class={cn(
                                        tdClass,
                                        numericClass,
                                        row.status === 'failed' &&
                                            'text-muted-foreground',
                                    )}>{latency(row)}</td
                                >
                                <td class={cn(tdClass, numericClass)}
                                    >{row.tokens === 0
                                        ? '–'
                                        : row.tokens.toLocaleString()}</td
                                >
                                <td class={cn(tdClass, numericClass)}
                                    >{money(row.cost)}</td
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
    />
{/snippet}
