import { useState } from 'react';
import { ConfirmDialog } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import type { User } from '@/types';

type Pending = { kind: 'status'; user: User } | { kind: 'reset'; user: User } | null;

/**
 * Shared activate/deactivate and send-reset flows with confirmation, used
 * by the user list and the user details screen.
 */
export function useUserActions(onChanged: (user?: User) => void) {
    const toast = useToast();
    const [pending, setPending] = useState<Pending>(null);
    const [busy, setBusy] = useState(false);

    const run = async () => {
        if (!pending) return;
        setBusy(true);
        try {
            if (pending.kind === 'status') {
                const { data } = await api.patch<{ data: User }>(`/users/${pending.user.id}/status`, { is_active: !pending.user.is_active });
                toast.success(`${pending.user.full_name} has been ${data.data.is_active ? 'activated' : 'deactivated'}.`);
                onChanged(data.data);
            } else {
                const { data } = await api.post<{ message: string }>(`/users/${pending.user.id}/send-password-reset`);
                toast.success(data.message);
                onChanged();
            }
            setPending(null);
        } catch (err) {
            const fields = Object.values(fieldErrors(err));
            toast.error(fields[0] ?? errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    const dialog = pending ? (
        <ConfirmDialog
            open
            loading={busy}
            onCancel={() => setPending(null)}
            onConfirm={run}
            tone={pending.kind === 'status' && pending.user.is_active ? 'danger' : 'primary'}
            title={
                pending.kind === 'reset' ? 'Send password reset link' : pending.user.is_active ? 'Deactivate user' : 'Activate user'
            }
            confirmLabel={pending.kind === 'reset' ? 'Send link' : pending.user.is_active ? 'Deactivate' : 'Activate'}
            message={
                pending.kind === 'reset' ? (
                    <>A secure link to set a new password will be emailed to <strong>{pending.user.email}</strong>. No password is shown or sent.</>
                ) : pending.user.is_active ? (
                    <>
                        <strong>{pending.user.full_name}</strong> will be signed out immediately and will not be able to sign in until reactivated. Their records and history are kept.
                    </>
                ) : (
                    <>
                        <strong>{pending.user.full_name}</strong> will be able to sign in again with their existing password.
                    </>
                )
            }
        />
    ) : null;

    return {
        toggleStatus: (user: User) => setPending({ kind: 'status', user }),
        sendReset: (user: User) => setPending({ kind: 'reset', user }),
        dialog,
    };
}
