<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Separator } from '@/components/ui/separator';
    import { redirect } from '@/routes/social';

    type Props = {
        providers: string[];
        /** Re-check a signed-in person instead of signing in. */
        confirm?: boolean;
        separator?: string | null;
    };

    let {
        providers,
        confirm = false,
        separator = 'Or continue with email',
    }: Props = $props();

    const labels: Record<string, string> = { google: 'Google' };
    const error = $derived(page.props.errors?.social as string | undefined);
</script>

{#if providers.length > 0}
    <div class="grid gap-2">
        {#each providers as provider (provider)}
            <Button asChild variant="outline" class="w-full">
                {#snippet children(props)}
                    <!-- A full page load: the provider's page is not an Inertia visit. -->
                    <a
                        {...props}
                        href={redirect.url(
                            provider,
                            confirm ? { query: { confirm: 1 } } : undefined,
                        )}
                        data-test="social-{provider}-button"
                    >
                        {#if provider === 'google'}
                            <svg
                                class="h-4 w-4"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    fill="#4285F4"
                                    d="M23.52 12.27c0-.85-.08-1.67-.22-2.45H12v4.64h6.46a5.52 5.52 0 0 1-2.4 3.62v3h3.88c2.27-2.09 3.58-5.17 3.58-8.81z"
                                />
                                <path
                                    fill="#34A853"
                                    d="M12 24c3.24 0 5.96-1.07 7.94-2.9l-3.88-3.02c-1.07.72-2.45 1.15-4.06 1.15-3.12 0-5.77-2.11-6.71-4.95H1.28v3.11A12 12 0 0 0 12 24z"
                                />
                                <path
                                    fill="#FBBC05"
                                    d="M5.29 14.28a7.2 7.2 0 0 1 0-4.56V6.61H1.28a12 12 0 0 0 0 10.78l4.01-3.11z"
                                />
                                <path
                                    fill="#EA4335"
                                    d="M12 4.77c1.76 0 3.34.61 4.59 1.8l3.44-3.44A11.97 11.97 0 0 0 12 0 12 12 0 0 0 1.28 6.61l4.01 3.11C6.23 6.88 8.88 4.77 12 4.77z"
                                />
                            </svg>
                        {/if}
                        Continue with {labels[provider] ?? provider}
                    </a>
                {/snippet}
            </Button>
        {/each}

        {#if error}
            <div class="text-center">
                <InputError message={error} />
            </div>
        {/if}
    </div>

    {#if separator}
        <div class="relative my-6">
            <div class="absolute inset-0 flex items-center">
                <Separator class="w-full" />
            </div>
            <div class="relative flex justify-center text-xs uppercase">
                <span class="bg-background px-2 text-muted-foreground">
                    {separator}
                </span>
            </div>
        </div>
    {/if}
{/if}
