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
    import { index as usersRoute } from '@/routes/admin/users';

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
                dinners: number;
                dinner_plans: number;
                shopping_lists: number;
                ingredients: number;
                api_tokens: number;
                connected_apps: number;
            };
            signups: { day: string; value: number }[];
        };
        ai: {
            features: Record<string, string>;
            totals: {
                requests: number;
                provider_requests: number;
                cached: number;
                failed: number;
                avg_duration_ms: number;
                input_tokens: number;
                output_tokens: number;
                cost: number;
                categorization_users: number;
                suggestions_users: number;
            };
            daily: Record<string, DailyAi[]>;
            models: {
                feature: string;
                model: string;
                requests: number;
                input_tokens: number;
                output_tokens: number;
                cost: number;
            }[];
            households: {
                household_id: number;
                name: string;
                requests: number;
                failed: number;
                users: number;
                cost: number;
            }[];
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
    const cacheRate = $derived(
        percent(analytics.ai.totals.cached, analytics.ai.totals.requests),
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
            label: 'Households',
            value: number(analytics.app.totals.households),
            hint: `${number(analytics.app.totals.active_households)} active this week`,
        },
        {
            label: 'Dinners',
            value: number(analytics.app.totals.dinners),
            hint: `${number(analytics.app.totals.dinner_plans)} dinner plans`,
        },
        {
            label: 'Shopping lists',
            value: number(analytics.app.totals.shopping_lists),
            hint: `${number(analytics.app.totals.ingredients)} ingredients`,
        },
        {
            label: 'API tokens',
            value: number(analytics.app.totals.api_tokens),
            hint: `${number(analytics.app.totals.connected_apps)} connected apps`,
        },
    ]);

    const aiStats = $derived<Stat[]>([
        {
            label: 'Requests',
            value: number(analytics.ai.totals.requests),
            hint: `${number(analytics.ai.totals.provider_requests)} to the provider, ${cacheRate} from cache`,
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
            label: 'Tokens',
            value: number(
                analytics.ai.totals.input_tokens +
                    analytics.ai.totals.output_tokens,
            ),
            hint: `${number(analytics.ai.totals.input_tokens)} in, ${number(analytics.ai.totals.output_tokens)} out`,
        },
        {
            label: 'Reported cost',
            value: money(analytics.ai.totals.cost),
            hint: 'Only System One reports cost',
        },
        {
            label: 'Opted in',
            value: number(
                Math.max(
                    analytics.ai.totals.categorization_users,
                    analytics.ai.totals.suggestions_users,
                ),
            ),
            hint: `${number(analytics.ai.totals.categorization_users)} categorization, ${number(analytics.ai.totals.suggestions_users)} suggestions`,
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

    function aiSeries(rows: DailyAi[]) {
        return [
            { key: 'ok', label: 'Succeeded', values: rows.map((r) => r.ok) },
            {
                key: 'cached',
                label: 'Cached',
                values: rows.map((r) => r.cached),
            },
            {
                key: 'failed',
                label: 'Failed',
                values: rows.map((r) => r.failed),
            },
        ];
    }

    function meterClass(ratio: number): string {
        if (ratio >= 0.9) {
            return 'bg-red-500';
        }

        if (ratio >= 0.75) {
            return 'bg-amber-500';
        }

        return 'bg-emerald-500';
    }
</script>

<AppHead title="Analytics" />

<AdminPage
    title="Analytics"
    description="How the app and its AI features are used, {analytics.from} to {analytics.to}. Days are counted in UTC, so late-evening activity in Norway lands on the next day."
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
        <StatStrip stats={appStats} />
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
        <StatStrip stats={aiStats} />

        <div class="grid gap-3 lg:grid-cols-2">
            {#each Object.entries(analytics.ai.daily) as [feature, rows] (feature)}
                <AdminPanel>
                    <BarChart
                        title="{analytics.ai.features[feature] ??
                            feature} per day"
                        labels={rows.map((row) => row.day)}
                        series={aiSeries(rows)}
                    />
                </AdminPanel>
            {/each}
        </div>

        <div class="grid gap-3 lg:grid-cols-5">
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

            <AdminPanel
                title="Models used"
                description="Includes cache hits. Tokens and cost count successful provider calls only."
                padded={false}
                class="lg:col-span-3"
            >
                {#if analytics.ai.models.length === 0}
                    <p
                        class="px-5 py-8 text-center text-sm text-muted-foreground"
                    >
                        No AI requests in this period. Usage shows up here as
                        soon as a user opts in.
                    </p>
                {:else}
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b text-left text-xs text-muted-foreground"
                                >
                                    <th class="px-5 py-2.5 font-medium"
                                        >Feature</th
                                    >
                                    <th class="px-5 py-2.5 font-medium"
                                        >Model</th
                                    >
                                    <th
                                        class="px-5 py-2.5 text-right font-medium"
                                        >Requests</th
                                    >
                                    <th
                                        class="px-5 py-2.5 text-right font-medium"
                                        >Tokens in / out</th
                                    >
                                    <th
                                        class="px-5 py-2.5 text-right font-medium"
                                        >Cost</th
                                    >
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                {#each analytics.ai.models as row (row.feature + row.model)}
                                    <tr class="hover:bg-muted/40">
                                        <td
                                            class="px-5 py-2.5 whitespace-nowrap"
                                            >{analytics.ai.features[
                                                row.feature
                                            ] ?? row.feature}</td
                                        >
                                        <td
                                            class="px-5 py-2.5 font-mono text-xs break-all text-muted-foreground"
                                            >{row.model}</td
                                        >
                                        <td
                                            class="px-5 py-2.5 text-right tabular-nums"
                                            >{number(row.requests)}</td
                                        >
                                        <td
                                            class="px-5 py-2.5 text-right tabular-nums whitespace-nowrap"
                                            >{number(row.input_tokens)} / {number(
                                                row.output_tokens,
                                            )}</td
                                        >
                                        <td
                                            class="px-5 py-2.5 text-right tabular-nums"
                                            >{money(row.cost)}</td
                                        >
                                    </tr>
                                {/each}
                            </tbody>
                        </table>
                    </div>
                {/if}
            </AdminPanel>
        </div>

        <AdminPanel
            title="Households using AI most"
            description="Top ten by requests in this period, including cache hits. Click a household to see its members."
            padded={false}
        >
            {#if analytics.ai.households.length === 0}
                <p class="px-5 py-8 text-center text-sm text-muted-foreground">
                    No household has used AI assistance in this period.
                </p>
            {:else}
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b text-left text-xs text-muted-foreground"
                            >
                                <th class="px-5 py-2.5 font-medium"
                                    >Household</th
                                >
                                <th class="px-5 py-2.5 text-right font-medium"
                                    >Requests</th
                                >
                                <th class="px-5 py-2.5 text-right font-medium"
                                    >Failed</th
                                >
                                <th class="px-5 py-2.5 text-right font-medium"
                                    >Members using it</th
                                >
                                <th class="px-5 py-2.5 text-right font-medium"
                                    >Cost</th
                                >
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            {#each analytics.ai.households as row (row.household_id)}
                                <tr class="hover:bg-muted/40">
                                    <td class="px-5 py-2.5 font-medium">
                                        <Link
                                            href={usersRoute({
                                                query: {
                                                    household: row.household_id,
                                                },
                                            }).url}
                                            class="hover:underline"
                                            >{row.name}</Link
                                        >
                                    </td>
                                    <td
                                        class="px-5 py-2.5 text-right tabular-nums"
                                        >{number(row.requests)}</td
                                    >
                                    <td
                                        class={cn(
                                            'px-5 py-2.5 text-right tabular-nums',
                                            row.failed > 0 &&
                                                'text-amber-600 dark:text-amber-400',
                                        )}>{number(row.failed)}</td
                                    >
                                    <td
                                        class="px-5 py-2.5 text-right tabular-nums"
                                        >{number(row.users)}</td
                                    >
                                    <td
                                        class="px-5 py-2.5 text-right tabular-nums"
                                        >{money(row.cost)}</td
                                    >
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            {/if}
        </AdminPanel>
    </AdminSection>
</AdminPage>
