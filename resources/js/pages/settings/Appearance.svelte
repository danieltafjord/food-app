<script module lang="ts">
    import AppLayout from '@/layouts/AppLayout.svelte';
    import SettingsLayout from '@/layouts/settings/Layout.svelte';
    import { edit as editAppearance } from '@/routes/appearance';

    export const layout = [
        [
            AppLayout,
            {
                breadcrumbs: [
                    {
                        title: 'Appearance settings',
                        href: editAppearance(),
                    },
                ],
            },
        ],
        SettingsLayout,
    ];
</script>

<script lang="ts">
    import AppearanceTabs from '@/components/AppearanceTabs.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
</script>

<AppHead title="Appearance settings" />

<h1 class="sr-only">Appearance settings</h1>

<div class="space-y-6">
    <Heading
        variant="small"
        title="Appearance settings"
        description="Update the appearance settings for your account"
    />
    <AppearanceTabs />
</div>
