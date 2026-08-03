import { Link } from '@inertiajs/react';
import '../../../css/auth.css';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const FEATURES = [
    {
        title: 'OpenAI-compatible gateway',
        body: 'Point any OpenAI client at your own /v1/chat/completions endpoint.',
    },
    {
        title: 'Your own Gemini accounts',
        body: 'Rotate a pool of Gemini logins so cookie expiry never takes your app down.',
    },
    {
        title: 'Built-in RAG',
        body: 'Attach your own documents — chunked, embedded, and retrieved automatically.',
    },
];

function BrandLogo({ className }: { className?: string }) {
    return <div className={`auth-logo-badge ${className ?? ''}`}>⧉</div>;
}

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="auth-shell">
            <div className="auth-brand-panel">
                <Link href={home()} className="auth-brand-lockup">
                    <BrandLogo />
                    <div>
                        <b>TokenForge</b>
                        <small>gemini gateway &amp; rag console</small>
                    </div>
                </Link>

                <div className="auth-pitch">
                    <h2>One token. Your whole Gemini pool.</h2>
                    <p>
                        TokenForge fronts your own Gemini accounts with a
                        single OpenAI-compatible endpoint — rotation, usage
                        tracking, and retrieval-augmented answers included.
                    </p>

                    <div className="auth-features">
                        {FEATURES.map((f) => (
                            <div className="auth-feature" key={f.title}>
                                <span className="dot" />
                                <div>
                                    <b>{f.title}</b>
                                    <span>{f.body}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="auth-foot">TokenForge · gateway console</div>
            </div>

            <div className="auth-form-panel">
                <div className="auth-form-card">
                    <Link
                        href={home()}
                        className="auth-brand-lockup auth-mobile-brand"
                        style={{ marginBottom: 32, justifyContent: 'center' }}
                    >
                        <BrandLogo />
                        <div>
                            <b>TokenForge</b>
                            <small>gemini gateway &amp; rag console</small>
                        </div>
                    </Link>

                    <div style={{ marginBottom: 28 }}>
                        <h1
                            style={{
                                fontSize: 22,
                                fontWeight: 650,
                                letterSpacing: '-0.01em',
                                margin: '0 0 6px',
                            }}
                        >
                            {title}
                        </h1>
                        <p
                            style={{
                                fontSize: 13.5,
                                color: 'var(--muted-foreground)',
                                margin: 0,
                            }}
                        >
                            {description}
                        </p>
                    </div>

                    {children}
                </div>
            </div>
        </div>
    );
}
