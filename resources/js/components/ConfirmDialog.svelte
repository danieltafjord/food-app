<script lang="ts">
    /**
     * A styled replacement for window.confirm(). Pass `challenge` to require
     * the user to type a value (for example an e-mail address) before the
     * destructive button becomes enabled.
     */
    import type { Snippet } from 'svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import {
        Dialog,
        DialogContent,
        DialogDescription,
        DialogFooter,
        DialogTitle,
    } from '@/components/ui/dialog';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';

    let {
        open = $bindable(false),
        title,
        description = '',
        confirmLabel = 'Confirm',
        cancelLabel = 'Cancel',
        destructive = false,
        processing = false,
        challenge,
        challengeLabel = '',
        error = '',
        onConfirm,
        onOpenChange,
        children,
    }: {
        open?: boolean;
        title: string;
        description?: string;
        confirmLabel?: string;
        cancelLabel?: string;
        destructive?: boolean;
        processing?: boolean;
        /** The exact text the user has to type before confirming. */
        challenge?: string;
        challengeLabel?: string;
        error?: string;
        onConfirm: (typed: string) => void;
        /** Called when the dialog closes itself, so the parent can clear its state. */
        onOpenChange?: (value: boolean) => void;
        children?: Snippet;
    } = $props();

    function close() {
        open = false;
        onOpenChange?.(false);
    }

    let typed = $state('');

    $effect(() => {
        if (!open) {
            typed = '';
        }
    });

    const matches = $derived(
        challenge === undefined ||
            typed.trim().toLowerCase() === challenge.trim().toLowerCase(),
    );

    function submit(event: SubmitEvent) {
        event.preventDefault();

        if (!matches || processing) {
            return;
        }

        onConfirm(typed.trim());
    }
</script>

<Dialog
    bind:open
    onOpenChange={(value) => {
        if (!value) {
            onOpenChange?.(false);
        }
    }}
>
    <DialogContent class="rounded-3xl sm:max-w-md">
        <form onsubmit={submit} class="space-y-5">
            <div class="space-y-2">
                <DialogTitle>{title}</DialogTitle>
                {#if description}
                    <DialogDescription>{description}</DialogDescription>
                {/if}
                {@render children?.()}
            </div>

            {#if challenge !== undefined}
                <div class="grid gap-2">
                    <Label for="confirm-challenge" class="text-sm">
                        {challengeLabel || `Type “${challenge}” to confirm`}
                    </Label>
                    <Input
                        id="confirm-challenge"
                        class="h-10 rounded-full px-4 shadow-none"
                        bind:value={typed}
                        autocomplete="off"
                        spellcheck={false}
                        placeholder={challenge}
                    />
                    <InputError message={error} />
                </div>
            {:else}
                <InputError message={error} />
            {/if}

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    class="rounded-full shadow-none"
                    onclick={close}
                >
                    {cancelLabel}
                </Button>
                <Button
                    type="submit"
                    variant={destructive ? 'destructive' : 'default'}
                    class="rounded-full"
                    disabled={!matches || processing}
                >
                    {confirmLabel}
                </Button>
            </DialogFooter>
        </form>
    </DialogContent>
</Dialog>
