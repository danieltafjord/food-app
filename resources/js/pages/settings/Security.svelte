<script module lang="ts">
    import AppLayout from '@/layouts/AppLayout.svelte';
    import SettingsLayout from '@/layouts/settings/Layout.svelte';
    import { edit } from '@/routes/security';

    export const layout = [
        [
            AppLayout,
            {
                breadcrumbs: [
                    {
                        title: 'Security settings',
                        href: edit(),
                    },
                ],
            },
        ],
        SettingsLayout,
    ];
</script>

<script lang="ts">
    import { Form, page } from '@inertiajs/svelte';
    import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import ManagePasskeys from '@/components/ManagePasskeys.svelte';
    import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.svelte';
    import ManageTwoFactor from '@/components/ManageTwoFactor.svelte';
    import PasswordInput from '@/components/PasswordInput.svelte';
    import { Button } from '@/components/ui/button';
    import { Label } from '@/components/ui/label';
    const canManageTwoFactor = $derived(Boolean(page.props.canManageTwoFactor));
    const requiresConfirmation = $derived(
        Boolean(page.props.requiresConfirmation),
    );
    const twoFactorEnabled = $derived(Boolean(page.props.twoFactorEnabled));
    const canManagePasskeys = $derived(Boolean(page.props.canManagePasskeys));
    const passkeys = $derived(
        (Array.isArray(page.props.passkeys)
            ? page.props.passkeys
            : []) as ManagePasskeysProps['passkeys'],
    );

    let { passwordRules }: { passwordRules: string } = $props();

    // Accounts made with Google start without a password.
    const hasPassword = $derived(page.props.auth.hasPassword);
</script>

<AppHead title="Security settings" />

<h1 class="sr-only">Security settings</h1>

<div class="space-y-6">
    <Heading
        variant="small"
        title={hasPassword ? 'Update password' : 'Set a password'}
        description={hasPassword
            ? 'Ensure your account is using a long, random password to stay secure'
            : 'You sign in with Google. Add a password to also sign in with your email.'}
    />

    <Form
        {...SecurityController.update.form()}
        class="space-y-6"
        options={{ preserveScroll: true }}
        resetOnSuccess
        resetOnError={['password', 'password_confirmation', 'current_password']}
    >
        {#snippet children({ errors, processing })}
            {#if hasPassword}
                <div class="grid gap-2">
                    <Label for="current_password">Current password</Label>
                    <PasswordInput
                        id="current_password"
                        name="current_password"
                        class="mt-1 block h-10 w-full rounded-full px-4 shadow-none"
                        autocomplete="current-password"
                        placeholder="Current password"
                    />
                    <InputError message={errors.current_password} />
                </div>
            {/if}

            <div class="grid gap-2">
                <Label for="password">New password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block h-10 w-full rounded-full px-4 shadow-none"
                    autocomplete="new-password"
                    placeholder="New password"
                    passwordrules={passwordRules}
                />
                <InputError message={errors.password} />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block h-10 w-full rounded-full px-4 shadow-none"
                    autocomplete="new-password"
                    placeholder="Confirm password"
                    passwordrules={passwordRules}
                />
                <InputError message={errors.password_confirmation} />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    class="rounded-full"
                    disabled={processing}
                    data-test="update-password-button"
                >
                    Save
                </Button>
            </div>
        {/snippet}
    </Form>
</div>

<ManageTwoFactor
    {canManageTwoFactor}
    {requiresConfirmation}
    {twoFactorEnabled}
/>

<ManagePasskeys {canManagePasskeys} {passkeys} />
