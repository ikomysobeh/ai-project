import { useState } from 'react';
import ConsoleModal from '@/components/console/modal';
import Pill from '@/components/console/pill';
import ConsoleLayout from '@/layouts/console-layout';
import { api, ApiError } from '@/lib/console-api';
import { useConsoleToast } from '@/lib/console-toast';

type Member = {
    name: string;
    email: string;
    role: string;
    status: 'active' | 'invited';
};

type Props = {
    members: Member[];
};

export default function Team({ members }: Props) {
    const [open, setOpen] = useState(false);

    return (
        <ConsoleLayout
            title="Team & Invites"
            crumb="workspace / team"
            actions={
                <button className="btn primary" onClick={() => setOpen(true)}>
                    + Invite member
                </button>
            }
        >
            <p className="lead">
                Invite people with a signed link. They open it, fill their details, and land in this tenant with the
                role you set — no password sharing, links expire and are single-use.
            </p>

            <div className="section-title">
                <h2>Members</h2>
            </div>
            <div className="card" style={{ padding: '4px 0' }}>
                <table className="tbl">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {members.map((m, i) => (
                            <tr key={i}>
                                <td>
                                    <b>{m.name}</b>
                                </td>
                                <td className="faint">{m.email}</td>
                                <td>
                                    <span className="tag">{m.role}</span>
                                </td>
                                <td>
                                    <Pill kind={m.status === 'active' ? 'active' : 'cooling'}>
                                        {m.status === 'active' ? 'active' : 'invited'}
                                    </Pill>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="section-title" style={{ marginTop: 26 }}>
                <h2>Invite flow</h2>
            </div>
            <div className="flow">{`admin clicks "Invite" → gateway creates a signed, single-use link
   → /invite/{signedToken}
   → person opens it, fills name + password
   → account created inside the tenant with the assigned role
   → link marked used (expires in 7 days)`}</div>

            {open && <InviteModal onClose={() => setOpen(false)} />}
        </ConsoleLayout>
    );
}

function InviteModal({ onClose }: { onClose: () => void }) {
    const toast = useConsoleToast();
    const [email, setEmail] = useState('');
    const [role, setRole] = useState('member');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [link, setLink] = useState<string | null>(null);

    async function submit() {
        setSaving(true);
        setError(null);

        try {
            const result = await api<{ invite: { signed_token: string } }>('/invites', {
                method: 'POST',
                body: JSON.stringify({ email: email || null, role }),
            });
            setLink(`${window.location.origin}/invite/${result.invite.signed_token}`);
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Something went wrong.');
        } finally {
            setSaving(false);
        }
    }

    if (link) {
        return (
            <ConsoleModal open onClose={onClose}>
                <h3>Invite link ready ✓</h3>
                <p className="lead">Send this to the person. It works once, then expires in 7 days.</p>
                <div className="keyrow">
                    <div className="keyval">{link}</div>
                    <button
                        className="btn"
                        onClick={() => {
                            navigator.clipboard?.writeText(link);
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

    return (
        <ConsoleModal open onClose={onClose}>
            <h3>Invite member</h3>
            <p className="lead">Generates a signed, single-use link that expires in 7 days.</p>

            <div className="field">
                <label>Email (optional)</label>
                <input value={email} onChange={(e) => setEmail(e.target.value)} placeholder="teammate@company.com" autoFocus />
            </div>
            <div className="field">
                <label>Role</label>
                <select value={role} onChange={(e) => setRole(e.target.value)}>
                    <option value="member">Member</option>
                    <option value="admin">Admin</option>
                </select>
            </div>

            {error && <p style={{ color: 'var(--coral)', fontSize: 13 }}>{error}</p>}

            <div className="modal-actions">
                <button className="btn ghost" onClick={onClose}>
                    Cancel
                </button>
                <button className="btn primary" onClick={submit} disabled={saving}>
                    Create link
                </button>
            </div>
        </ConsoleModal>
    );
}
