import { Component  } from 'react';
import type {ReactNode} from 'react';

export default class ConsoleErrorBoundary extends Component<{ children: ReactNode }, { error: Error | null }> {
    state = { error: null as Error | null };

    static getDerivedStateFromError(error: Error) {
        return { error };
    }

    render() {
        if (this.state.error) {
            return (
                <div className="card" style={{ margin: 24, borderColor: 'var(--coral)' }}>
                    <h3 style={{ color: 'var(--coral)' }}>Something broke rendering this page</h3>
                    <pre style={{ whiteSpace: 'pre-wrap', fontFamily: 'var(--mono)', fontSize: 12 }}>
                        {this.state.error.message}
                        {'\n\n'}
                        {this.state.error.stack}
                    </pre>
                </div>
            );
        }

        return this.props.children;
    }
}
