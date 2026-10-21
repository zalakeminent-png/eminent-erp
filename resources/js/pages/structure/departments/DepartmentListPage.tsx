import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Building, Eye, Pencil, Plus, Power, Trash2, Users } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { ConfirmDialog } from '@/components/ui/Modal';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage } from '@/lib/api';
import type { Paginated } from '@/types';
import type { Department, Factory, StructureType } from '../types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '../components/ListControls';
import { useStatusAction } from '../components/useStatusAction';

export default function DepartmentListPage() {
    const { can } = useAuth();
    const list = useListParams({ sort: 'name' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);

    const { data, loading, error, reload } = useApi<Paginated<Department>>('/departments', {
        search: debounced, factory_id: list.get('factory_id'), type_id: list.get('type_id'), status: list.get('status'),
        sort: list.sort, direction: list.direction, page: list.page, per_page: 15,
    });
    const factories = useApi<{ data: Factory[] }>('/factories', { all: 1 });
    const types = useApi<{ data: StructureType[] }>('/structure-types', { category: 'department' });
    const status = useStatusAction<Department>('departments', 'department', () => reload(), 'Assigned employees keep their assignment.');
    const toast = useToast();
    const [deleting, setDeleting] = useState<Department | null>(null);
    const [busy, setBusy] = useState(false);

    const confirmDelete = async () => {
        if (!deleting) return;
        setBusy(true);
        try {
            // Removed from the list only after the server confirms the delete.
            const { data: result } = await api.delete<{ message: string }>(`/departments/${deleting.id}`);
            toast.success(result.message);
            setDeleting(null);
            reload();
        } catch (err) {
            toast.error(errorMessage(err));
            setDeleting(null);
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <PageHeader
                title="Departments"
                description="Organisational units within each factory."
                actions={can('departments.create') && <ButtonLink to="/factory-setup/departments/new" icon={<Plus className="h-4 w-4" />}>Add department</ButtonLink>}
            />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search code or name" />
                    <select aria-label="Filter by factory" value={list.get('factory_id')} onChange={(e) => list.update({ factory_id: e.target.value })} className={filterInputClass}>
                        <option value="">All factories</option>
                        {factories.data?.data.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                    </select>
                    <select aria-label="Filter by type" value={list.get('type_id')} onChange={(e) => list.update({ type_id: e.target.value })} className={filterInputClass}>
                        <option value="">All types</option>
                        {types.data?.data.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </select>
                    <select aria-label="Filter by status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </FilterBar>

                {error ? (
                    <div className="p-4"><Alert tone="danger">{error}</Alert></div>
                ) : loading && !data ? (
                    <LoadingBlock />
                ) : data && data.data.length === 0 ? (
                    <EmptyState icon={<Building className="h-6 w-6" />} title={list.hasFilters ? 'No departments match your filters' : 'No departments yet'} />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <SortableTh label="Code" field="code" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Department" field="name" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th className="hidden md:table-cell">Factory</Th>
                                    <Th className="hidden lg:table-cell">Manager / Supervisor</Th>
                                    <Th className="hidden text-right sm:table-cell">Employees</Th>
                                    <SortableTh label="Status" field="is_active" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th className="text-right"><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((d) => (
                                    <tr key={d.id} className="hover:bg-slate-50">
                                        <Td className="font-mono text-xs font-semibold">{d.code}</Td>
                                        <Td>
                                            <Link to={`/factory-setup/departments/${d.id}`} className="font-medium text-slate-900 hover:text-brand-700">{d.name}</Link>
                                            {d.type && <p className="text-xs text-slate-500">{d.type.name}</p>}
                                        </Td>
                                        <Td className="hidden md:table-cell">{d.factory?.name}</Td>
                                        <Td className="hidden text-xs lg:table-cell">
                                            <p>{d.manager?.full_name ?? '—'}</p>
                                            <p className="text-slate-500">{d.supervisor?.full_name ?? '—'}</p>
                                        </Td>
                                        <Td className="hidden text-right tabular-nums sm:table-cell">{d.members_count}</Td>
                                        <Td><StatusBadge active={d.is_active} /></Td>
                                        <Td className="whitespace-nowrap text-right">
                                            <div className="flex justify-end gap-1">
                                                <ButtonLink to={`/factory-setup/departments/${d.id}`} variant="ghost" size="sm" aria-label={`View ${d.name}`} icon={<Eye className="h-4 w-4" />} />
                                                <ButtonLink to={`/factory-setup/departments/${d.id}/employees`} variant="ghost" size="sm" aria-label={`Employees of ${d.name}`} icon={<Users className="h-4 w-4" />} />
                                                {can('departments.update') && <ButtonLink to={`/factory-setup/departments/${d.id}/edit`} variant="ghost" size="sm" aria-label={`Edit ${d.name}`} icon={<Pencil className="h-4 w-4" />} />}
                                                {can(d.is_active ? 'departments.deactivate' : 'departments.update') && (
                                                    <Button variant="ghost" size="sm" onClick={() => status.request(d)} aria-label={`${d.is_active ? 'Deactivate' : 'Activate'} ${d.name}`} icon={<Power className={`h-4 w-4 ${d.is_active ? 'text-red-500' : 'text-emerald-600'}`} />} />
                                                )}
                                                {can('departments.delete') && (
                                                    <Button variant="ghost" size="sm" onClick={() => setDeleting(d)} aria-label={`Delete ${d.name}`} icon={<Trash2 className="h-4 w-4 text-red-500" />} />
                                                )}
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={(p) => list.update({ page: String(p) })} />
                    </div>
                ) : null}
            </Card>
            {status.dialog}
            {deleting && (
                <ConfirmDialog
                    open
                    title="Delete department"
                    confirmLabel="Delete"
                    tone="danger"
                    loading={busy}
                    onCancel={() => setDeleting(null)}
                    onConfirm={confirmDelete}
                    message={<>Are you sure you want to permanently delete <strong>{deleting.name}</strong> ({deleting.code})? This cannot be undone. Departments with employees or linked records cannot be deleted — deactivate them instead.</>}
                />
            )}
        </>
    );
}
