import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleModal from '@/components/console/modal';
import Pill from '@/components/console/pill';
import ConsoleLayout from '@/layouts/console-layout';
import { api, ApiError } from '@/lib/console-api';
import { useConsoleToast } from '@/lib/console-toast';

type KnowledgeBase = {
    id: number;
    name: string;
    status: 'empty' | 'indexing' | 'ready' | 'failed';
    embedding_model: string;
    documents_count: number;
    chunks_count: number;
    attached_app: string | null;
};

type Props = {
    knowledgeBases: KnowledgeBase[];
};

const KB_STATUS: Record<KnowledgeBase['status'], { kind: 'ready' | 'expired' | 'cooling' | 'muted'; label: string }> = {
    ready: { kind: 'ready', label: 'ready' },
    failed: { kind: 'expired', label: 'failed' },
    indexing: { kind: 'cooling', label: 'indexing…' },
    empty: { kind: 'muted', label: 'no documents yet' },
};

export default function Knowledge({ knowledgeBases }: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [docsKb, setDocsKb] = useState<KnowledgeBase | null>(null);
    const [uploadKb, setUploadKb] = useState<KnowledgeBase | null>(null);

    return (
        <ConsoleLayout
            title="Knowledge (RAG)"
            crumb="workspace / knowledge"
            actions={
                <button className="btn primary" onClick={() => setCreateOpen(true)}>
                    + New knowledge base
                </button>
            }
        >
            <p className="lead">
                Knowledge bases let an app answer from <b>your documents</b>. Upload files, we chunk and embed them,
                and at query time we retrieve the most relevant pieces and add them to the prompt before it reaches
                Gemini.
            </p>

            <div className="section-title">
                <h2>Knowledge bases</h2>
            </div>

            <div className="grid g-2">
                {knowledgeBases.length === 0 && <p className="faint">No knowledge bases yet.</p>}
                {knowledgeBases.map((kb) => (
                    <div className="card" key={kb.id}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                            <b style={{ fontSize: 15 }}>{kb.name}</b>
                            <Pill kind={KB_STATUS[kb.status].kind}>{KB_STATUS[kb.status].label}</Pill>
                        </div>
                        <div className="faint mono" style={{ fontSize: 11, margin: '4px 0 14px' }}>
                            kb_{kb.id} {kb.attached_app ? `· attached to ${kb.attached_app}` : '· not attached to an app'}
                        </div>
                        <div className="grid g-3" style={{ gap: 10 }}>
                            <div>
                                <div className="faint" style={{ fontSize: 11 }}>
                                    Documents
                                </div>
                                <div className="mono" style={{ fontSize: 18 }}>
                                    {kb.documents_count}
                                </div>
                            </div>
                            <div>
                                <div className="faint" style={{ fontSize: 11 }}>
                                    Chunks
                                </div>
                                <div className="mono" style={{ fontSize: 18 }}>
                                    {kb.chunks_count}
                                </div>
                            </div>
                            <div>
                                <div className="faint" style={{ fontSize: 11 }}>
                                    Embedder
                                </div>
                                <span className="tag" style={{ marginTop: 4, display: 'inline-block' }}>
                                    {kb.embedding_model}
                                </span>
                            </div>
                        </div>
                        <div style={{ marginTop: 14, display: 'flex', gap: 8 }}>
                            <button className="btn sm" onClick={() => setDocsKb(kb)}>
                                View docs
                            </button>
                            <button className="btn ghost sm" onClick={() => setUploadKb(kb)}>
                                Upload
                            </button>
                        </div>
                    </div>
                ))}
            </div>

            <div className="section-title" style={{ marginTop: 28 }}>
                <h2>The RAG pipeline</h2>
                <span className="hint">what happens under the hood</span>
            </div>
            <div className="grid g-2">
                <div className="card">
                    <div className="pill rag" style={{ marginBottom: 12 }}>
                        ingestion · runs in background
                    </div>
                    <div className="flow" style={{ border: 0, padding: 0 }}>{`upload doc
  → extract text
  → chunk (~800 tokens, overlap)
  → embed each chunk  (Ollama, CPU)
  → store vector in pgvector`}</div>
                </div>
                <div className="card">
                    <div className="pill rag" style={{ marginBottom: 12 }}>
                        retrieval · at request time
                    </div>
                    <div className="flow" style={{ border: 0, padding: 0 }}>{`user question
  → embed the question
  → vector search top-K
     (filtered by tenant + kb)
  → inject chunks into prompt
  → Gemini answers, grounded`}</div>
                </div>
            </div>
            <div className="note">
                <b>One rule that matters:</b> the embedder used to ingest must match the one used to query, and every
                vector search is filtered by <code>tenant_id</code> — otherwise one tenant could retrieve another's
                documents.
            </div>

            {createOpen && <NewKbModal onClose={() => setCreateOpen(false)} />}
            {docsKb && <DocumentsModal kb={docsKb} onClose={() => setDocsKb(null)} />}
            {uploadKb && <UploadModal kb={uploadKb} onClose={() => setUploadKb(null)} />}
        </ConsoleLayout>
    );
}

function NewKbModal({ onClose }: { onClose: () => void }) {
    const toast = useConsoleToast();
    const [name, setName] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function submit() {
        setSaving(true);
        setError(null);

        try {
            await api('/knowledge-bases', { method: 'POST', body: JSON.stringify({ name }) });
            onClose();
            router.reload({ only: ['knowledgeBases'] });
            toast('Knowledge base created ✓');
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Something went wrong.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <ConsoleModal open onClose={onClose}>
            <h3>New knowledge base</h3>
            <p className="lead">A container for documents the model can retrieve from.</p>
            <div className="field">
                <label>Name</label>
                <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Product Manuals" autoFocus />
            </div>
            <div className="note">
                Embeddings use <code>nomic-embed-text</code> (Ollama, local) — locked for every knowledge base in this
                MVP, so ingest and query always match.
            </div>
            {error && <p style={{ color: 'var(--coral)', fontSize: 13, marginTop: 12 }}>{error}</p>}
            <div className="modal-actions" style={{ marginTop: 16 }}>
                <button className="btn ghost" onClick={onClose}>
                    Cancel
                </button>
                <button className="btn primary" onClick={submit} disabled={saving || !name}>
                    Create
                </button>
            </div>
        </ConsoleModal>
    );
}

type Document = { id: number; source_name: string; source_type: string; status: string; created_at: string };

function DocumentsModal({ kb, onClose }: { kb: KnowledgeBase; onClose: () => void }) {
    const toast = useConsoleToast();
    const [docs, setDocs] = useState<Document[] | null>(null);

    useEffect(() => {
        api<{ documents: Document[] }>(`/knowledge-bases/${kb.id}/documents`).then((r) => setDocs(r.documents));
    }, [kb.id]);

    async function remove(id: number) {
        try {
            await api(`/documents/${id}`, { method: 'DELETE' });
            setDocs((d) => d?.filter((doc) => doc.id !== id) ?? null);
            router.reload({ only: ['knowledgeBases'] });
            toast('Document deleted ✓');
        } catch (e) {
            toast(e instanceof ApiError ? e.message : 'Something went wrong.', 'err');
        }
    }

    return (
        <ConsoleModal open onClose={onClose}>
            <h3>Documents · {kb.name}</h3>
            <p className="lead">{kb.documents_count} document(s), {kb.chunks_count} chunks total.</p>

            {docs === null && <p className="faint">Loading…</p>}
            {docs?.length === 0 && <p className="faint">No documents uploaded yet.</p>}
            {docs?.map((doc) => (
                <div
                    key={doc.id}
                    style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 0', borderBottom: '1px solid var(--line-soft)' }}
                >
                    <div style={{ flex: 1 }}>
                        <div style={{ fontSize: 13 }}>{doc.source_name}</div>
                        <div className="faint mono" style={{ fontSize: 11 }}>
                            {doc.status}
                        </div>
                    </div>
                    <button className="btn danger sm" onClick={() => remove(doc.id)}>
                        Delete
                    </button>
                </div>
            ))}

            <div className="modal-actions">
                <button className="btn primary" onClick={onClose}>
                    Done
                </button>
            </div>
        </ConsoleModal>
    );
}

function UploadModal({ kb, onClose }: { kb: KnowledgeBase; onClose: () => void }) {
    const toast = useConsoleToast();
    const [file, setFile] = useState<File | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function submit() {
        if (!file) {
return;
}

        setSaving(true);
        setError(null);

        try {
            const form = new FormData();
            form.append('file', file);
            await api(`/knowledge-bases/${kb.id}/documents`, { method: 'POST', body: form });
            onClose();
            router.reload({ only: ['knowledgeBases'] });
            toast('Queued for indexing — embedding in the background');
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Something went wrong.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <ConsoleModal open onClose={onClose}>
            <h3>Upload document · {kb.name}</h3>
            <p className="lead">.txt or .md only in this MVP. We extract → chunk → embed in the background.</p>
            <div className="field">
                <label>File</label>
                <input type="file" accept=".txt,.md" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            </div>
            {error && <p style={{ color: 'var(--coral)', fontSize: 13 }}>{error}</p>}
            <div className="modal-actions">
                <button className="btn ghost" onClick={onClose}>
                    Cancel
                </button>
                <button className="btn primary" onClick={submit} disabled={saving || !file}>
                    Upload
                </button>
            </div>
        </ConsoleModal>
    );
}
