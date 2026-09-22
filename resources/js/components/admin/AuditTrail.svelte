<script lang="ts">
    /**
     * Renders admin audit entries as a compact timeline. Used on the user
     * detail page and beneath the AI settings.
     */
    import { Link } from '@inertiajs/svelte';
    import { show as showUser } from '@/routes/admin/users';

    export type AuditEntry = {
        id: number;
        action: string;
        admin_name: string;
        subject_label: string;
        subject_user_id: number | null;
        changes: Record<string, unknown> | null;
        created_at: string | null;
    };

    let {
        entries,
        showSubject = false,
        emptyText = 'Nothing has been changed yet.',
    }: {
        entries: AuditEntry[];
        /** Show who the entry is about (off when the page already is about them). */
        showSubject?: boolean;
        emptyText?: string;
    } = $props();

    const verbs: Record<string, string> = {
        'user.updated': 'edited',
        'user.deactivated': 'deactivated',
        'user.reactivated': 'reactivated',
        'user.deleted': 'deleted',
        'ai.model_updated': 'changed the model for',
        'ai.limits_updated': 'changed the limits for',
    };

    const formatDateTime = (value: string | null) =>
        value
            ? new Date(value).toLocaleString(undefined, {
                  year: 'numeric',
                  month: 'short',
                  day: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
              })
            : '–';

    function describe(value: unknown): string {
        if (value === null || value === undefined || value === '') {
            return 'none';
        }

        if (typeof value === 'boolean') {
            return value ? 'yes' : 'no';
        }

        return String(value);
    }

    function isChange(value: unknown): value is { from: unknown; to: unknown } {
        return (
            typeof value === 'object' &&
            value !== null &&
            'from' in value &&
            'to' in value
        );
    }
</script>

{#if entries.length === 0}
    <p class="text-sm text-muted-foreground">{emptyText}</p>
{:else}
    <ol class="flex flex-col gap-4">
        {#each entries as entry (entry.id)}
            <li class="flex gap-3 text-sm">
                <span
                    class="mt-2 size-1.5 shrink-0 rounded-full bg-muted-foreground/50"
                    aria-hidden="true"
                ></span>
                <div class="min-w-0 flex-1">
                    <p>
                        <span class="font-medium">{entry.admin_name}</span>
                        {verbs[entry.action] ?? entry.action}
                        {#if showSubject}
                            {#if entry.subject_user_id}
                                <Link
                                    href={showUser(entry.subject_user_id).url}
                                    class="font-medium hover:underline"
                                    >{entry.subject_label}</Link
                                >
                            {:else}
                                <span class="font-medium"
                                    >{entry.subject_label}</span
                                >
                            {/if}
                        {:else if entry.action.startsWith('ai.')}
                            <span class="font-medium"
                                >{entry.subject_label}</span
                            >
                        {:else}
                            this account
                        {/if}
                    </p>
                    {#if entry.changes && Object.keys(entry.changes).length > 0}
                        <ul
                            class="mt-1 flex flex-col gap-0.5 text-xs text-muted-foreground"
                        >
                            {#each Object.entries(entry.changes) as [key, value] (key)}
                                <li>
                                    <span class="font-medium"
                                        >{key.replaceAll('_', ' ')}</span
                                    >:
                                    {#if isChange(value)}
                                        <span class="line-through"
                                            >{describe(value.from)}</span
                                        >
                                        <span aria-hidden="true">·</span>
                                        <span class="sr-only">changed to</span>
                                        {describe(value.to)}
                                    {:else}
                                        {describe(value)}
                                    {/if}
                                </li>
                            {/each}
                        </ul>
                    {/if}
                    <p class="mt-1 text-xs text-muted-foreground">
                        {formatDateTime(entry.created_at)}
                    </p>
                </div>
            </li>
        {/each}
    </ol>
{/if}
