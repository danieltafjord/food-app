import { createInertiaApp } from '@inertiajs/svelte';
import AuthLayout from '@/layouts/AuthLayout.svelte';
import PublicLayout from '@/layouts/PublicLayout.svelte';
import { initializeFlashToast } from '@/lib/flash-toast';
import { initializeTheme } from '@/lib/theme.svelte';

const appName = import.meta.env.VITE_APP_NAME || 'Handlelista';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Signed-in pages declare `layout = [AppLayout, { breadcrumbs }]` themselves,
    // so the app shell (sidebar, menus, admin routes) ships with them rather
    // than with the public and sign-in pages the mobile app opens.
    layout: (name) => {
        if (name === 'Welcome' || name === 'Privacy' || name === 'Support') {
            return PublicLayout;
        }

        return name.startsWith('auth/') ? AuthLayout : undefined;
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
