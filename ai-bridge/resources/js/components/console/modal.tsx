import type {ReactNode} from 'react';

export default function ConsoleModal({
    open,
    onClose,
    children,
}: {
    open: boolean;
    onClose: () => void;
    children: ReactNode;
}) {
    if (!open) {
return null;
}

    return (
        <div
            className="modal-bg show"
            onClick={(e) => {
                if (e.target === e.currentTarget) {
onClose();
}
            }}
        >
            <div className="modal">{children}</div>
        </div>
    );
}
