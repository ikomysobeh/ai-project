import '../../css/console.css';
import { Head, Link, usePage } from '@inertiajs/react';
import type {ReactNode} from 'react';
import ConsoleErrorBoundary from '@/components/console/error-boundary';
import { api } from '@/lib/console-api';

type NavItem = {
    href: string;
    label: string;
    icon: string;
    badge?: number;
};

export default function ConsoleLayout({
    title,
    crumb,
    actions,
    children,
}: {
    title: string;
    crumb: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const { url, props } = usePage();
    const { tenant, navCounts, auth } = props;
    const isAdmin = auth.user.role === 'owner' || auth.user.role === 'admin';

    const workspace: NavItem[] = [
        { href: '/dashboard', label: 'Dashboard', icon: '◈' },
        { href: '/console/apps', label: 'Apps', icon: '▤', badge: navCounts?.apps },
        { href: '/console/tokens', label: 'API Tokens', icon: '⚿', badge: navCounts?.tokens },
        { href: '/console/playground', label: 'Playground', icon: '⌘' },
    ];

    const sources: NavItem[] = [
        { href: '/console/accounts', label: 'Gemini Accounts', icon: '⟳', badge: navCounts?.accounts },
        { href: '/console/knowledge', label: 'Knowledge (RAG)', icon: '▦', badge: navCounts?.knowledgeBases },
    ];

    const help: NavItem[] = [{ href: '/console/docs', label: 'Documentation', icon: '❖' }];

    const admin: NavItem[] = [
        { href: '/console/admin', label: 'Overview', icon: '◭' },
        { href: '/console/team', label: 'Team & Invites', icon: '◑' },
    ];

    return (
        <>
            <Head title={title} />
            <div className="console">
                <div className="app">
                    <aside className="sidebar">
                        <div className="brand">
                            <div className="logo">⧉</div>
                            <div>
                                <b>TokenForge</b>
                                <small>gateway console</small>
                            </div>
                        </div>

                        <NavGroup label="Workspace" items={workspace} current={url} />
                        <NavGroup label="Sources" items={sources} current={url} />
                        {isAdmin && <NavGroup label="Admin" items={admin} current={url} />}
                        <NavGroup label="Help" items={help} current={url} />

                        {tenant && (
                            <div className="side-foot">
                                <div className="tenant">
                                    <div className="av">{tenant.name.charAt(0).toUpperCase()}</div>
                                    <div>
                                        <div style={{ fontSize: 13, fontWeight: 600 }}>{tenant.name}</div>
                                        <small className="mono">tenant · {tenant.slug}</small>
                                    </div>
                                </div>
                                <div style={{ marginTop: 10, fontSize: 12 }} className="faint">
                                    {auth.user.name} ({auth.user.role}) ·{' '}
                                    <button
                                        className="nav-item"
                                        style={{ display: 'inline', padding: 0, width: 'auto' }}
                                        onClick={async () => {
                                            await api('/auth/logout', { method: 'POST' });
                                            window.location.href = '/login';
                                        }}
                                    >
                                        Log out
                                    </button>
                                </div>
                            </div>
                        )}
                    </aside>

                    <main className="main">
                        <div className="topbar">
                            <div>
                                <h1>{title}</h1>
                                <div className="crumb">{crumb}</div>
                            </div>
                            <div className="spacer" />
                            {actions}
                        </div>

                        <div className="content">
                            <ConsoleErrorBoundary>{children}</ConsoleErrorBoundary>
                        </div>
                    </main>
                </div>
            </div>
        </>
    );
}

function NavGroup({ label, items, current }: { label: string; items: NavItem[]; current: string }) {
    return (
        <div className="nav-group">
            <div className="nav-label">{label}</div>
            {items.map((item) => (
                <Link
                    key={item.href}
                    href={item.href}
                    className={`nav-item${current.startsWith(item.href) ? ' active' : ''}`}
                >
                    <span className="ico">{item.icon}</span> {item.label}
                    {item.badge !== undefined && <span className="nav-badge">{item.badge}</span>}
                </Link>
            ))}
        </div>
    );
}
