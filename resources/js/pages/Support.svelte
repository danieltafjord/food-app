<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import SupportEn from '@/components/public/SupportEn.svelte';
    import SupportNo from '@/components/public/SupportNo.svelte';

    let {
        contact,
    }: { contact: { operatorName: string; supportEmail: string } } = $props();

    const SupportContent = $derived(
        page.props.locale === 'no' ? SupportNo : SupportEn,
    );
</script>

<SupportContent {contact} />
