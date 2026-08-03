export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    tenant_id: number;
    role: 'owner' | 'admin' | 'member';
    status: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type Tenant = {
    id: number;
    name: string;
    slug: string;
    status: string;
};

export type NavCounts = {
    apps: number;
    tokens: number;
    accounts: number;
    knowledgeBases: number;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
