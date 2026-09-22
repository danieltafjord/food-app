<script module lang="ts">
    import {
        index as usersRoute,
        show as showUser,
    } from '@/routes/admin/users';

    export const layout = {
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
    };
</script>

<script lang="ts">
    import { Link, page, router, useForm } from '@inertiajs/svelte';
    import ArrowDown from 'lucide-svelte/icons/arrow-down';
    import ArrowUp from 'lucide-svelte/icons/arrow-up';
    import Ellipsis from 'lucide-svelte/icons/ellipsis';
    import Search from 'lucide-svelte/icons/search';
    import X from 'lucide-svelte/icons/x';
    import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
    import AdminPage from '@/components/admin/AdminPage.svelte';
    import AdminPanel from '@/components/admin/AdminPanel.svelte';
    import SegmentedControl from '@/components/admin/SegmentedControl.svelte';
    import StatusDot from '@/components/admin/StatusDot.svelte';
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
        direction: 'asc' | 'desc';
    };

    type Paginated = {
        data: UserRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };

    let {
        users,
        filters,
    }: {
        users: Paginated;
        filters: Filters;
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
    let searchTimer: ReturnType<typeof setTimeout> | undefined;

    function applyFilters(
        next: Partial<{
            search: string;
            status: string;
            household: number | null;
            sort: Filters['sort'];
            direction: Filters['direction'];
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

        router.get(usersRoute({ query }).url, undefined, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['users', 'filters'],
        });
    }

    function onSearchInput() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => applyFilters({ search }), 300);
    }

    function sortBy(column: Filters['sort']) {
        const direction =
            filters.sort === column
                ? filters.direction === 'asc'
                    ? 'desc'
                    : 'asc'
                : column === 'name'
                  ? 'asc'
                  : 'desc';
        applyFilters({ sort: column, direction });
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

    const headerClass = 'px-5 py-2.5 text-left text-xs font-medium';
    const pillClass =
        'rounded-full border border-border/80 bg-muted/60 px-2 py-px text-[11px] font-medium text-muted-foreground';
</script>

<AppHead title="Users" />

{#snippet sortHeader(column: Filters['sort'], label: string)}
    {@const active = filters.sort === column}
    <button
        type="button"
        class={cn(
            'inline-flex items-center gap-1 hover:text-foreground',
            active && 'text-foreground',
        )}
        onclick={() => sortBy(column)}
        aria-sort={active
            ? filters.direction === 'asc'
                ? 'ascending'
                : 'descending'
            : undefined}
    >
        {label}
        {#if active}
            {#if filters.direction === 'asc'}
                <ArrowUp class="size-3" />
            {:else}
                <ArrowDown class="size-3" />
            {/if}
        {/if}
    </button>
{/snippet}

{#snippet pagination()}
    <p class="text-xs text-muted-foreground tabular-nums">
        Showing {users.from ?? 0}–{users.to ?? 0} of {users.total}
    </p>
    <div class="flex gap-2">
        {#if users.prev_page_url}
            <Button
                variant="outline"
                size="sm"
                class="rounded-full px-4 shadow-none"
                asChild
            >
                {#snippet children(props)}
                    <Link
                        href={users.prev_page_url ?? ''}
                        class={props.class}
                        preserveScroll>Previous</Link
                    >
                {/snippet}
            </Button>
        {/if}
        {#if users.next_page_url}
            <Button
                variant="outline"
                size="sm"
                class="rounded-full px-4 shadow-none"
                asChild
            >
                {#snippet children(props)}
                    <Link
                        href={users.next_page_url ?? ''}
                        class={props.class}
                        preserveScroll>Next</Link
                    >
                {/snippet}
            </Button>
        {/if}
    </div>
{/snippet}

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
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative w-full sm:max-w-xs">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    type="search"
                    placeholder="Search name or e-mail"
                    class="h-10 rounded-full bg-panel pl-10 pr-4 shadow-none"
                    bind:value={search}
                    oninput={onSearchInput}
                    aria-label="Search users"
                />
            </div>
            <SegmentedControl
                segments={statuses}
                value={filters.status}
                label="Filter by status"
                onSelect={(value) => applyFilters({ status: String(value) })}
            />
            {#if filters.household}
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border border-border/80 bg-panel px-3 py-1.5 text-xs font-medium"
                    onclick={() => applyFilters({ household: null })}
                    aria-label="Stop filtering by household {filters.household
                        .name}"
                >
                    Household: {filters.household.name}
                    <X class="size-3.5 text-muted-foreground" />
                </button>
            {/if}
        </div>

        <AdminPanel
            padded={false}
            footer={users.last_page > 1 ? pagination : undefined}
        >
            <div class="overflow-x-auto md:overflow-visible">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-muted-foreground">
                            <th class={headerClass}>
                                {@render sortHeader('name', 'User')}
                            </th>
                            <th class={headerClass}>Status</th>
                            <th class={cn(headerClass, 'text-right')}>
                                {@render sortHeader('households', 'Households')}
                            </th>
                            <th class={cn(headerClass, 'hidden lg:table-cell')}
                                >AI assistance</th
                            >
                            <th class={cn(headerClass, 'hidden lg:table-cell')}>
                                {@render sortHeader('joined', 'Joined')}
                            </th>
                            <th class={headerClass}>
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        {#if users.data.length === 0}
                            <tr>
                                <td
                                    colspan="6"
                                    class="px-5 py-12 text-center text-muted-foreground"
                                >
                                    No accounts match this search. Try a
                                    different name, e-mail or status.
                                </td>
                            </tr>
                        {/if}
                        {#each users.data as user (user.id)}
                            {@const isSelf = user.id === currentUserId}
                            {@const busy = busyId === user.id}
                            <tr
                                class={cn(
                                    'transition-colors hover:bg-muted/40',
                                    user.deactivated_at &&
                                        'text-muted-foreground',
                                    busy && 'opacity-50',
                                )}
                            >
                                <td class="px-5 py-3">
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
                                <td class="px-5 py-3">
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
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {user.households_count}
                                </td>
                                <td
                                    class="hidden px-5 py-3 text-muted-foreground lg:table-cell"
                                >
                                    {aiSummary(user)}
                                </td>
                                <td
                                    class="hidden px-5 py-3 whitespace-nowrap text-muted-foreground lg:table-cell"
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
