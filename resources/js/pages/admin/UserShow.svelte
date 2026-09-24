<script module lang="ts">
    import AppLayout from '@/layouts/AppLayout.svelte';
    import { index as usersRoute } from '@/routes/admin/users';

    export const layout = [
        AppLayout,
        {
            breadcrumbs: [
                {
                    title: 'Admin',
                    href: usersRoute(),
                },
                {
                    title: 'Users',
                    href: usersRoute(),
                },
            ],
        },
    ];
</script>

<script lang="ts">
    import { Link, page, router, useForm } from '@inertiajs/svelte';
    import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
    import AdminPage from '@/components/admin/AdminPage.svelte';
    import AdminPanel from '@/components/admin/AdminPanel.svelte';
    import AuditTrail from '@/components/admin/AuditTrail.svelte';
    import type { AuditEntry } from '@/components/admin/AuditTrail.svelte';
    import StatStrip from '@/components/admin/StatStrip.svelte';
    import type { Stat } from '@/components/admin/StatStrip.svelte';
    import StatusDot from '@/components/admin/StatusDot.svelte';
    import { theadClass, thClass } from '@/components/admin/table';
    import AppHead from '@/components/AppHead.svelte';
    import ConfirmDialog from '@/components/ConfirmDialog.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Checkbox } from '@/components/ui/checkbox';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { cn } from '@/lib/utils';
    import {
        index as aiRequestsRoute,
        show as showAiRequest,
    } from '@/routes/admin/ai-requests';
    import { index as apiRequestsRoute } from '@/routes/admin/api-requests';

    type UserRow = {
        id: number;
        name: string;
        email: string;
        is_admin: boolean;
        email_verified_at: string | null;
        deactivated_at: string | null;
        two_factor: boolean;
        households_count: number;
        ai_categorization_enabled: boolean;
        ai_suggestions_enabled: boolean;
        locale: string | null;
        created_at: string | null;
    };

    type HouseholdRow = {
        id: number;
        name: string;
        role: string;
        members_count: number;
        is_current: boolean;
        joined_at: string | null;
    };

    type TokenRow = {
        id: number;
        name: string | null;
        household_name: string | null;
        can_write: boolean;
        last_used_at: string | null;
        expires_at: string | null;
    };

    type AppRow = {
        id: number;
        name: string | null;
        household_name: string | null;
        can_write: boolean;
        connected_at: string | null;
    };

    type AiRow = {
        id: number;
        feature: string;
        model: string;
        status: 'ok' | 'failed' | 'cached';
        duration_ms: number;
        tokens: number;
        cost: number;
        created_at: string | null;
    };

    let {
        user,
        households,
        tokens,
        connectedApps,
        aiRequests,
        aiTotals,
        actions: auditEntries,
    }: {
        user: UserRow;
        households: HouseholdRow[];
        tokens: TokenRow[];
        connectedApps: AppRow[];
        aiRequests: AiRow[];
        aiTotals: { requests: number; last_30_days: number; cost: number };
        actions: AuditEntry[];
    } = $props();

    const currentUserId = $derived(page.props.auth.user?.id);
    const isSelf = $derived(user.id === currentUserId);

    const formatDate = (value: string | null) =>
        value
            ? new Date(value).toLocaleDateString(undefined, {
                  year: 'numeric',
                  month: 'short',
                  day: 'numeric',
              })
            : '–';
    const formatDateTime = (value: string | null) =>
        value
            ? new Date(value).toLocaleString(undefined, {
                  month: 'short',
                  day: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
              })
            : '–';
    const money = (value: number) =>
        value === 0 ? '$0' : `$${value.toFixed(value < 0.01 ? 5 : 3)}`;

    const stats = $derived<Stat[]>([
        {
            label: 'Households',
            value: String(user.households_count),
            hint:
                households.filter((h) => h.role === 'owner').length + ' owned',
        },
        {
            label: 'API tokens',
            value: String(tokens.length),
            hint: `${connectedApps.length} connected apps`,
        },
        {
            label: 'AI requests',
            value: aiTotals.requests.toLocaleString(),
            hint: `${aiTotals.last_30_days.toLocaleString()} in the last 30 days`,
        },
        {
            label: 'AI cost',
            value: money(aiTotals.cost),
            hint: 'Reported by the provider',
        },
    ]);

    // Forms seed from the initial props on purpose; a save reloads the page.
    // svelte-ignore state_referenced_locally
    const editForm = useForm({
        name: user.name,
        email: user.email,
        is_admin: user.is_admin,
        email_verified: user.email_verified_at !== null,
    });

    const isDirty = $derived(
        editForm.name !== user.name ||
            editForm.email !== user.email ||
            editForm.is_admin !== user.is_admin ||
            editForm.email_verified !== (user.email_verified_at !== null),
    );

    function submitEdit(event: SubmitEvent) {
        event.preventDefault();
        editForm.patch(UserController.update.url(user.id), {
            preserveScroll: true,
        });
    }

    let busy = $state(false);
    let deactivating = $state(false);
    let deleting = $state(false);
    let deleteError = $state('');

    function toggleActive() {
        busy = true;
        const url = user.deactivated_at
            ? UserController.reactivate.url(user.id)
            : UserController.deactivate.url(user.id);
        router.post(url, undefined, {
            preserveScroll: true,
            onFinish: () => {
                busy = false;
                deactivating = false;
            },
        });
    }

    function confirmDelete(typed: string) {
        busy = true;
        deleteError = '';
        router.delete(UserController.destroy.url(user.id), {
            data: { confirmation: typed },
            onError: (errors) => {
                deleteError =
                    errors.confirmation ?? 'The account could not be deleted.';
            },
            onFinish: () => (busy = false),
        });
    }

    const headerClass = thClass;
    const panelLinkClass =
        'text-xs font-medium text-muted-foreground hover:text-foreground hover:underline';
    const pillClass =
        'rounded-full border border-border/80 bg-muted/60 px-2 py-px text-[11px] font-medium text-muted-foreground';
    const statusTone: Record<AiRow['status'], 'ok' | 'danger' | 'neutral'> = {
        ok: 'ok',
        failed: 'danger',
        cached: 'neutral',
    };
</script>

<AppHead title={user.name} />

<AdminPage title={user.name} description={user.email}>
    {#snippet actions()}
        <div class="flex flex-wrap items-center gap-2">
            {#if user.deactivated_at}
                <StatusDot tone="danger"
                    >Deactivated {formatDate(user.deactivated_at)}</StatusDot
                >
            {:else if !user.email_verified_at}
                <StatusDot tone="warning">Unverified</StatusDot>
            {:else}
                <StatusDot tone="ok">Active</StatusDot>
            {/if}
            {#if user.is_admin}
                <span class={pillClass}>Admin</span>
            {/if}
            {#if user.two_factor}
                <span class={pillClass}>2FA</span>
            {/if}
            {#if isSelf}
                <span class={pillClass}>You</span>
            {/if}
        </div>
    {/snippet}

    <div class="flex flex-col gap-6">
        <StatStrip {stats} columns={4} />

        <div class="grid gap-6 lg:grid-cols-5">
            <div class="flex flex-col gap-6 lg:col-span-3">
                <AdminPanel
                    title="Households"
                    description="Every household this account belongs to."
                    padded={false}
                >
                    {#if households.length === 0}
                        <p class="px-6 pb-6 text-sm text-muted-foreground">
                            Not a member of any household.
                        </p>
                    {:else}
                        <table class="w-full text-sm">
                            <thead class={theadClass}>
                                <tr>
                                    <th class={headerClass}>Household</th>
                                    <th class={headerClass}>Role</th>
                                    <th class={cn(headerClass, 'text-right')}
                                        >Members</th
                                    >
                                    <th
                                        class={cn(
                                            headerClass,
                                            'hidden sm:table-cell',
                                        )}>Joined</th
                                    >
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                {#each households as household (household.id)}
                                    <tr class="hover:bg-muted/40">
                                        <td class="px-5 py-2.5">
                                            <Link
                                                href={usersRoute({
                                                    query: {
                                                        household: household.id,
                                                    },
                                                }).url}
                                                class="font-medium hover:underline"
                                                >{household.name}</Link
                                            >
                                            {#if household.is_current}
                                                <span
                                                    class={cn(
                                                        pillClass,
                                                        'ml-1.5',
                                                    )}>Current</span
                                                >
                                            {/if}
                                        </td>
                                        <td
                                            class="px-5 py-2.5 capitalize text-muted-foreground"
                                            >{household.role}</td
                                        >
                                        <td
                                            class="px-5 py-2.5 text-right tabular-nums"
                                            >{household.members_count}</td
                                        >
                                        <td
                                            class="hidden px-5 py-2.5 whitespace-nowrap text-muted-foreground sm:table-cell"
                                            >{formatDate(
                                                household.joined_at,
                                            )}</td
                                        >
                                    </tr>
                                {/each}
                            </tbody>
                        </table>
                    {/if}
                </AdminPanel>

                <AdminPanel
                    title="Recent AI requests"
                    description="The last 20 requests. Open one to see what was sent and what came back."
                    padded={false}
                >
                    {#snippet actions()}
                        <Link
                            href={aiRequestsRoute({
                                query: { user: user.id },
                            }).url}
                            class={panelLinkClass}>All AI requests</Link
                        >
                        <Link
                            href={apiRequestsRoute({
                                query: { user: user.id },
                            }).url}
                            class={panelLinkClass}>API requests</Link
                        >
                    {/snippet}
                    {#if aiRequests.length === 0}
                        <p class="px-6 pb-6 text-sm text-muted-foreground">
                            No AI requests yet.
                            {#if !user.ai_categorization_enabled && !user.ai_suggestions_enabled}
                                Both features are off for this account.
                            {/if}
                        </p>
                    {:else}
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class={theadClass}>
                                    <tr>
                                        <th class={headerClass}>When</th>
                                        <th class={headerClass}>Feature</th>
                                        <th class={headerClass}>Status</th>
                                        <th
                                            class={cn(
                                                headerClass,
                                                'text-right',
                                            )}>Latency</th
                                        >
                                        <th
                                            class={cn(
                                                headerClass,
                                                'text-right',
                                            )}>Tokens</th
                                        >
                                        <th
                                            class={cn(
                                                headerClass,
                                                'text-right',
                                            )}>Cost</th
                                        >
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    {#each aiRequests as request (request.id)}
                                        <tr class="hover:bg-muted/40">
                                            <td
                                                class="px-5 py-2.5 whitespace-nowrap text-muted-foreground"
                                            >
                                                <Link
                                                    href={showAiRequest(
                                                        request.id,
                                                    ).url}
                                                    class="hover:underline"
                                                    >{formatDateTime(
                                                        request.created_at,
                                                    )}</Link
                                                >
                                            </td>
                                            <td class="px-5 py-2.5">
                                                <span class="capitalize"
                                                    >{request.feature}</span
                                                >
                                                <span
                                                    class="block font-mono text-[11px] text-muted-foreground"
                                                    >{request.model}</span
                                                >
                                            </td>
                                            <td class="px-5 py-2.5">
                                                <StatusDot
                                                    tone={statusTone[
                                                        request.status
                                                    ]}
                                                >
                                                    <span class="capitalize"
                                                        >{request.status}</span
                                                    >
                                                </StatusDot>
                                            </td>
                                            <td
                                                class="px-5 py-2.5 text-right tabular-nums whitespace-nowrap"
                                                >{request.status === 'cached'
                                                    ? '–'
                                                    : `${request.duration_ms} ms`}</td
                                            >
                                            <td
                                                class="px-5 py-2.5 text-right tabular-nums"
                                                >{request.tokens.toLocaleString()}</td
                                            >
                                            <td
                                                class="px-5 py-2.5 text-right tabular-nums"
                                                >{money(request.cost)}</td
                                            >
                                        </tr>
                                    {/each}
                                </tbody>
                            </table>
                        </div>
                    {/if}
                </AdminPanel>

                <div class="grid gap-6 md:grid-cols-2">
                    <AdminPanel
                        title="API tokens"
                        description="Created by the user."
                    >
                        {#if tokens.length === 0}
                            <p class="text-sm text-muted-foreground">
                                No active tokens.
                            </p>
                        {:else}
                            <ul class="flex flex-col gap-3 text-sm">
                                {#each tokens as token (token.id)}
                                    <li>
                                        <p
                                            class="flex items-center gap-2 font-medium"
                                        >
                                            {token.name}
                                            <span class={pillClass}
                                                >{token.can_write
                                                    ? 'Read & write'
                                                    : 'Read only'}</span
                                            >
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {token.household_name} · last used {formatDate(
                                                token.last_used_at,
                                            )} · expires {formatDate(
                                                token.expires_at,
                                            )}
                                        </p>
                                    </li>
                                {/each}
                            </ul>
                        {/if}
                    </AdminPanel>
                    <AdminPanel
                        title="Connected apps"
                        description="OAuth clients the user approved."
                    >
                        {#if connectedApps.length === 0}
                            <p class="text-sm text-muted-foreground">
                                No connected apps.
                            </p>
                        {:else}
                            <ul class="flex flex-col gap-3 text-sm">
                                {#each connectedApps as app (app.id)}
                                    <li>
                                        <p
                                            class="flex items-center gap-2 font-medium"
                                        >
                                            {app.name}
                                            <span class={pillClass}
                                                >{app.can_write
                                                    ? 'Read & write'
                                                    : 'Read only'}</span
                                            >
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {app.household_name} · connected {formatDate(
                                                app.connected_at,
                                            )}
                                        </p>
                                    </li>
                                {/each}
                            </ul>
                        {/if}
                    </AdminPanel>
                </div>
            </div>

            <div class="flex flex-col gap-6 lg:col-span-2">
                <form onsubmit={submitEdit}>
                    <AdminPanel
                        title="Account"
                        description="Joined {formatDate(
                            user.created_at,
                        )} · language {user.locale ?? 'en'} · AI {[
                            user.ai_categorization_enabled && 'categorization',
                            user.ai_suggestions_enabled && 'suggestions',
                        ]
                            .filter(Boolean)
                            .join(', ') || 'off'}"
                    >
                        <div class="flex flex-col gap-4">
                            <div class="grid gap-2">
                                <Label for="edit-name">Name</Label>
                                <Input
                                    id="edit-name"
                                    class="h-10 rounded-full px-4 shadow-none"
                                    bind:value={editForm.name}
                                    required
                                    maxlength={255}
                                />
                                <InputError message={editForm.errors.name} />
                            </div>
                            <div class="grid gap-2">
                                <Label for="edit-email">E-mail</Label>
                                <Input
                                    id="edit-email"
                                    class="h-10 rounded-full px-4 shadow-none"
                                    type="email"
                                    bind:value={editForm.email}
                                    required
                                    maxlength={255}
                                />
                                <InputError message={editForm.errors.email} />
                            </div>
                            <div class="space-y-3">
                                <label class="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        bind:checked={editForm.email_verified}
                                    />
                                    E-mail verified
                                </label>
                                <InputError
                                    message={editForm.errors.email_verified}
                                />
                                <label class="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        bind:checked={editForm.is_admin}
                                        disabled={isSelf}
                                    />
                                    Admin access
                                </label>
                                <InputError
                                    message={editForm.errors.is_admin}
                                />
                            </div>
                        </div>

                        {#snippet footer()}
                            <span class="text-xs text-muted-foreground">
                                {#if editForm.recentlySuccessful}
                                    Saved
                                {:else if isDirty}
                                    Unsaved changes
                                {/if}
                            </span>
                            <Button
                                type="submit"
                                size="sm"
                                class="rounded-full px-4"
                                disabled={editForm.processing || !isDirty}
                            >
                                Save changes
                            </Button>
                        {/snippet}
                    </AdminPanel>
                </form>

                {#if !isSelf}
                    <AdminPanel
                        title="Access"
                        description={user.deactivated_at
                            ? 'This account is locked. Reactivating lets the user sign in again; tokens stay revoked.'
                            : 'Deactivating signs the user out everywhere and revokes their tokens without deleting anything.'}
                    >
                        <div class="flex flex-wrap gap-2">
                            {#if user.deactivated_at}
                                <Button
                                    size="sm"
                                    class="rounded-full px-4"
                                    disabled={busy}
                                    onclick={toggleActive}>Reactivate</Button
                                >
                            {:else}
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="rounded-full px-4 shadow-none"
                                    disabled={busy}
                                    onclick={() => (deactivating = true)}
                                    >Deactivate</Button
                                >
                            {/if}
                            <Button
                                size="sm"
                                variant="destructive"
                                class="rounded-full px-4"
                                disabled={busy}
                                onclick={() => {
                                    deleteError = '';
                                    deleting = true;
                                }}>Delete account</Button
                            >
                        </div>
                    </AdminPanel>
                {/if}

                <AdminPanel
                    title="Audit trail"
                    description="What admins did to this account."
                >
                    <AuditTrail entries={auditEntries} />
                </AdminPanel>
            </div>
        </div>
    </div>
</AdminPage>

<ConfirmDialog
    open={deactivating}
    title="Deactivate {user.name}?"
    description="They will be signed out everywhere, every API token they created stops working, and they cannot log in until you reactivate the account. Nothing is deleted."
    confirmLabel="Deactivate"
    destructive
    processing={busy}
    onConfirm={toggleActive}
    onOpenChange={(value) => (deactivating = value)}
/>

<ConfirmDialog
    open={deleting}
    title="Permanently delete {user.name}?"
    description="Their account, their content in shared households and every household where they are the only member are deleted. This cannot be undone."
    confirmLabel="Delete account"
    destructive
    processing={busy}
    challenge={user.email}
    challengeLabel="Type the user's e-mail address to confirm"
    error={deleteError}
    onConfirm={confirmDelete}
    onOpenChange={(value) => (deleting = value)}
/>
