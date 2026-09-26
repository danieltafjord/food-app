<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import { home } from '@/routes/localized';

    let {
        householdName,
        appUrl,
        appStoreUrl,
    }: {
        householdName: string | null;
        appUrl: string;
        appStoreUrl: string | null;
    } = $props();

    const copy = {
        en: {
            title: 'Invitation',
            heading: 'You’re invited to a household',
            intro: (name: string) =>
                `You’ve been invited to join “${name}” on Handlelista, where you plan dinners and share the shopping list.`,
            steps: 'Open the invitation in the app to join. Don’t have the app yet? Install it, sign in, and open the link from the email again.',
            openInApp: 'Open in the app',
            appStore: 'Download on the App Store',
            invalidHeading: 'This invitation can’t be used',
            invalidText:
                'It may have expired, been used already or been withdrawn. Ask the person who invited you to send a new one.',
            home: 'Go to the front page',
        },
        no: {
            title: 'Invitasjon',
            heading: 'Du er invitert til en husstand',
            intro: (name: string) =>
                `Du er invitert til «${name}» i Handlelista, der dere planlegger middager og deler handlelisten.`,
            steps: 'Åpne invitasjonen i appen for å bli med. Har du ikke appen ennå? Installer den, logg inn og åpne lenken i e-posten på nytt.',
            openInApp: 'Åpne i appen',
            appStore: 'Last ned fra App Store',
            invalidHeading: 'Denne invitasjonen kan ikke brukes',
            invalidText:
                'Den kan ha utløpt, allerede være brukt eller blitt trukket tilbake. Be den som inviterte deg om å sende en ny.',
            home: 'Gå til forsiden',
        },
    };

    const locale = $derived(page.props.locale);
    const t = $derived(copy[locale]);
</script>

<svelte:head>
    <title>{t.title} | Handlelista</title>
    <meta name="referrer" content="no-referrer" />
</svelte:head>

<div class="my-auto flex max-w-xl flex-col gap-8">
    {#if householdName}
        <header class="space-y-4">
            <h1
                class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
            >
                {t.heading}
            </h1>
            <p class="text-lg leading-8 text-muted-foreground">
                {t.intro(householdName)}
            </p>
            <p class="leading-7 text-muted-foreground">{t.steps}</p>
        </header>

        <div class="flex flex-wrap gap-3">
            <a
                href={appUrl}
                class="inline-flex min-h-11 items-center rounded-md bg-primary px-6 font-medium text-primary-foreground hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-2"
                >{t.openInApp}</a
            >
            {#if appStoreUrl}
                <a
                    href={appStoreUrl}
                    rel="noreferrer"
                    class="inline-flex min-h-11 items-center rounded-md border border-border px-6 font-medium hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2"
                    >{t.appStore}</a
                >
            {/if}
        </div>
    {:else}
        <header class="space-y-4">
            <h1
                class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
            >
                {t.invalidHeading}
            </h1>
            <p class="text-lg leading-8 text-muted-foreground">
                {t.invalidText}
            </p>
        </header>

        <Link
            href={home(locale)}
            class="inline-flex min-h-11 items-center self-start underline underline-offset-4"
            >{t.home}</Link
        >
    {/if}
</div>
