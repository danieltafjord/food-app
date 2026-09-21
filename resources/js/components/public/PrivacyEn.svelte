<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import { support } from '@/routes/localized';
    import { edit } from '@/routes/profile';

    let {
        contact,
    }: { contact: { operatorName: string; supportEmail: string } } = $props();
</script>

<article lang="en" class="flex max-w-2xl flex-col gap-10">
    <header class="space-y-4">
        <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
            Privacy policy
        </h1>
        <p class="text-lg leading-8 text-muted-foreground">
            Handlelista helps you plan meals and share shopping lists. This
            notice explains what happens to the information you use in the app.
        </p>
        <p class="text-sm text-muted-foreground">
            Last updated: <time datetime="2026-09-21">21 September 2026</time>
        </p>
    </header>

    <div
        class="space-y-9 leading-7 [&_h2]:mb-3 [&_h2]:text-xl [&_h2]:font-semibold [&_p]:text-muted-foreground [&_li]:text-muted-foreground [&_a]:text-blue-700 [&_a]:underline [&_a]:underline-offset-4 dark:[&_a]:text-blue-300"
    >
        <section>
            <h2>Who is responsible</h2>
            <p>
                {contact.operatorName} operates Handlelista and is the controller
                responsible for your personal data. For privacy questions or requests,
                contact
                <a href={`mailto:${contact.supportEmail}`}
                    >{contact.supportEmail}</a
                >.
            </p>
        </section>

        <section>
            <h2>Information you provide</h2>
            <ul class="list-disc space-y-3 pl-5">
                <li>
                    <strong class="text-foreground">Account information:</strong
                    > your name, email address, password stored as a hash, email verification
                    status, and language and appearance preferences. If you enable
                    extra sign-in protection, we also store the credentials needed
                    for two-factor authentication or passkeys.
                </li>
                <li>
                    <strong class="text-foreground">Household content:</strong> household
                    names and membership, invitations and recipient email addresses,
                    recipes and notes, ingredients and quantities, meal plans and
                    dates, shopping lists and checked items.
                </li>
                <li>
                    <strong class="text-foreground">Support messages:</strong> your
                    email address and whatever you include when you contact support,
                    such as a description of a problem or screenshots.
                </li>
            </ul>
            <p class="mt-3">
                Other household members may provide shared content or your email
                address when inviting you. Account details are needed for cloud
                synchronization and sharing; you can use the app’s local
                features without a cloud account.
            </p>
        </section>

        <section>
            <h2>Device storage and technical information</h2>
            <p>
                The app keeps a local copy of your lists and plans so you can
                use them offline. When you connect an account, household content
                synchronizes with the backend. Native sign-in tokens use the
                device’s secure credential storage.
            </p>
            <p class="mt-3">
                The website uses cookies to maintain your sign-in session and
                protect forms, and local storage for preferences such as
                appearance. Authentication and server records can include your
                IP address, browser or device information, request times and
                errors. The app does not include advertising or third-party
                analytics SDKs.
            </p>
        </section>

        <section>
            <h2>Why we use this information</h2>
            <p>
                We use account and household information to provide the service
                you request: sign-in, synchronization, invitations, shared lists
                and account administration. The legal basis is performance of
                our agreement with you (GDPR Article 6(1)(b)).
            </p>
            <p class="mt-3">
                We use technical records and support messages to resolve
                problems, answer requests and protect the service from abuse.
                This is based on our legitimate interests in running a reliable
                and secure service (Article 6(1)(f)). Where processing is
                required by law, the basis is a legal obligation (Article
                6(1)(c)). We do not use your data for advertising or decisions
                with legal or similarly significant effects made solely by
                automated processing.
            </p>
        </section>

        <section>
            <h2>Who receives information</h2>
            <p>
                Members of your household can see shared household content and
                membership information. Invitations disclose information needed
                to join the household. Share only information you intend other
                members to see.
            </p>
            <p class="mt-3">
                Our backend and database run on Laravel Cloud in the “EU West
                (London)” region, in the United Kingdom, using Amazon Web
                Services (AWS) infrastructure. Laravel Cloud uses Cloudflare for
                network delivery and security, which can involve processing
                connection information such as IP addresses outside the hosting
                region. Information about Laravel’s data-processing terms and
                service providers is available in its <a
                    href="https://trust.laravel.com/">Trust Center</a
                >.
            </p>
            <p class="mt-3">
                We use Resend for account emails, such as verification, password
                resets and invitations, with Ireland selected as our sending
                region. These messages contain the recipient’s email address and
                the content needed for that message. Emails you send to support
                are received in the developer’s regular email account.
            </p>
            <p class="mt-3">
                The Ireland setting determines where emails are sent from.
                According to Resend’s <a href="https://resend.com/security/gdpr"
                    >data-residency information</a
                >, message content, delivery logs, webhook payloads and account
                records are stored in the United States, regardless of the
                selected sending region. Its
                <a href="https://resend.com/legal/dpa"
                    >Data Processing Addendum</a
                >
                includes EU Standard Contractual Clauses for applicable international
                transfers. Its
                <a href="https://resend.com/legal/subprocessors"
                    >subprocessor list</a
                > describes the providers it uses. The London hosting region does
                not mean that all email or network processing takes place in the United
                Kingdom.
            </p>
            <p class="mt-3">
                We do not sell your personal data. Information may also be
                disclosed when required by law.
            </p>
        </section>

        <section id="deletion" class="scroll-mt-8">
            <h2>Retention and deletion</h2>
            <p>
                Cloud account and household data are stored while you use the
                service. Removing an individual list, recipe or plan hides it
                from normal use, but the backend can retain the record to
                synchronize deletion across devices. There is currently no
                scheduled purge of these individual deleted records.
            </p>
            <p class="mt-3">
                To delete your cloud account, choose “Delete account” in the
                app’s Account screen, or <Link href={edit()}
                    >open your web profile</Link
                >, sign in and confirm deletion with your password. Deletion
                removes your account, access credentials, sessions and related
                invitations. A household with no remaining members is deleted
                along with its stored content.
            </p>
            <p class="mt-3">
                If a household still has members, it remains available to them.
                Content recorded as created by you, including ingredients and
                items within shared lists and plans, is erased. Fields in other
                members’ content that record your contributions are cleared;
                unrelated content remains. Minimal records remain to synchronize
                those deletions.
            </p>
            <p class="mt-3">
                Cloud deletion does not remotely erase offline copies on your
                devices or copies already held by other people. Use “Clear data
                on this device” in the app’s Account screen on each device to
                remove its local copy. Clearing device data alone does not
                delete your cloud account.
            </p>
            <p class="mt-3">
                Database backups are currently disabled in Laravel Cloud, and we
                do not keep separate database backups. Logs available through
                Laravel Cloud are retained for one day under our current <a
                    href="https://laravel.com/cloud/pricing">Starter plan</a
                >. This dashboard retention does not describe every internal
                infrastructure or security record held by a provider.
            </p>
            <p class="mt-3">
                Under our current Resend plan, email content and logs are
                retained for 30 days, and Resend’s backups persist for seven
                days, according to its <a
                    href="https://resend.com/security/gdpr"
                    >retention information</a
                >. Deleting a Handlelista account does not immediately remove
                these email records. Contact support to request help with
                earlier removal.
            </p>
        </section>

        <section>
            <h2>Your choices and rights</h2>
            <p>
                Subject to applicable law, you can request access, correction,
                deletion, restriction of processing or a portable copy of your
                personal data. You can also object to processing based on
                legitimate interests. Email <a
                    href={`mailto:${contact.supportEmail}`}
                    >{contact.supportEmail}</a
                >; we may need to verify your identity before providing or
                changing personal data.
            </p>
            <p class="mt-3">
                You can complain to <a href="https://www.datatilsynet.no/"
                    >Datatilsynet</a
                >, the Norwegian Data Protection Authority, or your local data
                protection authority.
            </p>
        </section>

        <section>
            <h2>Changes to this notice</h2>
            <p>
                We will update this page when our practices change and revise
                the date above. Material changes affecting your personal data
                will be communicated in the app or by email as appropriate.
            </p>
            <p class="mt-3">
                Need help? Visit <Link href={support('en')}
                    >Handlelista support</Link
                >.
            </p>
        </section>
    </div>
</article>
