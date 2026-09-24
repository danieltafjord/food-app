<script module lang="ts">
    import AppLayout from '@/layouts/AppLayout.svelte';
    import SettingsLayout from '@/layouts/settings/Layout.svelte';
    import { index } from '@/routes/api-tokens';

    export const layout = [
        [
            AppLayout,
            {
                breadcrumbs: [
                    {
                        title: 'API tokens',
                        href: index(),
                    },
                ],
            },
        ],
        SettingsLayout,
    ];
</script>

<script lang="ts">
    import { page, router, useForm } from '@inertiajs/svelte';
    import Check from 'lucide-svelte/icons/check';
    import Copy from 'lucide-svelte/icons/copy';
    import KeyRound from 'lucide-svelte/icons/key-round';
    import Plug from 'lucide-svelte/icons/plug';
    import Plus from 'lucide-svelte/icons/plus';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import ApiTokenController from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
    import ConnectedAppController from '@/actions/App/Http/Controllers/Settings/ConnectedAppController';
    import AppHead from '@/components/AppHead.svelte';
    import ConfirmDialog from '@/components/ConfirmDialog.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import {
        Dialog,
        DialogContent,
        DialogDescription,
        DialogFooter,
        DialogTitle,
    } from '@/components/ui/dialog';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { cn } from '@/lib/utils';

    type ApiToken = {
        id: number;
        name: string | null;
        household_name: string | null;
        scopes: string[];
        last_used_at: string | null;
        created_at: string | null;
        expires_at: string | null;
    };

    type ConnectedApp = {
        id: number;
        name: string | null;
        household_name: string | null;
        can_write: boolean;
        connected_at: string | null;
    };

    let {
        tokens,
        connectedApps,
        households,
        apiBaseUrl,
        apiDocsUrl,
        mcpUrl,
    }: {
        tokens: ApiToken[];
        connectedApps: ConnectedApp[];
        households: { id: number; name: string }[];
        apiBaseUrl: string;
        apiDocsUrl: string;
        mcpUrl: string;
    } = $props();

    const plainTextToken = $derived(
        (page.flash as { plainTextApiToken?: string } | undefined)
            ?.plainTextApiToken,
    );

    const copy = {
        en: {
            title: 'API tokens',
            description:
                'Connect AI agents, Home Assistant and scripts to your household',
            copyNow: 'Copy the new token now. It will not be shown again.',
            copy: 'Copy',
            copied: 'Copied',
            noHousehold:
                'You need to be a member of a household before you can create a token.',
            newToken: 'New token',
            createTitle: 'Create a token',
            createDescription:
                'The token is pinned to one household and expires after a year.',
            tokenName: 'Token name',
            tokenNamePlaceholder: 'e.g. Home Assistant',
            household: 'Household',
            permissions: 'Permissions',
            readOnly: 'Read only',
            readWrite: 'Read and write',
            create: 'Create token',
            cancel: 'Cancel',
            activeTitle: 'Active tokens',
            activeDescription: 'Tokens expire one year after they are created',
            noTokens: 'No API tokens yet.',
            lastUsed: 'Last used',
            expires: 'Expires',
            never: 'never',
            expiresSoon: 'Expires soon',
            expired: 'Expired',
            revoke: 'Revoke',
            revokeTitle: (name: string | null) => `Revoke “${name}”?`,
            revokeDescription:
                'Anything using this token will stop working immediately. This cannot be undone.',
            appsTitle: 'Connected apps',
            appsDescription:
                'Apps you signed in to with your account, such as AI assistants',
            noApps: 'No connected apps.',
            connected: 'Connected',
            disconnect: 'Disconnect',
            disconnectTitle: (name: string | null) => `Disconnect “${name}”?`,
            disconnectDescription:
                'The app loses access right away and will have to ask for it again.',
            usingTitle: 'Using a token',
            usingDescription: 'Send it as a bearer token with every request',
            seeThe: 'See the',
            apiReference: 'API reference',
            forEveryEndpoint:
                'for every endpoint. AI agents that support MCP can connect to',
            withSameToken: 'with the same token.',
        },
        no: {
            title: 'API-nøkler',
            description:
                'Koble AI-agenter, Home Assistant og skript til husstanden din',
            copyNow: 'Kopier den nye nøkkelen nå. Den vises ikke igjen.',
            copy: 'Kopier',
            copied: 'Kopiert',
            noHousehold:
                'Du må være medlem av en husstand før du kan opprette en nøkkel.',
            newToken: 'Ny nøkkel',
            createTitle: 'Opprett en nøkkel',
            createDescription:
                'Nøkkelen er knyttet til én husstand og utløper etter ett år.',
            tokenName: 'Navn på nøkkel',
            tokenNamePlaceholder: 'f.eks. Home Assistant',
            household: 'Husstand',
            permissions: 'Tillatelser',
            readOnly: 'Kun lesing',
            readWrite: 'Lesing og skriving',
            create: 'Opprett nøkkel',
            cancel: 'Avbryt',
            activeTitle: 'Aktive nøkler',
            activeDescription: 'Nøkler utløper ett år etter at de er opprettet',
            noTokens: 'Ingen API-nøkler ennå.',
            lastUsed: 'Sist brukt',
            expires: 'Utløper',
            never: 'aldri',
            expiresSoon: 'Utløper snart',
            expired: 'Utløpt',
            revoke: 'Trekk tilbake',
            revokeTitle: (name: string | null) => `Trekke tilbake «${name}»?`,
            revokeDescription:
                'Alt som bruker denne nøkkelen slutter å virke med en gang. Dette kan ikke angres.',
            appsTitle: 'Tilkoblede apper',
            appsDescription:
                'Apper du har logget inn i med kontoen din, for eksempel AI-assistenter',
            noApps: 'Ingen tilkoblede apper.',
            connected: 'Koblet til',
            disconnect: 'Koble fra',
            disconnectTitle: (name: string | null) => `Koble fra «${name}»?`,
            disconnectDescription:
                'Appen mister tilgangen med en gang og må be om den på nytt.',
            usingTitle: 'Bruke en nøkkel',
            usingDescription: 'Send den som bearer-token med hver forespørsel',
            seeThe: 'Se',
            apiReference: 'API-referansen',
            forEveryEndpoint:
                'for alle endepunkter. AI-agenter som støtter MCP kan koble til',
            withSameToken: 'med samme nøkkel.',
        },
    };

    const language = $derived(
        page.props.auth.user?.locale === 'nb' || page.props.locale === 'no'
            ? 'no'
            : 'en',
    );
    const t = $derived(copy[language]);

    let copied = $state(false);
    let creating = $state(false);
    let revoking = $state<ApiToken | null>(null);
    let disconnecting = $state<ConnectedApp | null>(null);
    let busy = $state(false);

    const createForm = useForm({
        name: '',
        household_id: households[0]?.id ?? 0,
        can_write: '0',
    });

    const selectClass =
        'mt-1 block h-10 w-full rounded-full border border-input bg-transparent px-4 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';
    const pillClass =
        'inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase ring-1 ring-border ring-inset';

    const formatDate = (value: string | null): string =>
        value ? new Date(value).toLocaleDateString() : t.never;

    const SOON_DAYS = 30;

    function expiry(token: ApiToken): 'ok' | 'soon' | 'expired' {
        if (!token.expires_at) {
            return 'ok';
        }

        const daysLeft =
            (new Date(token.expires_at).getTime() - Date.now()) / 86_400_000;

        if (daysLeft <= 0) {
            return 'expired';
        }

        return daysLeft <= SOON_DAYS ? 'soon' : 'ok';
    }

    const copyToken = async () => {
        if (!plainTextToken) {
            return;
        }

        await navigator.clipboard.writeText(plainTextToken);
        copied = true;
        setTimeout(() => (copied = false), 2000);
    };

    function openCreate() {
        createForm.reset();
        createForm.clearErrors();
        createForm.household_id = households[0]?.id ?? 0;
        creating = true;
    }

    function submitCreate(event: SubmitEvent) {
        event.preventDefault();
        createForm.post(ApiTokenController.store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                creating = false;
                createForm.reset();
            },
        });
    }

    function confirmRevoke() {
        if (!revoking) {
            return;
        }

        busy = true;
        router.delete(ApiTokenController.destroy.url(revoking.id), {
            preserveScroll: true,
            onFinish: () => {
                busy = false;
                revoking = null;
            },
        });
    }

    function confirmDisconnect() {
        if (!disconnecting) {
            return;
        }

        busy = true;
        router.delete(ConnectedAppController.destroy.url(disconnecting.id), {
            preserveScroll: true,
            onFinish: () => {
                busy = false;
                disconnecting = null;
            },
        });
    }
</script>

<AppHead title={t.title} />

<h1 class="sr-only">{t.title}</h1>

<div class="flex flex-col space-y-6">
    <Heading variant="small" title={t.title} description={t.description} />

    {#if plainTextToken}
        <div
            class="space-y-3 rounded-2xl border border-green-600/30 bg-green-600/5 p-5"
            data-test="new-api-token"
        >
            <p class="text-sm font-medium">
                {t.copyNow}
            </p>
            <div class="flex items-start gap-2">
                <code
                    class="max-h-24 flex-1 overflow-y-auto rounded-xl bg-muted px-3 py-2 text-xs break-all"
                    >{plainTextToken}</code
                >
                <Button
                    variant="outline"
                    size="sm"
                    class="rounded-full px-4 shadow-none"
                    onclick={copyToken}
                >
                    {#if copied}
                        <Check class="h-4 w-4" /> {t.copied}
                    {:else}
                        <Copy class="h-4 w-4" /> {t.copy}
                    {/if}
                </Button>
            </div>
        </div>
    {/if}

    <div class="space-y-3">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                title={t.activeTitle}
                description={t.activeDescription}
            />
            {#if households.length > 0}
                <Button
                    size="sm"
                    class="shrink-0 rounded-full px-4"
                    onclick={openCreate}
                    data-test="new-api-token-button"
                >
                    <Plus class="h-4 w-4" />
                    {t.newToken}
                </Button>
            {/if}
        </div>

        {#if households.length === 0}
            <p class="text-sm text-muted-foreground">{t.noHousehold}</p>
        {:else if tokens.length === 0}
            <p class="text-sm text-muted-foreground">{t.noTokens}</p>
        {:else}
            <div class="overflow-hidden rounded-2xl border border-border/80">
                {#each tokens as token (token.id)}
                    {@const state = expiry(token)}
                    <div
                        class={cn(
                            'flex items-center justify-between gap-3 border-b p-4 last:border-b-0',
                            state === 'expired' && 'opacity-60',
                        )}
                    >
                        <div class="flex min-w-0 items-center gap-4">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-muted"
                            >
                                <KeyRound
                                    class="h-5 w-5 text-muted-foreground"
                                />
                            </div>
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p
                                        class="truncate font-medium tracking-tight"
                                    >
                                        {token.name}
                                    </p>
                                    <span class={pillClass}>
                                        {token.scopes.includes('write')
                                            ? t.readWrite
                                            : t.readOnly}
                                    </span>
                                    {#if state === 'soon'}
                                        <span
                                            class={cn(
                                                pillClass,
                                                'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900/60',
                                            )}>{t.expiresSoon}</span
                                        >
                                    {:else if state === 'expired'}
                                        <span
                                            class={cn(
                                                pillClass,
                                                'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900/60',
                                            )}>{t.expired}</span
                                        >
                                    {/if}
                                </div>
                                <p class="text-sm text-muted-foreground">
                                    {token.household_name}
                                    <span class="mx-1 text-muted-foreground/50"
                                        >/</span
                                    >
                                    {t.lastUsed}
                                    {formatDate(token.last_used_at)}
                                    <span class="mx-1 text-muted-foreground/50"
                                        >/</span
                                    >
                                    {t.expires}
                                    {formatDate(token.expires_at)}
                                </p>
                            </div>
                        </div>

                        <Button
                            variant="ghost"
                            size="sm"
                            class="shrink-0 rounded-full text-destructive hover:bg-destructive/10 hover:text-destructive"
                            disabled={busy}
                            onclick={() => (revoking = token)}
                            aria-label="{t.revoke} {token.name}"
                        >
                            <Trash2 class="h-4 w-4" />
                            <span class="sr-only">{t.revoke}</span>
                        </Button>
                    </div>
                {/each}
            </div>
        {/if}
    </div>

    <div class="space-y-3">
        <Heading
            variant="small"
            title={t.appsTitle}
            description={t.appsDescription}
        />

        {#if connectedApps.length === 0}
            <p class="text-sm text-muted-foreground">{t.noApps}</p>
        {:else}
            <div class="overflow-hidden rounded-2xl border border-border/80">
                {#each connectedApps as app (app.id)}
                    <div
                        class="flex items-center justify-between gap-3 border-b p-4 last:border-b-0"
                    >
                        <div class="flex min-w-0 items-center gap-4">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-muted"
                            >
                                <Plug class="h-5 w-5 text-muted-foreground" />
                            </div>
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p
                                        class="truncate font-medium tracking-tight"
                                    >
                                        {app.name}
                                    </p>
                                    <span class={pillClass}>
                                        {app.can_write
                                            ? t.readWrite
                                            : t.readOnly}
                                    </span>
                                </div>
                                <p class="text-sm text-muted-foreground">
                                    {app.household_name}
                                    <span class="mx-1 text-muted-foreground/50"
                                        >/</span
                                    >
                                    {t.connected}
                                    {formatDate(app.connected_at)}
                                </p>
                            </div>
                        </div>

                        <Button
                            variant="ghost"
                            size="sm"
                            class="shrink-0 rounded-full text-destructive hover:bg-destructive/10 hover:text-destructive"
                            disabled={busy}
                            onclick={() => (disconnecting = app)}
                            aria-label="{t.disconnect} {app.name}"
                        >
                            <Trash2 class="h-4 w-4" />
                            <span class="sr-only">{t.disconnect}</span>
                        </Button>
                    </div>
                {/each}
            </div>
        {/if}
    </div>

    <div class="space-y-3">
        <Heading
            variant="small"
            title={t.usingTitle}
            description={t.usingDescription}
        />
        <pre
            class="overflow-x-auto rounded-2xl bg-muted p-4 text-xs">curl {apiBaseUrl}/shopping-lists \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"</pre>
        <p class="text-sm text-muted-foreground">
            {t.seeThe}
            <a
                href={apiDocsUrl}
                target="_blank"
                rel="noopener"
                class="underline underline-offset-4">{t.apiReference}</a
            >
            {t.forEveryEndpoint}
            <code class="rounded-md bg-muted px-1.5 py-0.5 text-xs"
                >{mcpUrl}</code
            >
            {t.withSameToken}
        </p>
    </div>
</div>

<Dialog
    open={creating}
    onOpenChange={(value) => {
        if (!value) {
            creating = false;
        }
    }}
>
    <DialogContent class="rounded-3xl sm:max-w-md">
        <form onsubmit={submitCreate} class="space-y-5">
            <div class="space-y-1">
                <DialogTitle>{t.createTitle}</DialogTitle>
                <DialogDescription>{t.createDescription}</DialogDescription>
            </div>

            <div class="grid gap-2">
                <Label for="name">{t.tokenName}</Label>
                <Input
                    id="name"
                    name="name"
                    class="block h-10 w-full rounded-full px-4 shadow-none"
                    required
                    maxlength={100}
                    placeholder={t.tokenNamePlaceholder}
                    bind:value={createForm.name}
                />
                <InputError message={createForm.errors.name} />
            </div>

            <div class="grid gap-2">
                <Label for="household_id">{t.household}</Label>
                <select
                    id="household_id"
                    name="household_id"
                    class={selectClass}
                    required
                    bind:value={createForm.household_id}
                >
                    {#each households as household (household.id)}
                        <option value={household.id}>{household.name}</option>
                    {/each}
                </select>
                <InputError message={createForm.errors.household_id} />
            </div>

            <div class="grid gap-2">
                <Label for="can_write">{t.permissions}</Label>
                <select
                    id="can_write"
                    name="can_write"
                    class={selectClass}
                    required
                    bind:value={createForm.can_write}
                >
                    <option value="0">{t.readOnly}</option>
                    <option value="1">{t.readWrite}</option>
                </select>
                <InputError message={createForm.errors.can_write} />
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    class="rounded-full shadow-none"
                    onclick={() => (creating = false)}
                >
                    {t.cancel}
                </Button>
                <Button
                    type="submit"
                    class="rounded-full"
                    disabled={createForm.processing}
                    data-test="create-api-token-button">{t.create}</Button
                >
            </DialogFooter>
        </form>
    </DialogContent>
</Dialog>

<ConfirmDialog
    open={revoking !== null}
    title={t.revokeTitle(revoking?.name ?? null)}
    description={t.revokeDescription}
    confirmLabel={t.revoke}
    cancelLabel={t.cancel}
    destructive
    processing={busy}
    onConfirm={confirmRevoke}
    onOpenChange={(value) => {
        if (!value && !busy) {
            revoking = null;
        }
    }}
/>

<ConfirmDialog
    open={disconnecting !== null}
    title={t.disconnectTitle(disconnecting?.name ?? null)}
    description={t.disconnectDescription}
    confirmLabel={t.disconnect}
    cancelLabel={t.cancel}
    destructive
    processing={busy}
    onConfirm={confirmDisconnect}
    onOpenChange={(value) => {
        if (!value && !busy) {
            disconnecting = null;
        }
    }}
/>
