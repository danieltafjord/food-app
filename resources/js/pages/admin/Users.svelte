<script module lang="ts">
    import AppLayout from '@/layouts/AppLayout.svelte';
    import {
        index as usersRoute,
        show as showUser,
    } from '@/routes/admin/users';

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
    import Ellipsis from 'lucide-svelte/icons/ellipsis';
    import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
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
    import ConfirmDialog from '@/components/ConfirmDialog.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Checkbox } from '@/components/ui/checkbox';
    import {
        Dialog,
        DialogContent,
        DialogDescription,
        DialogFooter,
        DialogTitle,
    } from '@/components/ui/dialog';
    import {
        DropdownMenu,
        DropdownMenuContent,
        DropdownMenuItem,
        DropdownMenuSeparator,
        DropdownMenuTrigger,
    } from '@/components/ui/dropdown-menu';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { getInitials } from '@/lib/initials';
    import { cn } from '@/lib/utils';

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

    type Filters = {
        search: string;
        status: string;
        household: { id: number; name: string } | null;
        sort: 'joined' | 'name' | 'households';
        direction: SortDirection;
        per_page: number;
    };

    let {
        users,
        filters,
        pageSizes,
    }: {
        users: Paginator & { data: UserRow[] };
        filters: Filters;
        pageSizes: number[];
    } = $props();

    const statuses = [
        { value: 'all', label: 'All' },
        { value: 'active', label: 'Active' },
        { value: 'deactivated', label: 'Deactivated' },
        { value: 'admins', label: 'Admins' },
        { value: 'unverified', label: 'Unverified' },
        { value: 'ai', label: 'AI on' },
    ];

    const currentUserId = $derived(page.props.auth.user?.id);

    // svelte-ignore state_referenced_locally
    let search = $state(filters.search);

    const isFiltered = $derived(
        filters.search !== '' ||
            filters.status !== 'all' ||
            filters.household !== null,
    );

    function applyFilters(
        next: Partial<{
            search: string;
            status: string;
            household: number | null;
            sort: Filters['sort'];
            direction: SortDirection;
            per_page: number;
        }>,
    ) {
        const query: Record<string, string> = {};
        const nextSearch = next.search ?? search;
        const nextStatus = next.status ?? filters.status;
        const nextHousehold =
            next.household === undefined
                ? (filters.household?.id ?? null)
                : next.household;
        const nextSort = next.sort ?? filters.sort;
        const nextDirection = next.direction ?? filters.direction;
        const nextPerPage = next.per_page ?? filters.per_page;

        if (nextSearch.trim() !== '') {
            query.search = nextSearch.trim();
        }

        if (nextStatus !== 'all') {
            query.status = nextStatus;
        }

        if (nextHousehold) {
            query.household = String(nextHousehold);
        }

        if (nextSort !== 'joined' || nextDirection !== 'desc') {
            query.sort = nextSort;
            query.direction = nextDirection;
        }

        if (nextPerPage !== pageSizes[0]) {
            query.per_page = String(nextPerPage);
        }

        router.get(usersRoute({ query }).url, undefined, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['users', 'filters'],
        });
    }

    function clearFilters() {
        search = '';
        applyFilters({ search: '', status: 'all', household: null });
    }

    function sortBy(column: string) {
        const sort = column as Filters['sort'];
        const direction: SortDirection =
            filters.sort === sort
                ? filters.direction === 'asc'
                    ? 'desc'
                    : 'asc'
                : sort === 'name'
                  ? 'asc'
                  : 'desc';
        applyFilters({ sort, direction });
    }

    const formatDate = (value: string | null) =>
        value
            ? new Date(value).toLocaleDateString(undefined, {
                  year: 'numeric',
                  month: 'short',
                  day: 'numeric',
              })
            : '–';

    function aiSummary(user: UserRow): string {
        const enabled = [
            user.ai_categorization_enabled && 'Categorization',
            user.ai_suggestions_enabled && 'Suggestions',
        ].filter(Boolean);

        return enabled.length === 0 ? 'Off' : enabled.join(', ');
    }

    let editing = $state<UserRow | null>(null);
    const editForm = useForm({
        name: '',
        email: '',
        is_admin: false,
        email_verified: false,
    });

    function openEdit(user: UserRow) {
        editForm.clearErrors();
        editForm.name = user.name;
        editForm.email = user.email;
        editForm.is_admin = user.is_admin;
        editForm.email_verified = user.email_verified_at !== null;
        editing = user;
    }

    function submitEdit(event: SubmitEvent) {
        event.preventDefault();

        if (!editing) {
            return;
        }

        editForm.patch(UserController.update.url(editing.id), {
            preserveScroll: true,
            onSuccess: () => (editing = null),
        });
    }

    let busyId = $state<number | null>(null);

    function run(user: UserRow, url: string, method: 'post' | 'delete') {
        busyId = user.id;
        router[method](url, undefined, {
            preserveScroll: true,
            onFinish: () => (busyId = null),
        });
    }

    let deactivating = $state<UserRow | null>(null);
    let deleting = $state<UserRow | null>(null);
    let deleteError = $state('');

    function confirmDeactivate() {
        if (!deactivating) {
            return;
        }

        const user = deactivating;
        deactivating = null;
        run(user, UserController.deactivate.url(user.id), 'post');
    }

    function reactivate(user: UserRow) {
        run(user, UserController.reactivate.url(user.id), 'post');
    }

    function confirmDelete(typed: string) {
        if (!deleting) {
            return;
        }

        const user = deleting;
        deleteError = '';
        busyId = user.id;
        router.delete(UserController.destroy.url(user.id), {
            data: { confirmation: typed },
            preserveScroll: true,
            onError: (errors) => {
                deleteError =
                    errors.confirmation ?? 'The account could not be deleted.';
            },
            onSuccess: () => (deleting = null),
            onFinish: () => (busyId = null),
        });
    }

    const pillClass =
        'rounded-full border border-border/80 bg-muted/60 px-2 py-px text-[11px] font-medium text-muted-foreground';
</script>

<AppHead title="Users" />

<AdminPage
    title="Users"
    description="Every account in the app. Deactivating signs a user out everywhere without deleting their data."
>
    {#snippet actions()}
        <p class="text-sm text-muted-foreground tabular-nums">
            {users.total.toLocaleString()}
            {users.total === 1 ? 'account' : 'accounts'}
        </p>
    {/snippet}

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <SearchField
                bind:value={search}
                placeholder="Name, e-mail or #id"
                label="Search users"
                onSearch={(value) => applyFilters({ search: value })}
            />
            <SegmentedControl
                segments={statuses}
                value={filters.status}
                label="Filter by status"
                onSelect={(value) => applyFilters({ status: String(value) })}
            />
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
            footer={users.total > 0 ? pagination : undefined}
        >
            <div class="overflow-x-auto md:overflow-visible">
                <table class="w-full text-sm">
                    <thead class={theadClass}>
                        <tr>
                            <SortHeader
                                column="name"
                                label="User"
                                sort={filters.sort}
                                direction={filters.direction}
                                onSort={sortBy}
                            />
                            <th class={thClass}>Status</th>
                            <SortHeader
                                column="households"
                                label="Households"
                                align="right"
                                sort={filters.sort}
                                direction={filters.direction}
                                onSort={sortBy}
                            />
                            <th class={cn(thClass, 'hidden lg:table-cell')}
                                >AI assistance</th
                            >
                            <SortHeader
                                column="joined"
                                label="Joined"
                                class="hidden lg:table-cell"
                                sort={filters.sort}
                                direction={filters.direction}
                                onSort={sortBy}
                            />
                            <th class={thClass}>
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        {#if users.data.length === 0}
                            <TableEmpty
                                colspan={6}
                                message={isFiltered
                                    ? 'No accounts match this search. Try a different name, e-mail or status.'
                                    : 'No accounts yet.'}
                                filtered={isFiltered}
                                onClear={clearFilters}
                            />
                        {/if}
                        {#each users.data as user (user.id)}
                            {@const isSelf = user.id === currentUserId}
                            {@const busy = busyId === user.id}
                            <tr
                                class={cn(
                                    rowClass,
                                    user.deactivated_at &&
                                        'text-muted-foreground',
                                    busy && 'opacity-50',
                                )}
                            >
                                <td class={tdClass}>
                                    <div class="flex items-center gap-3">
                                        <span
                                            class={cn(
                                                'flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium',
                                                user.deactivated_at
                                                    ? 'text-muted-foreground'
                                                    : 'text-foreground',
                                            )}
                                            aria-hidden="true"
                                        >
                                            {getInitials(user.name)}
                                        </span>
                                        <div class="min-w-0">
                                            <p
                                                class="flex items-center gap-1.5 font-medium"
                                            >
                                                <Link
                                                    href={showUser(user.id).url}
                                                    class="truncate hover:underline"
                                                    >{user.name}</Link
                                                >
                                                {#if isSelf}
                                                    <span class={pillClass}
                                                        >You</span
                                                    >
                                                {/if}
                                            </p>
                                            <p
                                                class="truncate text-xs text-muted-foreground"
                                            >
                                                {user.email}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class={tdClass}>
                                    <div
                                        class="flex flex-wrap items-center gap-x-3 gap-y-1"
                                    >
                                        {#if user.deactivated_at}
                                            <StatusDot tone="danger"
                                                >Deactivated</StatusDot
                                            >
                                        {:else if !user.email_verified_at}
                                            <StatusDot tone="warning"
                                                >Unverified</StatusDot
                                            >
                                        {:else}
                                            <StatusDot tone="ok"
                                                >Active</StatusDot
                                            >
                                        {/if}
                                        {#if user.is_admin}
                                            <span class={pillClass}>Admin</span>
                                        {/if}
                                        {#if user.two_factor}
                                            <span class={pillClass}>2FA</span>
                                        {/if}
                                    </div>
                                </td>
                                <td class={cn(tdClass, numericClass)}>
                                    {user.households_count}
                                </td>
                                <td
                                    class={cn(
                                        tdClass,
                                        'hidden text-muted-foreground lg:table-cell',
                                    )}
                                >
                                    {aiSummary(user)}
                                </td>
                                <td
                                    class={cn(
                                        tdClass,
                                        'hidden whitespace-nowrap text-muted-foreground lg:table-cell',
                                    )}
                                >
                                    {formatDate(user.created_at)}
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex justify-end">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                {#snippet children(props)}
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        class="size-8 rounded-full text-muted-foreground data-[state=open]:bg-accent data-[state=open]:text-foreground"
                                                        disabled={busy}
                                                        onclick={props.onclick}
                                                        aria-expanded={props[
                                                            'aria-expanded'
                                                        ]}
                                                        data-state={props[
                                                            'data-state'
                                                        ]}
                                                        aria-label="Actions for {user.name}"
                                                    >
                                                        <Ellipsis
                                                            class="size-4"
                                                        />
                                                    </Button>
                                                {/snippet}
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                sideOffset={4}
                                                class="min-w-40 rounded-2xl p-1.5"
                                            >
                                                <DropdownMenuItem
                                                    class="rounded-lg px-2.5"
                                                    asChild
                                                >
                                                    {#snippet children(props)}
                                                        <Link
                                                            href={showUser(
                                                                user.id,
                                                            ).url}
                                                            class={props.class}
                                                        >
                                                            View details
                                                        </Link>
                                                    {/snippet}
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    class="rounded-lg px-2.5"
                                                    asChild
                                                >
                                                    {#snippet children(props)}
                                                        <button
                                                            type="button"
                                                            class={props.class}
                                                            onclick={() => {
                                                                props.onClick?.();
                                                                openEdit(user);
                                                            }}
                                                        >
                                                            Edit details
                                                        </button>
                                                    {/snippet}
                                                </DropdownMenuItem>
                                                {#if !isSelf}
                                                    <DropdownMenuItem
                                                        class="rounded-lg px-2.5"
                                                        asChild
                                                    >
                                                        {#snippet children(
                                                            props,
                                                        )}
                                                            <button
                                                                type="button"
                                                                class={props.class}
                                                                onclick={() => {
                                                                    props.onClick?.();

                                                                    if (
                                                                        user.deactivated_at
                                                                    ) {
                                                                        reactivate(
                                                                            user,
                                                                        );
                                                                    } else {
                                                                        deactivating =
                                                                            user;
                                                                    }
                                                                }}
                                                            >
                                                                {user.deactivated_at
                                                                    ? 'Reactivate'
                                                                    : 'Deactivate'}
                                                            </button>
                                                        {/snippet}
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        class="rounded-lg px-2.5"
                                                        asChild
                                                    >
                                                        {#snippet children(
                                                            props,
                                                        )}
                                                            <button
                                                                type="button"
                                                                class={cn(
                                                                    props.class,
                                                                    'text-red-600 hover:text-red-600 dark:text-red-400 dark:hover:text-red-400',
                                                                )}
                                                                onclick={() => {
                                                                    props.onClick?.();
                                                                    deleteError =
                                                                        '';
                                                                    deleting =
                                                                        user;
                                                                }}
                                                            >
                                                                Delete account
                                                            </button>
                                                        {/snippet}
                                                    </DropdownMenuItem>
                                                {/if}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        </AdminPanel>
    </div>
</AdminPage>

<Dialog
    open={editing !== null}
    onOpenChange={(value) => {
        if (!value) {
            editing = null;
        }
    }}
>
    <DialogContent class="rounded-3xl sm:max-w-md">
        {#if editing}
            <form onsubmit={submitEdit} class="space-y-5">
                <div class="space-y-1">
                    <DialogTitle>Edit {editing.name}</DialogTitle>
                    <DialogDescription>
                        Changing the e-mail without keeping it verified will ask
                        the user to verify again.
                    </DialogDescription>
                </div>

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
                        <Checkbox bind:checked={editForm.email_verified} />
                        E-mail verified
                    </label>
                    <InputError message={editForm.errors.email_verified} />
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox
                            bind:checked={editForm.is_admin}
                            disabled={editing.id === currentUserId}
                        />
                        Admin access
                    </label>
                    <InputError message={editForm.errors.is_admin} />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        class="rounded-full shadow-none"
                        onclick={() => (editing = null)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        class="rounded-full"
                        disabled={editForm.processing}
                    >
                        Save changes
                    </Button>
                </DialogFooter>
            </form>
        {/if}
    </DialogContent>
</Dialog>

<ConfirmDialog
    open={deactivating !== null}
    title="Deactivate {deactivating?.name ?? ''}?"
    description="They will be signed out everywhere, every API token they created stops working, and they cannot log in until you reactivate the account. Nothing is deleted."
    confirmLabel="Deactivate"
    destructive
    onConfirm={confirmDeactivate}
    onOpenChange={(value) => {
        if (!value) {
            deactivating = null;
        }
    }}
/>

<ConfirmDialog
    open={deleting !== null}
    title="Permanently delete {deleting?.name ?? ''}?"
    description="Their account, their content in shared households and every household where they are the only member are deleted. This cannot be undone."
    confirmLabel="Delete account"
    destructive
    processing={busyId !== null && busyId === deleting?.id}
    challenge={deleting?.email ?? ''}
    challengeLabel="Type the user's e-mail address to confirm"
    error={deleteError}
    onConfirm={confirmDelete}
    onOpenChange={(value) => {
        if (!value) {
            deleting = null;
        }
    }}
/>

{#snippet pagination()}
    <PaginationFooter
        paginator={users}
        {pageSizes}
        onPageSize={(perPage) => applyFilters({ per_page: perPage })}
        only={['users', 'filters']}
    />
{/snippet}
