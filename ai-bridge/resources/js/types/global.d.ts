import type { Auth, NavCounts, Tenant } from '@/types/auth';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            tenant: Tenant | null;
            navCounts: NavCounts | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
