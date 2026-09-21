<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import PrivacyEn from '@/components/public/PrivacyEn.svelte';
    import PrivacyNo from '@/components/public/PrivacyNo.svelte';

    let {
        contact,
    }: { contact: { operatorName: string; supportEmail: string } } = $props();

    const PrivacyContent = $derived(
        page.props.locale === 'no' ? PrivacyNo : PrivacyEn,
    );
</script>

<PrivacyContent {contact} />
