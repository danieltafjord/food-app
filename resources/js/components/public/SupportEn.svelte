<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import { privacy } from '@/routes/localized';
    import { edit } from '@/routes/profile';

    let {
        contact,
    }: { contact: { operatorName: string; supportEmail: string } } = $props();
</script>

<div lang="en" class="flex max-w-2xl flex-col gap-10">
    <header class="space-y-4">
        <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
            How can I help?
        </h1>
        <p class="text-lg leading-8 text-muted-foreground">
            Questions, a problem with the app, or an idea to share? Get in touch
            with {contact.operatorName}, the developer of Handlelista.
        </p>
    </header>

    <section
        aria-labelledby="contact-heading"
        class="space-y-4 rounded-2xl border border-border bg-muted/40 p-6 sm:p-8"
    >
        <h2 id="contact-heading" class="text-xl font-semibold">
            Email support
        </h2>
        <a
            href={`mailto:${contact.supportEmail}`}
            class="inline-flex min-h-11 items-center rounded-md text-lg font-medium break-all text-blue-700 underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-4 dark:text-blue-300"
            >{contact.supportEmail}</a
        >
        <p class="text-sm leading-6 text-muted-foreground">
            Include your app version, device model, and what happened.
            Screenshots can help, but please remove private information. Never
            send your password or verification codes.
        </p>
    </section>

    <div
        class="space-y-9 leading-7 [&_h2]:mb-3 [&_h2]:text-xl [&_h2]:font-semibold [&_p]:text-muted-foreground [&_a]:text-blue-700 [&_a]:underline [&_a]:underline-offset-4 dark:[&_a]:text-blue-300"
    >
        <section>
            <h2>My changes aren’t showing on another device</h2>
            <p>
                Check that both devices are online, signed in to the same
                account or household, and using the same household. Open the app
                on both devices and allow synchronization to finish. If changes
                are still missing, contact support before clearing device data;
                changes that haven’t synced could be lost.
            </p>
        </section>
        <section>
            <h2>I can’t sign in</h2>
            <p>
                Use “Forgot password?” on the sign-in page to reset your
                password. Check your spam folder for the email. If an invitation
                won’t open, sign in with the email address that received it and
                try the invitation again.
            </p>
        </section>
        <section>
            <h2>How do I delete my account?</h2>
            <p>
                In the app, open Account and choose “Delete account”. This opens
                your web profile, where you can sign in, choose “Delete account”
                and confirm with your password. You can also <Link href={edit()}
                    >open your account settings here</Link
                >. You don’t need to email support to start deletion.
            </p>
            <p class="mt-3">
                Account deletion removes your cloud account. Shared household
                data and copies on your devices need separate consideration.
                Read the <Link href={privacy.url('en') + '#deletion'}
                    >deletion details in our privacy policy</Link
                >. To remove the app’s local copy, use “Clear data on this
                device” in Account on each device.
            </p>
        </section>
        <section>
            <h2>Privacy and data requests</h2>
            <p>
                For access to your data, corrections, an export, or help
                removing personal information from shared content, email <a
                    href={`mailto:${contact.supportEmail}`}
                    >{contact.supportEmail}</a
                >. See the <Link href={privacy('en')}>privacy policy</Link> for how
                Handlelista handles your information.
            </p>
        </section>
    </div>
</div>
