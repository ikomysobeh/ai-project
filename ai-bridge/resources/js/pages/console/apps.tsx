import { router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleModal from '@/components/console/modal';
import ConsoleLayout from '@/layouts/console-layout';
import { api, ApiError } from '@/lib/console-api';
import { useConsoleToast } from '@/lib/console-toast';

type App = {
    id: number;
    name: string;
    default_model: string;
    knowledge_base: { id: number; name: string } | null;
    tokens_count: number;
    requests: number;
};

type Props = {
    apps: App[];
    knowledgeBases: { id: number; name: string }[];
    models: string[];
};

export default function Apps({ apps, knowledgeBases, models }: Props) {
    const [open, setOpen] = useState(false);

    return (
        <ConsoleLayout
            title="Apps"
            crumb="workspace / apps"
            actions={
                <button className="btn primary" onClick={() => setOpen(true)}>
                    + New App
                </button>
            }
        >
            <p className="lead">
                An app is a logical container. It picks a default model, can attach a knowledge base for RAG, and
                holds the tokens your code actually uses.
            </p>

            <div className="section-title">
                <h2>Your apps</h2>
            </div>

            <div className="card" style={{ padding: '4px 0' }}>
                <table className="tbl">
                    <thead>
                        <tr>
                            <th>App</th>
                            <th>Default model</th>
                            <th>Knowledge base</th>
                            <th>Tokens</th>
                            <th>Requests</th>
                        </tr>
                    </thead>
                    <tbody>
                        {apps.length === 0 && (
                            <tr>
                                <td colSpan={5} className="faint">
                                    No apps yet — create one to get started.
                                </td>
                            </tr>
                        )}
                        {apps.map((app) => (
                            <tr key={app.id}>
                                <td>
                                    <b>{app.name}</b>
                                    <div className="mono faint" style={{ fontSize: 11 }}>
                                        app_{app.id}
                                    </div>
                                </td>
                                <td>
                                    <span className="tag">{app.default_model}</span>
                                </td>
                                <td>
                                    {app.knowledge_base ? (
                                        <span className="pill rag">{app.knowledge_base.name}</span>
                                    ) : (
                                        <span className="faint">—</span>
                                    )}
                                </td>
                                <td className="mono">{app.tokens_count}</td>
                                <td className="mono">{app.requests.toLocaleString()}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {open && <NewAppModal models={models} knowledgeBases={knowledgeBases} onClose={() => setOpen(false)} />}
        </ConsoleLayout>
    );
}

function NewAppModal({
    models,
    knowledgeBases,
    onClose,
}: {
    models: string[];
    knowledgeBases: { id: number; name: string }[];
    onClose: () => void;
}) {
    const toast = useConsoleToast();
    const [name, setName] = useState('');
    const [model, setModel] = useState(models[0] ?? '');
    const [kbId, setKbId] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function submit() {
        setSaving(true);
        setError(null);

        try {
            await api('/apps', {
                method: 'POST',
                body: JSON.stringify({
                    name,
                    default_model: model,
                    knowledge_base_id: kbId ? Number(kbId) : null,
                }),
            });
            onClose();
            router.reload({ only: ['apps'] });
            toast('App created ✓');
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Something went wrong.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <ConsoleModal open onClose={onClose}>
            <h3>Create app</h3>
            <p className="lead">An app groups tokens and picks a default model.</p>

            <div className="field">
                <label>App name</label>
                <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Support Bot" autoFocus />
            </div>

            <div className="field">
                <label>Default model</label>
                <select value={model} onChange={(e) => setModel(e.target.value)}>
                    {models.map((m) => (
                        <option key={m} value={m}>
                            {m}
                        </option>
                    ))}
                </select>
            </div>

            <div className="field">
                <label>Attach knowledge base (optional)</label>
                <select value={kbId} onChange={(e) => setKbId(e.target.value)}>
                    <option value="">None</option>
                    {knowledgeBases.map((kb) => (
                        <option key={kb.id} value={kb.id}>
                            {kb.name}
                        </option>
                    ))}
                </select>
            </div>

            {error && <p style={{ color: 'var(--coral)', fontSize: 13 }}>{error}</p>}

            <div className="modal-actions">
                <button className="btn ghost" onClick={onClose}>
                    Cancel
                </button>
                <button className="btn primary" onClick={submit} disabled={saving || !name}>
                    Create app
                </button>
            </div>
        </ConsoleModal>
    );
}
