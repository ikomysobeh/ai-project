type PillKind = 'active' | 'cooling' | 'expired' | 'ready' | 'muted' | 'rag';

const LABELS: Record<PillKind, string> = {
    active: 'active',
    cooling: 'cooling down',
    expired: 'expired',
    ready: 'ready',
    muted: 'inactive',
    rag: 'rag',
};

export default function Pill({ kind, children, title }: { kind: PillKind; children?: React.ReactNode; title?: string }) {
    return (
        <span className={`pill ${kind}`} title={title}>
            {children ?? LABELS[kind]}
        </span>
    );
}
