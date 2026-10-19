import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';
import { CheckCircle2, AlertCircle, X } from 'lucide-react';
import { cx } from '@/lib/format';

type ToastTone = 'success' | 'error';
interface ToastItem {
    id: number;
    tone: ToastTone;
    message: string;
}

interface ToastApi {
    success: (message: string) => void;
    error: (message: string) => void;
}

const ToastContext = createContext<ToastApi | null>(null);

export function ToastProvider({ children }: { children: ReactNode }) {
    const [items, setItems] = useState<ToastItem[]>([]);

    const dismiss = useCallback((id: number) => setItems((all) => all.filter((t) => t.id !== id)), []);

    const push = useCallback(
        (tone: ToastTone, message: string) => {
            const id = Date.now() + Math.random();
            setItems((all) => [...all.slice(-3), { id, tone, message }]);
            setTimeout(() => dismiss(id), tone === 'error' ? 7000 : 4000);
        },
        [dismiss],
    );

    const api = useMemo<ToastApi>(() => ({ success: (m) => push('success', m), error: (m) => push('error', m) }), [push]);

    return (
        <ToastContext.Provider value={api}>
            {children}
            <div className="pointer-events-none fixed inset-x-4 bottom-4 z-[60] flex flex-col items-end gap-2 sm:left-auto sm:right-6" aria-live="polite">
                {items.map((toast) => (
                    <div
                        key={toast.id}
                        className={cx(
                            'pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-white px-4 py-3 text-sm shadow-lg',
                            toast.tone === 'success' ? 'border-emerald-200' : 'border-red-200',
                        )}
                    >
                        {toast.tone === 'success' ? (
                            <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" aria-hidden="true" />
                        ) : (
                            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-red-600" aria-hidden="true" />
                        )}
                        <p className="flex-1 text-slate-700">{toast.message}</p>
                        <button type="button" onClick={() => dismiss(toast.id)} className="text-slate-400 hover:text-slate-600" aria-label="Dismiss">
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast(): ToastApi {
    const context = useContext(ToastContext);
    if (!context) throw new Error('useToast must be used within <ToastProvider>');
    return context;
}
