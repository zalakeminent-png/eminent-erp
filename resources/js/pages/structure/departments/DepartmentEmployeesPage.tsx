import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { UserMinus, UserPlus, Users } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/Modal';
import { Pagination, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import type { PaginationMeta, User } from '@/types';
import type { Department, UserSummary } from '../types';
import { SearchInput } from '../components/ListControls';
import { UserPicker } from '../components/UserPicker';

interface MembersResponse {
    data: User[];
    meta: PaginationMeta & { primary_user_ids: number[] };
}

/**
 * Department Employees: lists primary and additional members and lets
 * authorized users add or remove them (PUT /departments/{id}/users).
 */
export default function DepartmentEmployeesPage() {
    const { id } = useParams();
    const { can, user: me } = useAuth();
    const toast = useToast();
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const debounced = useDebouncedValue(search);
    const department = useApi<{ data: Department }>(`/departments/${id}`);
    const members = useApi<MembersResponse>(`/departments/${id}/users`, { search: debounced, page, per_page: 200 });
    const [adding, setAdding] = useState<UserSummary | null>(null);
    const [removing, setRemoving] = useState<User | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const editable = can('departments.assign-users');

    if (department.loading) return <LoadingBlock />;
    if (department.error || !department.data) return <Alert tone="danger">{department.error ?? 'Department not found.'}</Alert>;
    const d = department.data.data;

    // The endpoint replaces the full member list, so fetch all current ids first.
    const save = async (change: (ids: number[]) => number[], message: string) => {
        setBusy(true);
        setError(null);
        try {
            const { data: all } = await api.get<MembersResponse>(`/departments/${d.id}/users`, { params: { per_page: 200 } });
            if (all.meta.total > all.data.length) throw new Error('Too many members to edit here.');
            await api.put(`/departments/${d.id}/users`, { user_ids: change(all.data.map((u) => u.id)) });
            toast.success(message);
            setAdding(null);
            setRemoving(null);
            members.reload();
            department.reload();
        } catch (err) {
            setError(fieldErrors(err).user_ids ?? errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    const primaryIds = new Set(members.data?.meta.primary_user_ids ?? []);

    return (
        <>
            <PageHeader
                title={`Employees: ${d.name}`}
                breadcrumb={<><Link to="/factory-setup/departments" className="hover:text-slate-700">Departments</Link> / <Link to={`/factory-setup/departments/${d.id}`} className="hover:text-slate-700">{d.name}</Link></>}
                description="Where users work. Roles (what they may do) are managed under Users."
            />
            {error && <Alert tone="danger" className="mb-4">{error}</Alert>}

            {editable && (
                <Card className="mb-6">
                    <CardHeader title="Add employee" description={d.is_active ? 'Users without a primary department get this one as primary; others get it as an additional department.' : undefined} />
                    <CardBody>
                        {!d.is_active ? (
                            <Alert tone="warning">Inactive departments cannot receive new assignments.</Alert>
                        ) : (
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <div className="flex-1"><UserPicker label="User" value={adding} onChange={setAdding} /></div>
                                <Button
                                    icon={<UserPlus className="h-4 w-4" />}
                                    disabled={!adding || busy || adding.id === me?.id}
                                    loading={busy && adding !== null}
                                    onClick={() => adding && save((ids) => [...new Set([...ids, adding.id])], `${adding.full_name} added to ${d.name}.`)}
                                >
                                    Add
                                </Button>
                            </div>
                        )}
                    </CardBody>
                </Card>
            )}

            <Card>
                <div className="border-b border-slate-200 p-4">
                    <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder="Search employees" />
                </div>
                {members.loading && !members.data ? (
                    <LoadingBlock />
                ) : !members.data?.data.length ? (
                    <EmptyState icon={<Users className="h-6 w-6" />} title={search ? 'No matching employees' : 'No employees assigned'} />
                ) : (
                    <>
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Employee</Th>
                                    <Th className="hidden sm:table-cell">Emp. code</Th>
                                    <Th>Assignment</Th>
                                    <Th className="hidden md:table-cell">Roles</Th>
                                    <Th>Status</Th>
                                    {editable && <Th className="text-right"><span className="sr-only">Actions</span></Th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {members.data.data.map((u) => (
                                    <tr key={u.id}>
                                        <Td>
                                            {can('users.view') ? <Link to={`/settings/users/${u.id}`} className="font-medium text-brand-700 hover:underline">{u.full_name}</Link> : <span className="font-medium">{u.full_name}</span>}
                                            <p className="text-xs text-slate-500">{u.email}</p>
                                        </Td>
                                        <Td className="hidden font-mono text-xs sm:table-cell">{u.employee_code ?? '—'}</Td>
                                        <Td>{primaryIds.has(u.id) ? <Badge tone="brand">Primary</Badge> : <Badge>Additional</Badge>}</Td>
                                        <Td className="hidden md:table-cell"><div className="flex flex-wrap gap-1">{u.roles?.map((r) => <Badge key={r.id}>{r.name}</Badge>)}</div></Td>
                                        <Td><StatusBadge active={u.is_active} /></Td>
                                        {editable && (
                                            <Td className="text-right">
                                                {u.id !== me?.id && (
                                                    <Button variant="ghost" size="sm" onClick={() => setRemoving(u)} aria-label={`Remove ${u.full_name}`} icon={<UserMinus className="h-4 w-4 text-red-500" />} />
                                                )}
                                            </Td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination meta={members.data.meta} onPage={setPage} />
                    </>
                )}
            </Card>

            <ConfirmDialog
                open={removing !== null}
                title="Remove from department"
                tone="danger"
                confirmLabel="Remove"
                loading={busy}
                onCancel={() => setRemoving(null)}
                onConfirm={() => removing && save((ids) => ids.filter((x) => x !== removing.id), `${removing.full_name} removed from ${d.name}.`)}
                message={<>Remove <strong>{removing?.full_name}</strong> from {d.name}? Their user account and roles are not changed.</>}
            />
        </>
    );
}
