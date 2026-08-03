export default function StatCard({
    label,
    value,
    delta,
    direction,
}: {
    label: string;
    value: string | number;
    delta?: string;
    direction?: 'up' | 'down';
}) {
    return (
        <div className="card">
            <h3>{label}</h3>
            <div className="stat">{value}</div>
            <div className={`delta ${direction ?? 'faint'}`}>{delta ?? ''}</div>
        </div>
    );
}
