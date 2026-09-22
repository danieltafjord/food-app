<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import PasswordInput from '@/components/PasswordInput.svelte';
    import { Button } from '@/components/ui/button';
    import {
        Dialog,
        DialogClose,
        DialogContent,
        DialogDescription,
        DialogFooter,
        DialogTitle,
        DialogTrigger,
    } from '@/components/ui/dialog';
    import { Label } from '@/components/ui/label';
</script>

<div class="space-y-6">
    <Heading
        variant="small"
        title="Delete account"
        description="Permanently delete your account"
    />
    <div
        class="space-y-4 rounded-2xl border border-red-100 bg-red-50 p-5 dark:border-red-200/10 dark:bg-red-700/10"
    >
        <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
            <p class="font-medium">Warning</p>
            <p class="text-sm">
                Please proceed with caution, this cannot be undone.
            </p>
        </div>
        <Dialog>
            <DialogTrigger>
                <Button
                    variant="destructive"
                    class="rounded-full"
                    data-test="delete-user-button">Delete account</Button
                >
            </DialogTrigger>
            <DialogContent class="rounded-3xl">
                <Form
                    {...ProfileController.destroy.form()}
                    class="space-y-6"
                    options={{ preserveScroll: true }}
                >
                    {#snippet children({ errors, processing })}
                        <div class="space-y-3">
                            <DialogTitle
                                >Are you sure you want to delete your account?</DialogTitle
                            >
                            <DialogDescription>
                                Your account and households where you are the
                                only member will be deleted. Recipes, meal
                                plans, and shopping lists you created will be
                                removed from shared households. Other members'
                                content and shared ingredients will remain. If
                                you are the last owner, another member will
                                become the owner. Enter your password to
                                confirm.
                            </DialogDescription>
                        </div>

                        <div class="grid gap-2">
                            <Label for="password" class="sr-only"
                                >Password</Label
                            >
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder="Password"
                                class="h-10 rounded-full px-4 shadow-none"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose>
                                <Button
                                    variant="secondary"
                                    class="rounded-full shadow-none"
                                    >Cancel</Button
                                >
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                class="rounded-full"
                                disabled={processing}
                                data-test="confirm-delete-user-button"
                            >
                                Delete account
                            </Button>
                        </DialogFooter>
                    {/snippet}
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</div>
