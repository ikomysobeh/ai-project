import { router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleModal from '@/components/console/modal';
import Pill from '@/components/console/pill';
import ConsoleLayout from '@/layouts/console-layout';
import { api, ApiError } from '@/lib/console-api';
import { useConsoleToast } from '@/lib/console-toast';

type Token = {
    id: number;
    name: string;
    prefix: string;
    app: string | null;
    model: string | null;
    used_today: number;
    daily_quota: number;
    last_used_at: string | null;
    revoked: boolean;
    expires_at: string | null;
    expired: boolean;
    revealable: boolean;
};

type Props = {
    apps: { id: number; name: string }[];
    tokens: Token[];
};

export default function Tokens({ apps, tokens }: Props) {
    const toast = useConsoleToast();
    const [open, setOpen] = useState(false);
    const [revoking, setRevoking] = useState<number | null>(null);
    const [viewing, setViewing] = useState<number | null>(null);
    const [viewToken, setViewToken] = useState<{ name: string; raw: string } | null>(null);

    async function revoke(id: number) {
        setRevoking(id);

        try {
            await api(`/tokens/${id}`, { method: 'DELETE' });
            router.reload({ only: ['tokens'] });
            toast('Token revoked — effective immediately');
        } catch (e) {
            toast(e instanceof ApiError ? e.message : 'Something went wrong.', 'err');
        } finally {
            setRevoking(null);
        }
    }

    async function view(token: Token) {
        setViewing(token.id);

        try {
            const result = await api<{ raw_token: string }>(`/tokens/${token.id}/reveal`, { method: 'POST' });
            setViewToken({ name: token.name, raw: result.raw_token });
        } catch (e) {
            toast(e instanceof ApiError ? e.message : 'Something went wrong.', 'err');
        } finally {
            setViewing(null);
        }
    }

    return (
        <ConsoleLayout
            title="API Tokens"
            crumb="workspace / tokens"
            actions={
                <button className="btn primary" onClick={() => setOpen(true)} disabled={apps.length === 0}>
                    + Generate token
                </button>
            }
        >
            <p className="lead">
                Tokens are what your external code sends as <code>Authorization: Bearer …</code>. The full value is
                encrypted at rest — you can view it again anytime with <b>View</b>. Revoking is instant.
            </p>

            <div className="section-title">
                <h2>API tokens</h2>
            </div>

            <div className="card" style={{ padding: '4px 0' }}>
                <table className="tbl">
                    <thead>
                        <tr>
                            <th>Token</th>
                            <th>App</th>
                            <th>Model</th>
                            <th>Daily quota</th>
                            <th>Last used</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {tokens.length === 0 && (
                            <tr>
                                <td colSpan={8} className="faint">
                                    {apps.length === 0
                                        ? 'Create an app first, then generate a token for it.'
                                        : 'No tokens yet.'}
                                </td>
                            </tr>
                        )}
                        {tokens.map((token) => (
                            <tr key={token.id}>
                                <td className="mono" style={{ color: 'var(--cyan)' }}>
                                    {token.prefix}…
                                </td>
                                <td>{token.app}</td>
                                <td>
                                    <span className="tag">{token.model}</span>
                                </td>
                                <td className="mono">
                                    {token.used_today.toLocaleString()} / {token.daily_quota.toLocaleString()}
                                </td>
                                <td className="faint">{token.last_used_at ?? '—'}</td>
                                <td className={token.expired ? undefined : 'faint'} style={token.expired ? { color: 'var(--coral)' } : undefined}>
                                    {token.expires_at
                                        ? new Date(token.expires_at).toLocaleDateString(undefined, {
                                              year: 'numeric',
                                              month: 'short',
                                              day: 'numeric',
                                          })
                                        : 'Never'}
                                </td>
                                <td>
                                    {token.revoked ? (
                                        <Pill kind="muted">revoked</Pill>
                                    ) : token.expired ? (
                                        <Pill kind="expired" />
                                    ) : (
                                        <Pill kind="active" />
                                    )}
                                </td>
                                <td style={{ textAlign: 'right' }}>
                                    <div style={{ display: 'inline-flex', gap: 8 }}>
                                        {token.revealable && (
                                            <button
                                                className="btn ghost sm"
                                                onClick={() => view(token)}
                                                disabled={viewing === token.id}
                                                title="View the full token value"
                                            >
                                                {viewing === token.id ? 'Loading…' : 'View'}
                                            </button>
                                        )}
                                        {!token.revoked && (
                                            <button
                                                className="btn danger sm"
                                                onClick={() => revoke(token.id)}
                                                disabled={revoking === token.id}
                                            >
                                                Revoke
                                            </button>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="note">
                <b>Usage limits, not billing.</b> <code>Daily quota</code> is a request-count cap so one token can't
                drain the whole account pool. It's checked in Redis <b>before</b> the request reaches Gemini.
            </div>

            {open && <NewTokenModal apps={apps} onClose={() => setOpen(false)} />}
            {viewToken && <ViewTokenModal token={viewToken} onClose={() => setViewToken(null)} />}
        </ConsoleLayout>
    );
}

function ViewTokenModal({ token, onClose }: { token: { name: string; raw: string }; onClose: () => void }) {
    const toast = useConsoleToast();

    return (
        <ConsoleModal open onClose={onClose}>
            <h3>{token.name}</h3>
            <p className="lead">This is the same token you copied at creation.</p>
            <div className="keyrow">
                <div className="keyval">{token.raw}</div>
                <button
                    className="btn"
                    onClick={() => {
                        navigator.clipboard?.writeText(token.raw);
                        toast('Copied ✓');
                    }}
                >
                    Copy
                </button>
            </div>
            <div className="modal-actions">
                <button className="btn primary" onClick={onClose}>
                    Done
                </button>
            </div>
        </ConsoleModal>
    );
}

const EXPIRY_PRESETS = [
    { value: 'never', label: 'Never' },
    { value: '30', label: '30 days' },
    { value: '90', label: '90 days' },
    { value: '365', label: '1 year' },
    { value: 'custom', label: 'Custom date…' },
] as const;

function presetToIsoDate(days: number): string {
    const d = new Date(Date.now() + days * 24 * 60 * 60 * 1000);
    return d.toISOString().slice(0, 10);
}

function NewTokenModal({ apps, onClose }: { apps: { id: number; name: string }[]; onClose: () => void }) {
    const toast = useConsoleToast();
    const [appId, setAppId] = useState(apps[0]?.id ?? '');
    const [name, setName] = useState('');
    const [quota, setQuota] = useState('1000');
    const [expiryPreset, setExpiryPreset] = useState<(typeof EXPIRY_PRESETS)[number]['value']>('never');
    const [customDate, setCustomDate] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [rawToken, setRawToken] = useState<string | null>(null);

    function resolveExpiresAt(): string | null {
        if (expiryPreset === 'never') return null;
        if (expiryPreset === 'custom') return customDate || null;
        return presetToIsoDate(Number(expiryPreset));
    }

    async function submit() {
        setSaving(true);
        setError(null);

        try {
            const result = await api<{ raw_token: string }>(`/apps/${appId}/tokens`, {
                method: 'POST',
                body: JSON.stringify({ name, daily_quota: Number(quota), expires_at: resolveExpiresAt() }),
            });
            setRawToken(result.raw_token);
            router.reload({ only: ['tokens'] });
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Something went wrong.');
        } finally {
            setSaving(false);
        }
    }

    if (rawToken) {
        return (
            <ConsoleModal open onClose={onClose}>
                <h3>Token created ✓</h3>
                <p className="lead">Copy it now — or come back and hit "View" on this token anytime.</p>
                <div className="keyrow">
                    <div className="keyval">{rawToken}</div>
                    <button
                        className="btn"
                        onClick={() => {
                            navigator.clipboard?.writeText(rawToken);
                            toast('Copied ✓');
                        }}
                    >
                        Copy
                    </button>
                </div>
                <div className="note" style={{ marginTop: 16 }}>
                    Stored encrypted at rest — the display prefix (<code>{rawToken.slice(0, 13)}…</code>) is what
                    shows up in the tokens list, but the full value can be revealed again from there.
                </div>
                <div className="modal-actions">
                    <button className="btn primary" onClick={onClose}>
                        Done
                    </button>
                </div>
            </ConsoleModal>
        );
    }

    return (
        <ConsoleModal open onClose={onClose}>
            <h3>Generate API token</h3>
            <p className="lead">You'll be able to view or copy this token again anytime after creating it.</p>

            <div className="field">
                <label>App</label>
                <select value={appId} onChange={(e) => setAppId(Number(e.target.value))}>
                    {apps.map((app) => (
                        <option key={app.id} value={app.id}>
                            {app.name}
                        </option>
                    ))}
                </select>
            </div>

            <div className="field">
                <label>Token name</label>
                <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. production key" autoFocus />
            </div>

            <div className="field">
                <label>Daily quota (requests)</label>
                <input value={quota} onChange={(e) => setQuota(e.target.value)} />
            </div>

            <div className="field">
                <label>Expires</label>
                <select
                    value={expiryPreset}
                    onChange={(e) => setExpiryPreset(e.target.value as typeof expiryPreset)}
                >
                    {EXPIRY_PRESETS.map((p) => (
                        <option key={p.value} value={p.value}>
                            {p.label}
                        </option>
                    ))}
                </select>
            </div>

            {expiryPreset === 'custom' && (
                <div className="field">
                    <label>Expiration date</label>
                    <input
                        type="date"
                        value={customDate}
                        min={presetToIsoDate(1)}
                        onChange={(e) => setCustomDate(e.target.value)}
                    />
                </div>
            )}

            {error && <p style={{ color: 'var(--coral)', fontSize: 13 }}>{error}</p>}

            <div className="modal-actions">
                <button className="btn ghost" onClick={onClose}>
                    Cancel
                </button>
                <button
                    className="btn primary"
                    onClick={submit}
                    disabled={saving || !name || (expiryPreset === 'custom' && !customDate)}
                >
                    Generate
                </button>
            </div>
        </ConsoleModal>
    );
}
