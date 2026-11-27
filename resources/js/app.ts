import { createInertiaApp } from '@inertiajs/svelte';
import AppLayout from '@/layouts/AppLayout.svelte';
import AuthLayout from '@/layouts/AuthLayout.svelte';
import EmployeeLayout from '@/layouts/EmployeeLayout.svelte';
import SettingsLayout from '@/layouts/settings/Layout.svelte';
import { initializeFlashToast } from '@/lib/flash-toast';
import { initializeTheme } from '@/lib/theme.svelte';

const appName = import.meta.env.VITE_APP_NAME || 'Ladang Lima';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name, page) => {
        const role = (
            page?.props?.auth as { user?: { role?: string } } | undefined
        )?.user?.role;
        const isHrAdmin = role === 'HR_ADMIN';

        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return isHrAdmin ? [AppLayout, SettingsLayout] : EmployeeLayout;
            case name.startsWith('office/'):
            case name.startsWith('sales/'):
            case name === 'admin/AbsenSayaMobile':
            case name === 'admin/PengajuanSayaMobile':
                return EmployeeLayout;
            case name === 'admin/AbsenSaya':
            case name === 'admin/PengajuanSaya':
            case name === 'admin/RiwayatSaya':
                return AppLayout;
            case name === 'admin/Profil':
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
