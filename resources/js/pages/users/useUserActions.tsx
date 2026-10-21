import { useState } from 'react';
import { ConfirmDialog } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import type { User } from '@/types';

type Pending = { kind: 'status' | 'reset' | 'delete'; user: User } | null;

/**
 * Shared activate/deactivate, send-reset and delete flows with confirmation,
 * used by the user list and the user details screen.
 */
export function useUserActions(onChanged: (user?: User) => void, onDeleted?: (user: User) => void) {
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
            } else if (pending.kind === 'reset') {
                const { data } = await api.post<{ message: string }>(`/users/${pending.user.id}/send-password-reset`);
                toast.success(data.message);
                onChanged();
            } else {
                // Removed from the UI only after the server confirms the delete.
                const { data } = await api.delete<{ message: string }>(`/users/${pending.user.id}`);
                toast.success(data.message);
                if (onDeleted) onDeleted(pending.user);
                else onChanged();
            }
            setPending(null);
        } catch (err) {
            const fields = Object.values(fieldErrors(err));
            toast.error(fields[0] ?? errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    const content = (p: NonNullable<Pending>) => {
        if (p.kind === 'delete') {
            return {
                title: 'Delete user',
                confirmLabel: 'Delete user',
                tone: 'danger' as const,
                message: (
                    <>
                        Are you sure you want to permanently delete <strong>{p.user.full_name}</strong> ({p.user.email})? Their account and role
                        assignments are removed and this cannot be undone. Past activity logs are kept.
                    </>
                ),
            };
        }
        if (p.kind === 'reset') {
            return {
                title: 'Send password reset link',
                confirmLabel: 'Send link',
                tone: 'primary' as const,
                message: <>A secure link to set a new password will be emailed to <strong>{p.user.email}</strong>. No password is shown or sent.</>,
            };
        }
        return p.user.is_active
            ? {
                title: 'Deactivate user',
                confirmLabel: 'Deactivate',
                tone: 'danger' as const,
                message: <><strong>{p.user.full_name}</strong> will be signed out immediately and will not be able to sign in until reactivated. Their records and history are kept.</>,
            }
            : {
                title: 'Activate user',
                confirmLabel: 'Activate',
                tone: 'primary' as const,
                message: <><strong>{p.user.full_name}</strong> will be able to sign in again with their existing password.</>,
            };
    };

    const dialog = pending ? (
        <ConfirmDialog open loading={busy} onCancel={() => setPending(null)} onConfirm={run} {...content(pending)} />
    ) : null;

    return {
        toggleStatus: (user: User) => setPending({ kind: 'status', user }),
        sendReset: (user: User) => setPending({ kind: 'reset', user }),
        remove: (user: User) => setPending({ kind: 'delete', user }),
        dialog,
    };
}
