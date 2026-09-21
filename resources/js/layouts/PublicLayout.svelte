<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import { home, privacy, support } from '@/routes/localized';

    let { children }: { children?: Snippet } = $props();

    const copy = {
        en: {
            skipToContent: 'Skip to content',
            language: 'Language',
            footer: 'Footer',
            privacy: 'Privacy policy',
            support: 'Get help',
            madeBy: 'Made by Daniel Tafjord in Norway',
        },
        no: {
            skipToContent: 'Hopp til innholdet',
            language: 'Språk',
            footer: 'Bunntekst',
            privacy: 'Personvern',
            support: 'Få hjelp',
            madeBy: 'Laget av Daniel Tafjord i Norge',
        },
    };

    const languages = [
        { locale: 'no', label: 'Norsk' },
        { locale: 'en', label: 'English' },
    ] as const;

    const locale = $derived(page.props.locale);
    const t = $derived(copy[locale]);

    const pathWithoutLocale = $derived(
        page.url.split(/[?#]/)[0].replace(/^\/(no|en)(?=\/|$)/, ''),
    );

    function urlInLanguage(language: 'no' | 'en'): string {
        return `/${language}${pathWithoutLocale}`;
    }

    $effect(() => {
        document.documentElement.lang = locale;
    });
</script>

<svelte:head>
    {#if page.props.seo}
        <title>{page.props.seo.title}</title>
    {/if}
</svelte:head>

<div class="flex min-h-svh flex-col bg-background text-foreground">
    <a
        href="#main-content"
        class="sr-only z-50 rounded-md bg-background p-3 focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
        >{t.skipToContent}</a
    >
    <!-- Inertia's Link keeps navigating to its first href, so re-create the links when the URL changes. -->
    {#key page.url}
        <header>
            <div
                class="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3 px-6 py-5 sm:px-8"
            >
                <Link
                    href={home(locale)}
                    class="inline-flex min-h-11 items-center rounded-sm text-lg font-semibold focus-visible:outline-2 focus-visible:outline-offset-4"
                >
                    <img src="/logo.svg" alt="" class="mr-2.5 size-9" />
                    Handlelista
                </Link>
                <nav aria-label={t.language} class="flex gap-1 text-sm">
                    {#each languages as language (language.locale)}
                        <Link
                            href={urlInLanguage(language.locale)}
                            preserveScroll
                            hreflang={language.locale}
                            lang={language.locale}
                            aria-current={language.locale === locale
                                ? 'true'
                                : undefined}
                            class="inline-flex min-h-11 items-center rounded-md px-3 text-muted-foreground hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 aria-current:font-semibold aria-current:text-foreground"
                            >{language.label}</Link
                        >
                    {/each}
                </nav>
            </div>
        </header>
    {/key}

    <main
        id="main-content"
        tabindex="-1"
        class="mx-auto flex w-full max-w-4xl grow flex-col px-6 py-12 sm:px-8 sm:py-16"
    >
        {@render children?.()}
    </main>

    {#key page.url}
        <footer>
            <div
                class="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3 px-6 py-6 text-sm text-muted-foreground sm:px-8"
            >
                <p>© {new Date().getFullYear()} Handlelista · {t.madeBy}</p>
                <nav aria-label={t.footer} class="flex gap-4">
                    <Link
                        href={privacy(locale)}
                        class="inline-flex min-h-11 items-center underline underline-offset-4"
                        >{t.privacy}</Link
                    >
                    <Link
                        href={support(locale)}
                        class="inline-flex min-h-11 items-center underline underline-offset-4"
                        >{t.support}</Link
                    >
                </nav>
            </div>
        </footer>
    {/key}
</div>
