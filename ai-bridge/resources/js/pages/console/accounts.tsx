import { router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleModal from '@/components/console/modal';
import Pill from '@/components/console/pill';
import StatCard from '@/components/console/stat-card';
import ConsoleLayout from '@/layouts/console-layout';
import { api, ApiError } from '@/lib/console-api';
import { useConsoleToast } from '@/lib/console-toast';

type Account = {
    id: number;
    label: string;
    status: 'active' | 'cooling_down' | 'expired';
    requests: number;
    last_used_at: string | null;
    last_error: string | null;
};

type Props = {
    accounts: Account[];
    stats: { total: number; active: number; cooling: number; expired: number };
};

const STATUS_PILL = {
    active: 'active',
    cooling_down: 'cooling',
    expired: 'expired',
} as const;

export default function Accounts({ accounts, stats }: Props) {
    const toast = useConsoleToast();
    const [addOpen, setAddOpen] = useState(false);
    const [reauthAccount, setReauthAccount] = useState<Account | null>(null);
    const [busy, setBusy] = useState<number | null>(null);

    async function test(id: number) {
        setBusy(id);

        try {
            const result = await api<{ account: Account }>(`/accounts/${id}/test`, { method: 'POST' });
            router.reload({ only: ['accounts', 'stats'] });
            toast(
                result.account.status === 'active'
                    ? 'Account is healthy ✓'
                    : `Account is ${result.account.status.replace('_', ' ')}${result.account.last_error ? ` — ${result.account.last_error}` : ''}`,
                result.account.status === 'active' ? 'ok' : 'err',
            );
        } catch (e) {
            toast(e instanceof ApiError ? e.message : 'Something went wrong.', 'err');
        } finally {
            setBusy(null);
        }
    }

    return (
        <ConsoleLayout
            title="Gemini Accounts"
            crumb="workspace / rotation-pool"
            actions={
                <button className="btn primary" onClick={() => setAddOpen(true)}>
                    + Add Gemini account
                </button>
            }
        >
            <p className="lead">
                This is the <b>rotation pool</b> — the Gemini logins the gateway cycles through. WebAI-to-API signs in
                with each account's browser cookies (no API key). When one hits a limit or its cookie expires, we
                automatically switch to the next healthy one.
            </p>

            <div className="grid g-4" style={{ margin: '18px 0' }}>
                <StatCard label="Pool size" value={stats.total} delta="accounts" />
                <StatCard label="Live now" value={stats.active} delta="ready to serve" />
                <StatCard label="Cooling" value={stats.cooling} delta="rate-limited" />
                <StatCard label="Expired" value={stats.expired} delta="need re-auth" />
            </div>

            <div className="section-title">
                <h2>Accounts</h2>
            </div>
            <div className="card" style={{ padding: '4px 0' }}>
                <table className="tbl">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Requests served</th>
                            <th>Last used</th>
                            <th>State</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {accounts.length === 0 && (
                            <tr>
                                <td colSpan={5} className="faint">
                                    No accounts yet — paste your Gemini cookies to add one.
                                </td>
                            </tr>
                        )}
                        {accounts.map((a) => (
                            <tr key={a.id}>
                                <td className="mono">
                                    <b>{a.label}</b>
                                </td>
                                <td className="mono">{a.requests.toLocaleString()}</td>
                                <td className="faint">{a.last_used_at ?? 'idle'}</td>
                                <td>
                                    <Pill kind={STATUS_PILL[a.status]} title={a.last_error ?? undefined} />
                                </td>
                                <td style={{ textAlign: 'right' }}>
                                    {a.status === 'expired' ? (
                                        <button className="btn sm" onClick={() => setReauthAccount(a)}>
                                            Re-authenticate
                                        </button>
                                    ) : (
                                        <button className="btn ghost sm" onClick={() => test(a.id)} disabled={busy === a.id}>
                                            Test
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="section-title" style={{ marginTop: 28 }}>
                <h2>How rotation works</h2>
            </div>
            <div className="flow">{`request arrives
   │
   ▼
pick account WHERE status = active   ORDER BY last_used ASC   (least recently used)
   │
   ├─▶ success ────────────────▶ log usage, return answer
   │
   ├─▶ 429 / rate limit ───────▶ mark cooling_down, try next account
   │
   └─▶ AuthError / expired ────▶ mark expired, try next account
                                   │
                                   └─ all exhausted ─▶ graceful error to caller`}</div>

            {addOpen && <AddAccountModal onClose={() => setAddOpen(false)} />}
            {reauthAccount && <ReauthModal account={reauthAccount} onClose={() => setReauthAccount(null)} />}
        </ConsoleLayout>
    );
}

function AddAccountModal({ onClose }: { onClose: () => void }) {
    const toast = useConsoleToast();
    const [label, setLabel] = useState('');
    const [psid, setPsid] = useState('');
    const [psidts, setPsidts] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function submit() {
        setSaving(true);
        setError(null);

        try {
            const result = await api<{ account: { status: string; last_error: string | null } }>('/accounts', {
                method: 'POST',
                body: JSON.stringify({ label, secure_1psid: psid, secure_1psidts: psidts }),
            });
            onClose();
            router.reload({ only: ['accounts', 'stats'] });
            toast(
                result.account.status === 'active'
                    ? 'Account added and verified ✓'
                    : `Account added, but the cookies didn't validate${result.account.last_error ? ` — ${result.account.last_error}` : ' — check them and re-authenticate.'}`,
                result.account.status === 'active' ? 'ok' : 'err',
            );
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Something went wrong.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <ConsoleModal open onClose={onClose}>
            <h3>Add Gemini account to pool</h3>
            <p className="lead">
                The gateway uses this account's browser cookies to talk to Gemini. Cookies are encrypted at rest and
                never logged.
            </p>

            <div className="field">
                <label>Label</label>
                <input value={label} onChange={(e) => setLabel(e.target.value)} placeholder="gemini-acct-05" autoFocus />
            </div>
            <div className="field">
                <label>__Secure-1PSID</label>
                <input value={psid} onChange={(e) => setPsid(e.target.value)} placeholder="Paste from browser DevTools" />
            </div>
            <div className="field">
                <label>__Secure-1PSIDTS</label>
                <input value={psidts} onChange={(e) => setPsidts(e.target.value)} placeholder="Paste from browser DevTools" />
            </div>
            <div className="note">
                Sign in to Gemini in your browser, open DevTools → Application → Cookies →{' '}
                <code>gemini.google.com</code>, and copy these two cookie values.
            </div>

            {error && (
                <p style={{ color: 'var(--coral)', fontSize: 13, marginTop: 12 }}>{error}</p>
            )}

            <div className="modal-actions">
                <button className="btn ghost" onClick={onClose}>
                    Cancel
                </button>
                <button className="btn primary" onClick={submit} disabled={saving || !label || !psid || !psidts}>
                    Add to pool
                </button>
            </div>
        </ConsoleModal>
    );
}

function ReauthModal({ account, onClose }: { account: Account; onClose: () => void }) {
    const toast = useConsoleToast();
    const [psid, setPsid] = useState('');
    const [psidts, setPsidts] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function submit() {
        setSaving(true);
        setError(null);

        try {
            const result = await api<{ account: { status: string; last_error: string | null } }>(`/accounts/${account.id}/reauth`, {
                method: 'POST',
                body: JSON.stringify({ secure_1psid: psid, secure_1psidts: psidts }),
            });
            onClose();
            router.reload({ only: ['accounts', 'stats'] });
            toast(
                result.account.status === 'active'
                    ? 'Re-authenticated — back in rotation ✓'
                    : `Those cookies didn't validate either${result.account.last_error ? ` — ${result.account.last_error}` : '.'}`,
                result.account.status === 'active' ? 'ok' : 'err',
            );
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Something went wrong.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <ConsoleModal open onClose={onClose}>
            <h3>Re-authenticate {account.label}</h3>
            <p className="lead">Paste fresh cookies to bring this account back into rotation.</p>

            <div className="field">
                <label>__Secure-1PSID</label>
                <input value={psid} onChange={(e) => setPsid(e.target.value)} autoFocus />
            </div>
            <div className="field">
                <label>__Secure-1PSIDTS</label>
                <input value={psidts} onChange={(e) => setPsidts(e.target.value)} />
            </div>

            {error && (
                <p style={{ color: 'var(--coral)', fontSize: 13, marginTop: 12 }}>{error}</p>
            )}

            <div className="modal-actions">
                <button className="btn ghost" onClick={onClose}>
                    Cancel
                </button>
                <button className="btn primary" onClick={submit} disabled={saving || !psid || !psidts}>
                    Re-authenticate
                </button>
            </div>
        </ConsoleModal>
    );
}
