<script module lang="ts">
    import { index } from '@/routes/api-tokens';

    export const layout = {
        breadcrumbs: [
            {
                title: 'API tokens',
                href: index(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, page, router } from '@inertiajs/svelte';
    import Check from 'lucide-svelte/icons/check';
    import Copy from 'lucide-svelte/icons/copy';
    import KeyRound from 'lucide-svelte/icons/key-round';
    import Plug from 'lucide-svelte/icons/plug';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import ApiTokenController from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
    import ConnectedAppController from '@/actions/App/Http/Controllers/Settings/ConnectedAppController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';

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
            copyNow: '{t.copyNow}',
            copy: 'Copy',
            copied: 'Copied',
            noHousehold: '{t.noHousehold}',
            tokenName: 'Token name',
            tokenNamePlaceholder: 'e.g. Home Assistant',
            household: 'Household',
            permissions: 'Permissions',
            readOnly: 'Read only',
            readWrite: 'Read and write',
            create: 'Create token',
            activeTitle: 'Active tokens',
            activeDescription: 'Tokens expire one year after they are created',
            noTokens: '{t.noTokens}',
            lastUsed: 'Last used',
            expires: 'Expires',
            never: 'never',
            revoke: 'Revoke',
            confirmRevoke: (name: string | null) =>
                `Revoke "${name}"? Anything using it will stop working.`,
            appsTitle: 'Connected apps',
            appsDescription:
                'Apps you signed in to with your account, such as AI assistants',
            noApps: 'No connected apps.',
            connected: 'Connected',
            disconnect: 'Disconnect',
            confirmDisconnect: (name: string | null) =>
                `Disconnect "${name}"? It will have to ask for access again.`,
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
            tokenName: 'Navn på nøkkel',
            tokenNamePlaceholder: 'f.eks. Home Assistant',
            household: 'Husstand',
            permissions: 'Tillatelser',
            readOnly: 'Kun lesing',
            readWrite: 'Lesing og skriving',
            create: 'Opprett nøkkel',
            activeTitle: 'Aktive nøkler',
            activeDescription: 'Nøkler utløper ett år etter at de er opprettet',
            noTokens: 'Ingen API-nøkler ennå.',
            lastUsed: 'Sist brukt',
            expires: 'Utløper',
            never: 'aldri',
            revoke: 'Trekk tilbake',
            confirmRevoke: (name: string | null) =>
                `Trekke tilbake «${name}»? Alt som bruker den slutter å virke.`,
            appsTitle: 'Tilkoblede apper',
            appsDescription:
                'Apper du har logget inn i med kontoen din, for eksempel AI-assistenter',
            noApps: 'Ingen tilkoblede apper.',
            connected: 'Koblet til',
            disconnect: 'Koble fra',
            confirmDisconnect: (name: string | null) =>
                `Koble fra «${name}»? Appen må be om tilgang på nytt.`,
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
    let revokingId = $state<number | null>(null);
    let disconnectingId = $state<number | null>(null);

    const selectClass =
        'mt-1 block h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

    const formatDate = (value: string | null): string =>
        value ? new Date(value).toLocaleDateString() : t.never;

    const copyToken = async () => {
        if (!plainTextToken) {
            return;
        }

        await navigator.clipboard.writeText(plainTextToken);
        copied = true;
        setTimeout(() => (copied = false), 2000);
    };

    const revoke = (token: ApiToken) => {
        if (!confirm(t.confirmRevoke(token.name))) {
            return;
        }

        revokingId = token.id;
        router.delete(ApiTokenController.destroy.url(token.id), {
            preserveScroll: true,
            onFinish: () => (revokingId = null),
        });
    };

    const disconnect = (app: ConnectedApp) => {
        if (!confirm(t.confirmDisconnect(app.name))) {
            return;
        }

        disconnectingId = app.id;
        router.delete(ConnectedAppController.destroy.url(app.id), {
            preserveScroll: true,
            onFinish: () => (disconnectingId = null),
        });
    };
</script>

<AppHead title={t.title} />

<h1 class="sr-only">{t.title}</h1>

<div class="flex flex-col space-y-6">
    <Heading variant="small" title={t.title} description={t.description} />

    {#if plainTextToken}
        <div
            class="space-y-3 rounded-lg border border-green-600/30 bg-green-600/5 p-4"
            data-test="new-api-token"
        >
            <p class="text-sm font-medium">
                {t.copyNow}
            </p>
            <div class="flex items-start gap-2">
                <code
                    class="max-h-24 flex-1 overflow-y-auto rounded-md bg-muted p-2 text-xs break-all"
                    >{plainTextToken}</code
                >
                <Button variant="outline" size="sm" onclick={copyToken}>
                    {#if copied}
                        <Check class="h-4 w-4" /> {t.copied}
                    {:else}
                        <Copy class="h-4 w-4" /> {t.copy}
                    {/if}
                </Button>
            </div>
        </div>
    {/if}

    {#if households.length === 0}
        <p class="text-sm text-muted-foreground">
            {t.noHousehold}
        </p>
    {:else}
        <Form
            {...ApiTokenController.store.form()}
            class="space-y-6"
            options={{ preserveScroll: true }}
            resetOnSuccess
        >
            {#snippet children({ errors, processing })}
                <div class="grid gap-2">
                    <Label for="name">{t.tokenName}</Label>
                    <Input
                        id="name"
                        name="name"
                        class="mt-1 block w-full"
                        required
                        maxlength={100}
                        placeholder={t.tokenNamePlaceholder}
                    />
                    <InputError class="mt-2" message={errors.name} />
                </div>

                <div class="grid gap-2">
                    <Label for="household_id">{t.household}</Label>
                    <select
                        id="household_id"
                        name="household_id"
                        class={selectClass}
                        required
                    >
                        {#each households as household (household.id)}
                            <option value={household.id}
                                >{household.name}</option
                            >
                        {/each}
                    </select>
                    <InputError class="mt-2" message={errors.household_id} />
                </div>

                <div class="grid gap-2">
                    <Label for="can_write">{t.permissions}</Label>
                    <select
                        id="can_write"
                        name="can_write"
                        class={selectClass}
                        required
                    >
                        <option value="0">{t.readOnly}</option>
                        <option value="1">{t.readWrite}</option>
                    </select>
                    <InputError class="mt-2" message={errors.can_write} />
                </div>

                <Button
                    type="submit"
                    disabled={processing}
                    data-test="create-api-token-button">{t.create}</Button
                >
            {/snippet}
        </Form>
    {/if}

    <div class="space-y-3">
        <Heading
            variant="small"
            title={t.activeTitle}
            description={t.activeDescription}
        />

        {#if tokens.length === 0}
            <p class="text-sm text-muted-foreground">{t.noTokens}</p>
        {:else}
            <div class="rounded-lg border">
                {#each tokens as token (token.id)}
                    <div
                        class="flex items-center justify-between border-b p-4 last:border-b-0"
                    >
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted"
                            >
                                <KeyRound
                                    class="h-5 w-5 text-muted-foreground"
                                />
                            </div>
                            <div class="space-y-1">
                                <div class="flex items-center gap-2.5">
                                    <p class="font-medium tracking-tight">
                                        {token.name}
                                    </p>
                                    <span
                                        class="inline-flex items-center rounded-md bg-muted px-2 py-0.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase ring-1 ring-border ring-inset"
                                    >
                                        {token.scopes.includes('write')
                                            ? t.readWrite
                                            : t.readOnly}
                                    </span>
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
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            disabled={revokingId === token.id}
                            onclick={() => revoke(token)}
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
            <div class="rounded-lg border">
                {#each connectedApps as app (app.id)}
                    <div
                        class="flex items-center justify-between border-b p-4 last:border-b-0"
                    >
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted"
                            >
                                <Plug class="h-5 w-5 text-muted-foreground" />
                            </div>
                            <div class="space-y-1">
                                <div class="flex items-center gap-2.5">
                                    <p class="font-medium tracking-tight">
                                        {app.name}
                                    </p>
                                    <span
                                        class="inline-flex items-center rounded-md bg-muted px-2 py-0.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase ring-1 ring-border ring-inset"
                                    >
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
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            disabled={disconnectingId === app.id}
                            onclick={() => disconnect(app)}
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
            class="overflow-x-auto rounded-md bg-muted p-3 text-xs">curl {apiBaseUrl}/shopping-lists \
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
            <code class="rounded bg-muted px-1 py-0.5 text-xs">{mcpUrl}</code>
            {t.withSameToken}
        </p>
    </div>
</div>
