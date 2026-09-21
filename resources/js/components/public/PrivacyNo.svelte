<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import { support } from '@/routes/localized';
    import { edit } from '@/routes/profile';

    let {
        contact,
    }: { contact: { operatorName: string; supportEmail: string } } = $props();
</script>

<article lang="no" class="flex max-w-2xl flex-col gap-10">
    <header class="space-y-4">
        <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
            Personvernerklæring
        </h1>
        <p class="text-lg leading-8 text-muted-foreground">
            Handlelista hjelper deg med å planlegge måltider og dele
            handlelister. Her forklarer vi hva som skjer med opplysningene du
            bruker i appen.
        </p>
        <p class="text-sm text-muted-foreground">
            Sist oppdatert: <time datetime="2026-09-21">21. september 2026</time
            >
        </p>
    </header>

    <div
        class="space-y-9 leading-7 [&_h2]:mb-3 [&_h2]:text-xl [&_h2]:font-semibold [&_p]:text-muted-foreground [&_li]:text-muted-foreground [&_a]:text-blue-700 [&_a]:underline [&_a]:underline-offset-4 dark:[&_a]:text-blue-300"
    >
        <section>
            <h2>Hvem som er ansvarlig</h2>
            <p>
                {contact.operatorName} driver Handlelista og er behandlingsansvarlig
                for personopplysningene dine. Har du spørsmål eller forespørsler om
                personvern, kontakt
                <a href={`mailto:${contact.supportEmail}`}
                    >{contact.supportEmail}</a
                >.
            </p>
        </section>

        <section>
            <h2>Opplysninger du gir oss</h2>
            <ul class="list-disc space-y-3 pl-5">
                <li>
                    <strong class="text-foreground">Kontoopplysninger:</strong>
                    navn, e-postadresse, passord lagret som en hash, status for e-postbekreftelse
                    og valg for språk og utseende. Hvis du slår på ekstra innloggingssikring,
                    lagrer vi også det som trengs for tofaktorautentisering eller
                    passnøkler.
                </li>
                <li>
                    <strong class="text-foreground">Husstandsinnhold:</strong>
                    navn på husstander og hvem som er medlemmer, invitasjoner og mottakernes
                    e-postadresser, oppskrifter og notater, ingredienser og mengder,
                    måltidsplaner og datoer, handlelister og avkryssede varer.
                </li>
                <li>
                    <strong class="text-foreground">Supporthenvendelser:</strong
                    >
                    e-postadressen din og det du velger å ta med når du kontakter
                    support, for eksempel en beskrivelse av et problem eller skjermbilder.
                </li>
            </ul>
            <p class="mt-3">
                Andre medlemmer av husstanden kan legge inn delt innhold, eller
                oppgi e-postadressen din når de inviterer deg. Kontoopplysninger
                er nødvendige for synkronisering i nettskyen og deling; du kan
                bruke appens lokale funksjoner uten en konto.
            </p>
        </section>

        <section>
            <h2>Lagring på enheten og tekniske opplysninger</h2>
            <p>
                Appen lagrer en lokal kopi av listene og planene dine, slik at
                du kan bruke dem uten nett. Når du kobler til en konto,
                synkroniseres husstandsinnholdet med serveren.
                Innloggingstokener i appen lagres i enhetens sikre lager for
                legitimasjon.
            </p>
            <p class="mt-3">
                Nettstedet bruker informasjonskapsler for å holde deg innlogget
                og beskytte skjemaer, og lokal lagring for valg som utseende.
                Innlogging og serverlogger kan inneholde IP-adressen din,
                opplysninger om nettleser eller enhet, tidspunkter for
                forespørsler og feil. Appen inneholder ikke reklame eller
                analyseverktøy fra tredjeparter.
            </p>
        </section>

        <section>
            <h2>Hvorfor vi bruker opplysningene</h2>
            <p>
                Vi bruker konto- og husstandsopplysninger for å levere tjenesten
                du ber om: innlogging, synkronisering, invitasjoner, delte
                lister og kontoadministrasjon. Behandlingsgrunnlaget er
                oppfyllelse av avtalen med deg (GDPR artikkel 6 nr. 1 bokstav
                b).
            </p>
            <p class="mt-3">
                Vi bruker tekniske logger og supporthenvendelser for å løse
                problemer, svare på forespørsler og beskytte tjenesten mot
                misbruk. Dette bygger på vår berettigede interesse i å drive en
                pålitelig og sikker tjeneste (artikkel 6 nr. 1 bokstav f). Der
                behandlingen er pålagt ved lov, er grunnlaget en rettslig
                forpliktelse (artikkel 6 nr. 1 bokstav c). Vi bruker ikke
                dataene dine til reklame, eller til avgjørelser med rettslig
                eller tilsvarende betydelig virkning som utelukkende bygger på
                automatisert behandling.
            </p>
        </section>

        <section>
            <h2>Hvem som mottar opplysninger</h2>
            <p>
                Medlemmer av husstanden din kan se delt husstandsinnhold og hvem
                som er medlemmer. Invitasjoner viser de opplysningene som trengs
                for å bli med i husstanden. Del bare opplysninger du vil at
                andre medlemmer skal se.
            </p>
            <p class="mt-3">
                Serveren og databasen vår kjører på Laravel Cloud i regionen «EU
                West (London)», i Storbritannia, på infrastruktur fra Amazon Web
                Services (AWS). Laravel Cloud bruker Cloudflare til
                nettverkslevering og sikkerhet, noe som kan innebære at
                tilkoblingsopplysninger som IP-adresser behandles utenfor
                vertsregionen. Informasjon om Laravels databehandlervilkår og
                underleverandører finnes i deres <a
                    href="https://trust.laravel.com/">Trust Center</a
                >.
            </p>
            <p class="mt-3">
                Vi bruker Resend til e-post knyttet til kontoen, som
                bekreftelse, tilbakestilling av passord og invitasjoner, med
                Irland valgt som senderegion. Disse meldingene inneholder
                mottakerens e-postadresse og innholdet som trengs for den
                aktuelle meldingen. E-post du sender til support, mottas i
                utviklerens vanlige e-postkonto.
            </p>
            <p class="mt-3">
                Irland-innstillingen avgjør hvor e-postene sendes fra. Ifølge
                Resends <a href="https://resend.com/security/gdpr"
                    >informasjon om datalagring</a
                >
                lagres meldingsinnhold, leveringslogger, webhook-data og kontoopplysninger
                i USA, uavhengig av valgt senderegion. Resends
                <a href="https://resend.com/legal/dpa">databehandleravtale</a>
                inneholder EUs standard personvernbestemmelser for internasjonale
                overføringer der det er aktuelt.
                <a href="https://resend.com/legal/subprocessors"
                    >Listen over underleverandører</a
                > beskriver hvilke leverandører de bruker. At serveren står i London,
                betyr ikke at all behandling av e-post eller nettverkstrafikk skjer
                i Storbritannia.
            </p>
            <p class="mt-3">
                Vi selger ikke personopplysningene dine. Opplysninger kan også
                bli utlevert når loven krever det.
            </p>
        </section>

        <section id="deletion" class="scroll-mt-8">
            <h2>Lagringstid og sletting</h2>
            <p>
                Konto- og husstandsdata i nettskyen lagres så lenge du bruker
                tjenesten. Når du fjerner en enkelt liste, oppskrift eller plan,
                skjules den fra vanlig bruk, men serveren kan beholde posten for
                å synkronisere slettingen til andre enheter. Det finnes i dag
                ingen planlagt rydding av slike enkeltvis slettede poster.
            </p>
            <p class="mt-3">
                For å slette kontoen din i nettskyen velger du «Slett konto»
                under Konto i appen, eller <Link href={edit()}
                    >åpner profilen din på nett</Link
                >, logger inn og bekrefter slettingen med passordet ditt.
                Slettingen fjerner kontoen, innloggingsopplysninger, økter og
                tilhørende invitasjoner. En husstand uten gjenværende medlemmer
                slettes sammen med innholdet som er lagret i den.
            </p>
            <p class="mt-3">
                Hvis husstanden fortsatt har medlemmer, er den fortsatt
                tilgjengelig for dem. Innhold som er registrert som opprettet av
                deg, også ingredienser og varer i delte lister og planer,
                slettes. Felter i andre medlemmers innhold som viser dine
                bidrag, tømmes; annet innhold blir stående. Minimale poster
                beholdes for å synkronisere disse slettingene.
            </p>
            <p class="mt-3">
                Sletting i nettskyen fjerner ikke lokale kopier på enhetene
                dine, eller kopier som andre allerede har. Bruk «Slett data på
                denne enheten» under Konto i appen på hver enhet for å fjerne
                den lokale kopien. Å slette data på enheten sletter ikke kontoen
                din i nettskyen.
            </p>
            <p class="mt-3">
                Sikkerhetskopiering av databasen er for tiden slått av i Laravel
                Cloud, og vi har ingen egne sikkerhetskopier av databasen.
                Logger som er tilgjengelige gjennom Laravel Cloud, lagres i én
                dag med vårt nåværende <a
                    href="https://laravel.com/cloud/pricing"
                    >Starter-abonnement</a
                >. Denne lagringstiden gjelder det vi ser i kontrollpanelet, og
                beskriver ikke alle interne infrastruktur- eller
                sikkerhetslogger en leverandør kan ha.
            </p>
            <p class="mt-3">
                Med vårt nåværende Resend-abonnement lagres e-postinnhold og
                logger i 30 dager, og Resends sikkerhetskopier finnes i sju
                dager, ifølge deres <a href="https://resend.com/security/gdpr"
                    >informasjon om lagringstid</a
                >. Når du sletter en Handlelista-konto, fjernes ikke disse
                e-postloggene med det samme. Kontakt support hvis du vil ha
                hjelp til å få dem fjernet tidligere.
            </p>
        </section>

        <section>
            <h2>Dine valg og rettigheter</h2>
            <p>
                Innenfor rammene av gjeldende lov kan du be om innsyn, retting,
                sletting, begrensning av behandlingen eller en flyttbar kopi av
                personopplysningene dine. Du kan også protestere mot behandling
                som bygger på berettiget interesse. Send e-post til <a
                    href={`mailto:${contact.supportEmail}`}
                    >{contact.supportEmail}</a
                >; vi kan måtte bekrefte identiteten din før vi utleverer eller
                endrer personopplysninger.
            </p>
            <p class="mt-3">
                Du kan klage til <a href="https://www.datatilsynet.no/"
                    >Datatilsynet</a
                > eller til personvernmyndigheten der du bor.
            </p>
        </section>

        <section>
            <h2>Endringer i denne erklæringen</h2>
            <p>
                Vi oppdaterer denne siden når praksisen vår endres, og retter
                datoen øverst. Vesentlige endringer som påvirker
                personopplysningene dine, varsles i appen eller på e-post, alt
                etter hva som passer.
            </p>
            <p class="mt-3">
                Trenger du hjelp? Gå til <Link href={support('no')}
                    >hjelpesiden for Handlelista</Link
                >.
            </p>
        </section>
    </div>
</article>
