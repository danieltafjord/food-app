<script module lang="ts">
    import { analytics as analyticsRoute } from '@/routes/admin';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Admin',
                href: analyticsRoute(),
            },
            {
                title: 'Analytics',
                href: analyticsRoute(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import RefreshCw from 'lucide-svelte/icons/refresh-cw';
    import AdminPage from '@/components/admin/AdminPage.svelte';
    import AdminPanel from '@/components/admin/AdminPanel.svelte';
    import AdminSection from '@/components/admin/AdminSection.svelte';
    import SegmentedControl from '@/components/admin/SegmentedControl.svelte';
    import StatStrip from '@/components/admin/StatStrip.svelte';
    import type { Stat } from '@/components/admin/StatStrip.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import BarChart from '@/components/BarChart.svelte';
    import { cn } from '@/lib/utils';
    import { index as aiRequestsRoute } from '@/routes/admin/ai-requests';
    import { index as apiRequestsRoute } from '@/routes/admin/api-requests';

    type DailyAi = { day: string; ok: number; cached: number; failed: number };

    type Analytics = {
        days: number;
        from: string;
        to: string;
        app: {
            totals: {
                users: number;
                verified_users: number;
                new_users: number;
                households: number;
                active_households: number;
            };
            signups: { day: string; value: number }[];
        };
        api: {
            requests: number;
            client_errors: number;
            server_errors: number;
        };
        ai: {
            features: Record<string, string>;
            totals: {
                requests: number;
                provider_requests: number;
                cached: number;
                failed: number;
                avg_duration_ms: number;
                cost: number;
            };
            daily: DailyAi[];
            today: { feature: string; used: number; limit: number }[];
        };
    };

    let {
        analytics,
        generatedAt,
        cacheSeconds,
    }: { analytics: Analytics; generatedAt: string; cacheSeconds: number } =
        $props();

    const generatedLabel = $derived(
        new Date(generatedAt).toLocaleTimeString(undefined, {
            hour: '2-digit',
            minute: '2-digit',
        }),
    );
    const refreshHref = $derived(
        analyticsRoute({ query: { days: analytics.days, refresh: 1 } }).url,
    );

    const windows = [7, 30, 90].map((days) => ({
        value: days,
        label: `${days} days`,
        href: analyticsRoute({ query: { days } }).url,
    }));

    const number = (value: number) => value.toLocaleString();
    const money = (value: number) =>
        value === 0 ? '$0' : `$${value.toFixed(value < 0.01 ? 5 : 3)}`;
    const percent = (part: number, whole: number) =>
        whole === 0 ? '–' : `${Math.round((part / whole) * 100)}%`;

    const failureRatio = $derived(
        analytics.ai.totals.provider_requests === 0
            ? 0
            : analytics.ai.totals.failed /
                  analytics.ai.totals.provider_requests,
    );

    const appStats = $derived<Stat[]>([
        {
            label: 'Users',
            value: number(analytics.app.totals.users),
            hint: `${number(analytics.app.totals.verified_users)} verified`,
        },
        {
            label: `New in ${analytics.days} days`,
            value: number(analytics.app.totals.new_users),
            hint: 'Accounts created in this window',
        },
        {
            label: 'Active households',
            value: number(analytics.app.totals.active_households),
            hint: `Synced this week, of ${number(analytics.app.totals.households)} in total`,
        },
        {
            label: 'API requests',
            value: number(analytics.api.requests),
            hint: `${number(analytics.api.client_errors)} client errors, ${number(analytics.api.server_errors)} server errors`,
            tone:
                analytics.api.server_errors > 0
                    ? 'danger'
                    : analytics.api.client_errors > 0
                      ? 'warning'
                      : 'default',
        },
    ]);

    const aiStats = $derived<Stat[]>([
        {
            label: 'AI requests',
            value: number(analytics.ai.totals.requests),
            hint: `${number(analytics.ai.totals.provider_requests)} to the provider, ${percent(analytics.ai.totals.cached, analytics.ai.totals.requests)} from cache`,
        },
        {
            label: 'Failure rate',
            value: percent(
                analytics.ai.totals.failed,
                analytics.ai.totals.provider_requests,
            ),
            hint: `${number(analytics.ai.totals.failed)} failed provider calls`,
            tone:
                failureRatio >= 0.1
                    ? 'danger'
                    : failureRatio >= 0.03
                      ? 'warning'
                      : 'default',
        },
        {
            label: 'Average latency',
            value: `${number(analytics.ai.totals.avg_duration_ms)} ms`,
            hint: 'Successful provider calls only',
        },
        {
            label: 'Reported cost',
            value: money(analytics.ai.totals.cost),
            hint: 'Only System One reports cost',
        },
    ]);

    const signupLabels = $derived(analytics.app.signups.map((row) => row.day));
    const signupSeries = $derived([
        {
            key: 'signups',
            label: 'Sign-ups',
            values: analytics.app.signups.map((row) => row.value),
        },
    ]);

    const aiLabels = $derived(analytics.ai.daily.map((row) => row.day));
    const aiSeries = $derived([
        {
            key: 'ok',
            label: 'Succeeded',
            values: analytics.ai.daily.map((r) => r.ok),
        },
        {
            key: 'cached',
            label: 'Cached',
            values: analytics.ai.daily.map((r) => r.cached),
        },
        {
            key: 'failed',
            label: 'Failed',
            values: analytics.ai.daily.map((r) => r.failed),
        },
    ]);

    function meterClass(ratio: number): string {
        if (ratio >= 0.9) {
            return 'bg-red-500';
        }

        if (ratio >= 0.75) {
            return 'bg-amber-500';
        }

        return 'bg-emerald-500';
    }

    const linkClass =
        'text-sm font-medium text-muted-foreground hover:text-foreground hover:underline';
</script>

<AppHead title="Analytics" />

<AdminPage
    title="Analytics"
    description="The numbers worth watching, {analytics.from} to {analytics.to}. Days are counted in UTC. Per-request detail is in the AI and API request logs."
>
    {#snippet actions()}
        <p class="text-xs text-muted-foreground tabular-nums">
            Counted at {generatedLabel}, kept for {Math.round(
                cacheSeconds / 60,
            )} min
        </p>
        <Link
            href={refreshHref}
            class="inline-flex size-8 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
            aria-label="Recount now"
            preserveScroll
        >
            <RefreshCw class="size-4" />
        </Link>
        <SegmentedControl
            segments={windows}
            value={analytics.days}
            label="Time range"
        />
    {/snippet}

    <AdminSection title="App">
        {#snippet actions()}
            <Link
                href={apiRequestsRoute({ query: { outcome: 'errors' } }).url}
                class={linkClass}>Failed API requests</Link
            >
        {/snippet}
        <StatStrip stats={appStats} columns={4} />
        <AdminPanel>
            <BarChart
                title="Sign-ups per day"
                labels={signupLabels}
                series={signupSeries}
                valueLabel="sign-ups"
            />
        </AdminPanel>
    </AdminSection>

    <AdminSection title="AI assistance">
        {#snippet actions()}
            <Link href={aiRequestsRoute().url} class={linkClass}
                >AI request log</Link
            >
        {/snippet}
        <StatStrip stats={aiStats} columns={4} />

        <div class="grid gap-3 lg:grid-cols-5">
            <AdminPanel class="lg:col-span-3">
                <BarChart
                    title="AI requests per day"
                    labels={aiLabels}
                    series={aiSeries}
                />
            </AdminPanel>

            <AdminPanel
                title="Today's global budget"
                description="Resets at 00:00 UTC. Change the budgets on the AI models page."
                class="lg:col-span-2"
            >
                <ul class="flex flex-col gap-5">
                    {#each analytics.ai.today as row (row.feature)}
                        {@const ratio =
                            row.limit === 0
                                ? 1
                                : Math.min(1, row.used / row.limit)}
                        <li class="flex flex-col gap-2">
                            <div
                                class="flex items-baseline justify-between gap-3 text-sm"
                            >
                                <span
                                    >{analytics.ai.features[row.feature] ??
                                        row.feature}</span
                                >
                                <span class="text-xs tabular-nums">
                                    <span class="font-medium"
                                        >{number(row.used)}</span
                                    >
                                    <span class="text-muted-foreground"
                                        >/ {number(row.limit)}</span
                                    >
                                </span>
                            </div>
                            <div
                                class="h-2 w-full overflow-hidden rounded-full bg-muted"
                                role="meter"
                                aria-valuemin="0"
                                aria-valuemax={row.limit}
                                aria-valuenow={row.used}
                                aria-label="{analytics.ai.features[
                                    row.feature
                                ] ?? row.feature} usage today"
                            >
                                <div
                                    class={cn(
                                        'h-full rounded-full transition-[width]',
                                        meterClass(ratio),
                                    )}
                                    style="width: {ratio * 100}%"
                                ></div>
                            </div>
                        </li>
                    {/each}
                </ul>
            </AdminPanel>
        </div>
    </AdminSection>
</AdminPage>
