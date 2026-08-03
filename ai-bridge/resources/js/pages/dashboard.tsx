import Pill from '@/components/console/pill';
import Sparkline from '@/components/console/sparkline';
import StatCard from '@/components/console/stat-card';
import ConsoleLayout from '@/layouts/console-layout';

type Problem = { title: string; detail: string; kind: 'expired' | 'cooling' };
type TopApp = { id: number; name: string; requests: number };

type Props = {
    stats: {
        requests24h: number;
        requestsDeltaPct: number | null;
        activeTokens: number;
        totalTokens: number;
        errorRatePct: number;
        poolActive: number;
        poolTotal: number;
    };
    sparkline: number[];
    problems: Problem[];
    topApps: TopApp[];
};

export default function Dashboard({ stats, sparkline, problems, topApps }: Props) {
    const maxRequests = Math.max(...topApps.map((a) => a.requests), 1);

    return (
        <ConsoleLayout title="Dashboard" crumb="workspace / overview">
            <p className="lead">
                Everything routing through the gateway right now. Requests are forwarded to Gemini through your
                rotating account pool — no API keys leave this workspace.
            </p>

            <div className="grid g-4" style={{ marginTop: 20 }}>
                <StatCard
                    label="Requests · 24h"
                    value={stats.requests24h.toLocaleString()}
                    delta={
                        stats.requestsDeltaPct !== null
                            ? `${stats.requestsDeltaPct >= 0 ? '▲' : '▼'} ${Math.abs(stats.requestsDeltaPct)}% vs previous 24h`
                            : undefined
                    }
                    direction={stats.requestsDeltaPct !== null && stats.requestsDeltaPct >= 0 ? 'up' : 'down'}
                />
                <StatCard label="Active tokens" value={stats.activeTokens} delta={`of ${stats.totalTokens} total`} />
                <StatCard label="Error rate" value={`${stats.errorRatePct}%`} />
                <StatCard label="Pool health" value={`${stats.poolActive} / ${stats.poolTotal}`} delta="live accounts" />
            </div>

            <div className="section-title">
                <h2>Request volume</h2>
                <span className="hint">last 14 days</span>
            </div>
            <div className="card">
                <Sparkline points={sparkline} />
            </div>

            <div className="grid g-2" style={{ marginTop: 16 }}>
                <div className="card">
                    <div className="section-title" style={{ margin: '0 0 12px' }}>
                        <h2>Problems needing attention</h2>
                    </div>
                    {problems.length === 0 && <p className="faint">Nothing needs attention right now.</p>}
                    {problems.map((p, i) => (
                        <div
                            key={i}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: 12,
                                padding: '10px 0',
                                borderBottom: '1px solid var(--line-soft)',
                            }}
                        >
                            <Pill kind={p.kind}>{''}</Pill>
                            <div style={{ flex: 1 }}>
                                <div style={{ fontSize: 13, fontWeight: 550 }}>{p.title}</div>
                                <div className="faint" style={{ fontSize: 12 }}>
                                    {p.detail}
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
                <div className="card">
                    <div className="section-title" style={{ margin: '0 0 12px' }}>
                        <h2>Top apps by volume</h2>
                    </div>
                    {topApps.length === 0 && <p className="faint">No requests yet.</p>}
                    {topApps.map((app) => (
                        <div key={app.id} style={{ marginBottom: 14 }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12.5, marginBottom: 5 }}>
                                <span>{app.name}</span>
                                <span className="mono faint">{app.requests.toLocaleString()}</span>
                            </div>
                            <div style={{ height: 6, background: 'var(--line)', borderRadius: 6, overflow: 'hidden' }}>
                                <div
                                    style={{
                                        height: '100%',
                                        width: `${(app.requests / maxRequests) * 100}%`,
                                        background: 'linear-gradient(90deg, var(--cyan), var(--violet))',
                                    }}
                                />
                            </div>
                        </div>
                    ))}
                    <div className="note">
                        Billing is out of scope for now — these are <b>usage</b> counts, not spend.
                    </div>
                </div>
            </div>
        </ConsoleLayout>
    );
}
