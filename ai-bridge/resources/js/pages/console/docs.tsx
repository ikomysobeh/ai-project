import ConsoleLayout from '@/layouts/console-layout';

type Props = {
    html: string | null;
};

export default function Docs({ html }: Props) {
    return (
        <ConsoleLayout title="Documentation" crumb="workspace / docs">
            {html ? (
                <div className="card docs-content" dangerouslySetInnerHTML={{ __html: html }} />
            ) : (
                <div className="card faint">
                    The documentation file isn't available on this deployment. Check that
                    <code> docs/12-using-the-platform.md</code> exists and is mounted into the app container.
                </div>
            )}
        </ConsoleLayout>
    );
}
