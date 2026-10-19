import { useState } from 'react';
import { ConfirmDialog } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';

interface StatusTarget {
    id: number;
    name: string;
    is_active: boolean;
}

/**
 * Confirmed activate/deactivate for structure records
 * (PATCH /{resource}/{id}/status).
 */
export function useStatusAction<T extends StatusTarget>(resource: string, noun: string, onDone: (updated: T) => void, deactivateNote?: string) {
    const toast = useToast();
    const [target, setTarget] = useState<T | null>(null);
    const [busy, setBusy] = useState(false);

    const confirm = async () => {
        if (!target) return;
        setBusy(true);
        try {
            const { data } = await api.patch<{ data: T }>(`/${resource}/${target.id}/status`, { is_active: !target.is_active });
            toast.success(`${target.name} ${data.data.is_active ? 'activated' : 'deactivated'}.`);
            onDone(data.data);
            setTarget(null);
        } catch (err) {
            toast.error(fieldErrors(err).is_active ?? errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    const dialog = target ? (
        <ConfirmDialog
            open
            loading={busy}
            tone={target.is_active ? 'danger' : 'primary'}
            title={`${target.is_active ? 'Deactivate' : 'Activate'} ${noun}`}
            confirmLabel={target.is_active ? 'Deactivate' : 'Activate'}
            onCancel={() => setTarget(null)}
            onConfirm={confirm}
            message={
                target.is_active ? (
                    <>
                        <strong>{target.name}</strong> will no longer be available for new assignments or selections. Existing records and
                        history are kept, and it can be reactivated later.{deactivateNote ? ` ${deactivateNote}` : ''}
                    </>
                ) : (
                    <>
                        <strong>{target.name}</strong> will be available again for new assignments.
                    </>
                )
            }
        />
    ) : null;

    return { request: (record: T) => setTarget(record), dialog };
}
