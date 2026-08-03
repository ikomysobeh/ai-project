import { useState } from 'react';
import ConsoleLayout from '@/layouts/console-layout';
import { api, ApiError } from '@/lib/console-api';

type App = {
    id: number;
    name: string;
    default_model: string;
    rag_ready: boolean;
    knowledge_base_name: string | null;
};

type Props = {
    apps: App[];
    models: string[];
};

export default function Playground({ apps, models }: Props) {
    const [appId, setAppId] = useState(apps[0]?.id ?? '');
    const [model, setModel] = useState(apps[0]?.default_model ?? models[0] ?? '');
    const [prompt, setPrompt] = useState('How do I reset a customer\'s loyalty points?');
    const [sending, setSending] = useState(false);
    const [output, setOutput] = useState<React.ReactNode>(
        <span className="faint">Response will appear here…</span>,
    );

    const selectedApp = apps.find((a) => a.id === appId);

    async function send() {
        if (!selectedApp) {
return;
}

        setSending(true);
        setOutput(<span className="faint">Sending to the real gateway…</span>);

        try {
            const result = await api<Record<string, unknown>>(`/playground/${selectedApp.id}/send`, {
                method: 'POST',
                body: JSON.stringify({
                    model,
                    messages: [{ role: 'user', content: prompt }],
                }),
            });

            const text =
                (result?.choices as Array<{ message?: { content?: string } }> | undefined)?.[0]?.message?.content ??
                JSON.stringify(result, null, 2);

            setOutput(
                <>
                    <b style={{ color: 'var(--green)' }}>✓ 200 OK</b>
                    <br />
                    <br />
                    {text}
                </>,
            );
        } catch (e) {
            const message = e instanceof ApiError ? e.message : 'Something went wrong.';
            setOutput(
                <>
                    <b style={{ color: 'var(--coral)' }}>✗ {e instanceof ApiError ? e.status : 'Error'}</b>
                    <br />
                    <br />
                    {message}
                </>,
            );
        } finally {
            setSending(false);
        }
    }

    return (
        <ConsoleLayout title="Playground" crumb="workspace / playground">
            <p className="lead">
                Send a real request exactly like your external code would — this calls the actual gateway (account
                rotation, RAG, usage logging included), just authenticated by your dashboard session instead of a
                token.
            </p>

            <div className="row" style={{ marginTop: 18 }}>
                <select
                    className="inline"
                    value={appId}
                    onChange={(e) => {
                        const id = Number(e.target.value);
                        setAppId(id);
                        setModel(apps.find((a) => a.id === id)?.default_model ?? model);
                    }}
                >
                    {apps.length === 0 && <option>No apps yet</option>}
                    {apps.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.name}
                        </option>
                    ))}
                </select>
                <select className="inline" value={model} onChange={(e) => setModel(e.target.value)}>
                    {models.map((m) => (
                        <option key={m} value={m}>
                            {m}
                        </option>
                    ))}
                </select>
                {selectedApp && (
                    <span className={`pill ${selectedApp.rag_ready ? 'rag' : 'muted'}`}>
                        {selectedApp.rag_ready ? `RAG · ${selectedApp.knowledge_base_name}` : 'no knowledge base attached'}
                    </span>
                )}
            </div>

            <div className="pg">
                <div>
                    <div className="faint" style={{ fontSize: 11, marginBottom: 6 }}>
                        REQUEST
                    </div>
                    <textarea value={prompt} onChange={(e) => setPrompt(e.target.value)} />
                    <button
                        className="btn primary"
                        style={{ marginTop: 12, width: '100%' }}
                        onClick={send}
                        disabled={sending || !selectedApp || !prompt}
                    >
                        ▶ Send request
                    </button>
                </div>
                <div>
                    <div className="faint" style={{ fontSize: 11, marginBottom: 6 }}>
                        RESPONSE
                    </div>
                    <div className="out">{output}</div>
                </div>
            </div>

            <div className="section-title" style={{ marginTop: 26 }}>
                <h2>Equivalent code</h2>
            </div>
            <div className="flow">{`curl ${window.location.origin}/v1/chat/completions \\
  -H "Authorization: Bearer <your generated token>" \\
  -H "Content-Type: application/json" \\
  -d '{
    "model": "${model}",
    "messages": [{"role":"user","content":"${prompt.replace(/"/g, '\\"')}"}]
  }'`}</div>
        </ConsoleLayout>
    );
}
