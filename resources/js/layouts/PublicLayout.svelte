<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import { home, privacy, support } from '@/routes';

    let { children }: { children?: Snippet } = $props();
</script>

<div class="min-h-svh bg-background text-foreground">
    <a
        href="#main-content"
        class="sr-only z-50 rounded-md bg-background p-3 focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
        >Skip to content</a
    >
    <header class="border-b border-border">
        <div
            class="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3 px-6 py-5 sm:px-8"
        >
            <Link
                href={home()}
                class="inline-flex min-h-11 items-center rounded-sm text-xl font-semibold tracking-tight focus-visible:outline-2 focus-visible:outline-offset-4"
            >
                <span
                    class="mr-2 size-2.5 rounded-full bg-blue-600 dark:bg-blue-400"
                    aria-hidden="true"
                ></span>
                Handlelista
            </Link>
            <nav
                aria-label="Public pages"
                class="flex gap-1 text-sm font-medium"
            >
                <Link
                    href={privacy()}
                    class="inline-flex min-h-11 items-center rounded-md px-3 hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2"
                    >Privacy</Link
                >
                <Link
                    href={support()}
                    class="inline-flex min-h-11 items-center rounded-md px-3 hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2"
                    >Support</Link
                >
            </nav>
        </div>
    </header>

    <main
        id="main-content"
        tabindex="-1"
        class="mx-auto max-w-4xl px-6 py-12 sm:px-8 sm:py-16"
    >
        {@render children?.()}
    </main>

    <footer class="border-t border-border">
        <div
            class="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3 px-6 py-6 text-sm text-muted-foreground sm:px-8"
        >
            <p>Handlelista · Meal plans and shared shopping lists.</p>
            <nav aria-label="Footer" class="flex gap-4">
                <Link
                    href={privacy()}
                    class="inline-flex min-h-11 items-center underline underline-offset-4"
                    >Privacy policy</Link
                >
                <Link
                    href={support()}
                    class="inline-flex min-h-11 items-center underline underline-offset-4"
                    >Get help</Link
                >
            </nav>
        </div>
    </footer>
</div>
