<script module lang="ts">
    import { edit } from '@/routes/ai-assistance';

    export const layout = {
        breadcrumbs: [
            {
                title: 'AI assistance',
                href: edit(),
            },
        ],
    };
</script>

<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import AiAssistanceController from '@/actions/App/Http/Controllers/Settings/AiAssistanceController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Checkbox } from '@/components/ui/checkbox';

    type Feature = { key: string; label: string; daily_limit: number };
    type Usage = Record<string, { remaining?: number }> & {
        resets_at: string;
    };

    let {
        settings,
        available,
        features,
        usage,
    }: {
        settings: {
            categorization_enabled: boolean;
            suggestions_enabled: boolean;
        };
        available: boolean;
        features: Feature[];
        usage: Usage | null;
    } = $props();

    const descriptions: Record<string, string> = {
        categorization:
            'When you add an ingredient the app can pick the shopping aisle for you. Only the ingredient name is sent.',
        suggestions:
            'When you plan a dinner the app can suggest a few ingredients you may have forgotten. The dinner name, its ingredients and the names of ingredients in your household are sent.',
    };

    // The form is seeded from the initial props on purpose; a save reloads the page.
    // svelte-ignore state_referenced_locally
    const form = useForm({
        categorization_enabled: settings.categorization_enabled,
        suggestions_enabled: settings.suggestions_enabled,
    });

    const isDirty = $derived(
        form.categorization_enabled !== settings.categorization_enabled ||
            form.suggestions_enabled !== settings.suggestions_enabled,
    );

    function fieldFor(
        key: string,
    ): 'categorization_enabled' | 'suggestions_enabled' {
        return key === 'categorization'
            ? 'categorization_enabled'
            : 'suggestions_enabled';
    }

    function remaining(key: string): number | null {
        const value = usage?.[key]?.remaining;

        return typeof value === 'number' ? value : null;
    }

    function submit(event: SubmitEvent) {
        event.preventDefault();
        form.patch(AiAssistanceController.update.url(), {
            preserveScroll: true,
        });
    }
</script>

<AppHead title="AI assistance" />

<h1 class="sr-only">AI assistance</h1>

<div class="flex flex-col space-y-6">
    <Heading
        variant="small"
        title="AI assistance"
        description="Optional helpers that send small pieces of your data to an AI model. Both are off until you turn them on."
    />

    {#if !available}
        <p
            class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200"
        >
            AI assistance is currently switched off on the server. Your choices
            are saved and take effect as soon as it is back.
        </p>
    {/if}

    <form onsubmit={submit} class="space-y-6">
        <div class="overflow-hidden rounded-2xl border border-border/80">
            {#each features as feature (feature.key)}
                {@const field = fieldFor(feature.key)}
                {@const left = remaining(feature.key)}
                <label
                    class="flex cursor-pointer items-start gap-4 border-b p-4 last:border-b-0"
                >
                    <Checkbox bind:checked={form[field]} class="mt-0.5" />
                    <span class="min-w-0 space-y-1">
                        <span class="block font-medium tracking-tight">
                            {feature.label}
                        </span>
                        <span class="block text-sm text-muted-foreground">
                            {descriptions[feature.key] ?? ''}
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            Up to {feature.daily_limit} per day.
                            {#if left !== null && form[field]}
                                {left} left today.
                            {/if}
                        </span>
                    </span>
                </label>
            {/each}
        </div>
        <InputError message={form.errors.categorization_enabled} />
        <InputError message={form.errors.suggestions_enabled} />

        <div class="flex items-center gap-4">
            <Button
                type="submit"
                class="rounded-full"
                disabled={form.processing || !isDirty}
                data-test="save-ai-assistance-button">Save</Button
            >
            {#if form.recentlySuccessful}
                <span class="text-sm text-muted-foreground">Saved</span>
            {/if}
        </div>
    </form>

    <p class="text-sm text-muted-foreground">
        Requests are never used to train models, and the text you send is not
        stored. Daily limits reset at midnight UTC.
    </p>
</div>
