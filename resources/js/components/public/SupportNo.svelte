<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import { privacy } from '@/routes/localized';
    import { edit } from '@/routes/profile';

    let {
        contact,
    }: { contact: { operatorName: string; supportEmail: string } } = $props();
</script>

<div lang="no" class="flex max-w-2xl flex-col gap-10">
    <header class="space-y-4">
        <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
            Hva kan jeg hjelpe med?
        </h1>
        <p class="text-lg leading-8 text-muted-foreground">
            Har du spørsmål, et problem med appen eller en idé du vil dele? Ta
            kontakt med {contact.operatorName}, utvikleren av Handlelista.
        </p>
    </header>

    <section
        aria-labelledby="contact-heading"
        class="space-y-4 rounded-2xl border border-border bg-muted/40 p-6 sm:p-8"
    >
        <h2 id="contact-heading" class="text-xl font-semibold">
            Hjelp på e-post
        </h2>
        <a
            href={`mailto:${contact.supportEmail}`}
            class="inline-flex min-h-11 items-center rounded-md text-lg font-medium break-all text-blue-700 underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-4 dark:text-blue-300"
            >{contact.supportEmail}</a
        >
        <p class="text-sm leading-6 text-muted-foreground">
            Oppgi appversjon, hvilken enhet du bruker og hva som skjedde.
            Skjermbilder kan være til hjelp, men fjern private opplysninger
            først. Send aldri passord eller engangskoder.
        </p>
    </section>

    <div
        class="space-y-9 leading-7 [&_h2]:mb-3 [&_h2]:text-xl [&_h2]:font-semibold [&_p]:text-muted-foreground [&_a]:text-blue-700 [&_a]:underline [&_a]:underline-offset-4 dark:[&_a]:text-blue-300"
    >
        <section>
            <h2>Endringene mine vises ikke på en annen enhet</h2>
            <p>
                Sjekk at begge enhetene er på nett, logget inn på samme konto og
                bruker samme husstand. Åpne appen på begge enhetene og la
                synkroniseringen bli ferdig. Hvis endringer fortsatt mangler, ta
                kontakt før du sletter data på enheten – endringer som ikke er
                synkronisert, kan gå tapt.
            </p>
        </section>
        <section>
            <h2>Jeg får ikke logget inn</h2>
            <p>
                Bruk «Forgot password?» på innloggingssiden for å lage et nytt
                passord. Sjekk søppelposten hvis e-posten ikke dukker opp. Hvis
                en invitasjon ikke lar seg åpne, logg inn med e-postadressen som
                mottok den, og prøv invitasjonen på nytt.
            </p>
        </section>
        <section>
            <h2>Hvordan sletter jeg kontoen min?</h2>
            <p>
                Åpne Konto i appen og velg «Slett konto». Da åpnes profilen din
                på nett, der du logger inn, velger «Delete account» og bekrefter
                med passordet ditt. Du kan også <Link href={edit()}
                    >åpne kontoinnstillingene her</Link
                >. Du trenger ikke å sende e-post for å starte slettingen.
            </p>
            <p class="mt-3">
                Når du sletter kontoen, fjernes kontoen din i nettskyen. Delt
                innhold i husstanden og kopier på enhetene dine må vurderes for
                seg. Les <Link href={privacy.url('no') + '#deletion'}
                    >mer om sletting i personvernerklæringen</Link
                >. For å fjerne appens lokale kopi bruker du «Slett data på
                denne enheten» under Konto på hver enhet.
            </p>
        </section>
        <section>
            <h2>Personvern og forespørsler om data</h2>
            <p>
                Vil du ha innsyn i dataene dine, rette noe, få en eksport eller
                hjelp til å fjerne personopplysninger fra delt innhold? Send
                e-post til <a href={`mailto:${contact.supportEmail}`}
                    >{contact.supportEmail}</a
                >. I <Link href={privacy('no')}>personvernerklæringen</Link> kan du
                lese hvordan Handlelista behandler opplysningene dine.
            </p>
        </section>
    </div>
</div>
