import { useEffect, useRef, type ReactNode } from 'react';
import { X } from 'lucide-react';
import { Button } from './Button';

export function Modal({ open, onClose, title, children, footer }: { open: boolean; onClose: () => void; title: string; children: ReactNode; footer?: ReactNode }) {
    const panel = useRef<HTMLDivElement>(null);
    // Latest onClose without making the effect depend on it: callers often
    // pass an inline arrow, which is a new function on every render.
    const onCloseRef = useRef(onClose);
    onCloseRef.current = onClose;

    // Runs only when the dialog opens/closes — never on re-renders while
    // typing, so focus is not pulled away from inputs inside the dialog.
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onCloseRef.current();
        document.addEventListener('keydown', onKey);
        // Respect autoFocus on a field inside the dialog; otherwise focus the panel.
        if (!panel.current?.contains(document.activeElement)) panel.current?.focus();
        return () => document.removeEventListener('keydown', onKey);
    }, [open]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center">
            <div className="absolute inset-0 bg-slate-900/40" onClick={onClose} aria-hidden="true" />
            <div ref={panel} tabIndex={-1} role="dialog" aria-modal="true" aria-label={title} className="relative w-full max-w-md rounded-lg bg-white shadow-xl focus:outline-none">
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 className="text-base font-semibold text-slate-900">{title}</h2>
                    <button type="button" onClick={onClose} className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close">
                        <X className="h-4 w-4" />
                    </button>
                </div>
                <div className="px-5 py-4 text-sm text-slate-600">{children}</div>
                {footer && <div className="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3 rounded-b-lg">{footer}</div>}
            </div>
        </div>
    );
}

export function ConfirmDialog({
    open,
    title,
    message,
    confirmLabel = 'Confirm',
    tone = 'primary',
    loading = false,
    onConfirm,
    onCancel,
}: {
    open: boolean;
    title: string;
    message: ReactNode;
    confirmLabel?: string;
    tone?: 'primary' | 'danger';
    loading?: boolean;
    onConfirm: () => void;
    onCancel: () => void;
}) {
    return (
        <Modal
            open={open}
            onClose={onCancel}
            title={title}
            footer={
                <>
                    <Button variant="secondary" onClick={onCancel} disabled={loading}>Cancel</Button>
                    <Button variant={tone} onClick={onConfirm} loading={loading}>{confirmLabel}</Button>
                </>
            }
        >
            {message}
        </Modal>
    );
}
