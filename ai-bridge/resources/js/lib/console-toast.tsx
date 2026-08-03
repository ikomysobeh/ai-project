import { createContext, useCallback, useContext, useRef, useState } from 'react';

type ToastState = { message: string; kind: 'ok' | 'err'; visible: boolean };

const ToastContext = createContext<((message: string, kind?: 'ok' | 'err') => void) | null>(null);

export function ConsoleToastProvider({ children }: { children: React.ReactNode }) {
    const [toast, setToast] = useState<ToastState>({ message: '', kind: 'ok', visible: false });
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const show = useCallback((message: string, kind: 'ok' | 'err' = 'ok') => {
        setToast({ message, kind, visible: true });

        if (timer.current) {
clearTimeout(timer.current);
}

        timer.current = setTimeout(() => setToast((t) => ({ ...t, visible: false })), 2200);
    }, []);

    return (
        <ToastContext.Provider value={show}>
            {children}
            <div className={`toast${toast.visible ? ' show' : ''}`}>
                <span className={toast.kind}>●</span> {toast.message}
            </div>
        </ToastContext.Provider>
    );
}

export function useConsoleToast() {
    const ctx = useContext(ToastContext);

    if (!ctx) {
        throw new Error('useConsoleToast must be used within ConsoleToastProvider');
    }

    return ctx;
}
