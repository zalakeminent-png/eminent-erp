import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Eye, KeySquare, Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Table, Td, Th } from '@/components/ui/Table';
import { ConfirmDialog } from '@/components/ui/Modal';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import type { Role } from '@/types';

export default function RoleListPage() {
    const { can } = useAuth();
    const toast = useToast();
    const { data, loading, error, reload } = useApi<{ data: Role[] }>('/roles', { all: 1 });
    const [deleting, setDeleting] = useState<Role | null>(null);
    const [busy, setBusy] = useState(false);

    const onDelete = async () => {
        if (!deleting) return;
        setBusy(true);
        try {
            await api.delete(`/roles/${deleting.id}`);
            toast.success(`Role "${deleting.name}" deleted.`);
            setDeleting(null);
            reload();
        } catch (err) {
            toast.error(Object.values(fieldErrors(err))[0] ?? errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <PageHeader
                title="Roles & Permissions"
                description="Roles bundle permissions. Users receive permissions through their roles."
                actions={can('roles.create') && <ButtonLink to="/settings/roles/new" icon={<Plus className="h-4 w-4" />}>Add role</ButtonLink>}
            />
            <Card>
                {error ? (
                    <div className="p-4"><Alert tone="danger">{error}</Alert></div>
                ) : loading ? (
                    <LoadingBlock />
                ) : !data?.data.length ? (
                    <EmptyState icon={<ShieldCheck className="h-6 w-6" />} title="No roles" description="Run the database seeder to create the default roles." />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Role</Th>
                                <Th className="hidden sm:table-cell">Type</Th>
                                <Th>Status</Th>
                                <Th className="text-right">Users</Th>
                                <Th className="hidden text-right md:table-cell">Permissions</Th>
                                <Th className="text-right"><span className="sr-only">Actions</span></Th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 bg-white">
                            {data.data.map((role) => {
                                const deletable = !role.is_system && role.users_count === 0;
                                return (
                                    <tr key={role.id} className="hover:bg-slate-50">
                                        <Td>
                                            <Link to={`/settings/roles/${role.id}`} className="font-medium text-slate-900 hover:text-brand-700">{role.name}</Link>
                                            {role.description && <p className="max-w-md truncate text-xs text-slate-500">{role.description}</p>}
                                        </Td>
                                        <Td className="hidden sm:table-cell">{role.is_system ? <Badge tone="info">Core</Badge> : <Badge>Custom</Badge>}</Td>
                                        <Td><StatusBadge active={role.is_active} /></Td>
                                        <Td className="text-right tabular-nums">{role.users_count}</Td>
                                        <Td className="hidden text-right tabular-nums md:table-cell">{role.permissions_count}</Td>
                                        <Td className="whitespace-nowrap text-right">
                                            <div className="flex justify-end gap-1">
                                                <ButtonLink to={`/settings/roles/${role.id}`} variant="ghost" size="sm" aria-label={`View ${role.name}`} icon={<Eye className="h-4 w-4" />} />
                                                <ButtonLink to={`/settings/roles/${role.id}/permissions`} variant="ghost" size="sm" aria-label={`Permissions for ${role.name}`} icon={<KeySquare className="h-4 w-4" />} />
                                                {can('roles.update') && (
                                                    <ButtonLink to={`/settings/roles/${role.id}/edit`} variant="ghost" size="sm" aria-label={`Edit ${role.name}`} icon={<Pencil className="h-4 w-4" />} />
                                                )}
                                                {can('roles.delete') && !role.is_system && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={!deletable}
                                                        title={deletable ? undefined : 'Reassign users before deleting this role'}
                                                        onClick={() => setDeleting(role)}
                                                        aria-label={`Delete ${role.name}`}
                                                        icon={<Trash2 className="h-4 w-4 text-red-500" />}
                                                    />
                                                )}
                                            </div>
                                        </Td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </Table>
                )}
            </Card>
            <ConfirmDialog
                open={deleting !== null}
                title="Delete role"
                tone="danger"
                confirmLabel="Delete role"
                loading={busy}
                onCancel={() => setDeleting(null)}
                onConfirm={onDelete}
                message={<>Permanently delete the role <strong>{deleting?.name}</strong>? This cannot be undone.</>}
            />
        </>
    );
}
