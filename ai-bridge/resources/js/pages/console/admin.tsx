import StatCard from '@/components/console/stat-card';
import ConsoleLayout from '@/layouts/console-layout';

type MemberUsage = {
    name: string;
    role: string;
    apps: string;
    requests30d: number;
    errors30d: number;
    tokens: number;
};

type Props = {
    stats: {
        members: number;
        pendingInvites: number;
        requests30d: number;
        failed30d: number;
        tokensIssued: number;
        tokensActive: number;
    };
    usageByMember: MemberUsage[];
};

export default function Admin({ stats, usageByMember }: Props) {
    const failRate = stats.requests30d > 0 ? ((stats.failed30d / stats.requests30d) * 100).toFixed(1) : '0.0';

    return (
        <ConsoleLayout title="Admin Overview" crumb="workspace / admin">
            <p className="lead">
                Tenant-wide visibility: who's using what, where the errors are, and whether the account pool is
                healthy.
            </p>

            <div className="grid g-4" style={{ margin: '18px 0' }}>
                <StatCard label="Members" value={stats.members} delta={`${stats.pendingInvites} pending invite(s)`} />
                <StatCard label="Requests · 30d" value={stats.requests30d.toLocaleString()} delta="across all apps" />
                <StatCard
                    label="Failed · 30d"
                    value={stats.failed30d.toLocaleString()}
                    delta={`${failRate}% of total`}
                    direction={Number(failRate) > 5 ? 'down' : undefined}
                />
                <StatCard label="Tokens issued" value={stats.tokensIssued} delta={`${stats.tokensActive} active`} />
            </div>

            <div className="section-title">
                <h2>Usage by member</h2>
            </div>
            <div className="card" style={{ padding: '4px 0' }}>
                <table className="tbl">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Apps</th>
                            <th>Requests · 30d</th>
                            <th>Errors</th>
                            <th>Tokens</th>
                        </tr>
                    </thead>
                    <tbody>
                        {usageByMember.map((m, i) => (
                            <tr key={i}>
                                <td>
                                    <b>{m.name}</b>
                                    <div className="faint mono" style={{ fontSize: 11 }}>
                                        {m.role}
                                    </div>
                                </td>
                                <td>{m.apps}</td>
                                <td className="mono">{m.requests30d.toLocaleString()}</td>
                                <td className="mono">{m.errors30d.toLocaleString()}</td>
                                <td className="mono">{m.tokens}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="note">
                No cost column — <b>billing is out of scope</b>. Admins see request counts, tokens, and error rates.
            </div>
        </ConsoleLayout>
    );
}
