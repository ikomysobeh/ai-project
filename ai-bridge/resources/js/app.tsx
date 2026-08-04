import { createInertiaApp } from '@inertiajs/react';
import ConsoleErrorBoundary from '@/components/console/error-boundary';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { ConsoleToastProvider } from '@/lib/console-toast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            // Console pages wrap themselves in <ConsoleLayout /> directly —
            // it's a completely different shell (own sidebar/topbar) from
            // the starter kit's AppLayout, not a settings-style nested one.
            case name.startsWith('console/') || name === 'dashboard':
                return null;
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <ConsoleErrorBoundary>
                <ConsoleToastProvider>
                    <TooltipProvider delayDuration={0}>
                        {app}
                        <Toaster />
                    </TooltipProvider>
                </ConsoleToastProvider>
            </ConsoleErrorBoundary>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
