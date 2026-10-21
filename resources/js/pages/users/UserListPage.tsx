import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Eye, KeyRound, Pencil, Plus, Power, Search, Trash2, Users } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { formatDateTime, initials } from '@/lib/format';
import type { Paginated, Role, User } from '@/types';
import { useUserActions } from './useUserActions';

export default function UserListPage() {
    const { can, user: me } = useAuth();
    const [params, setParams] = useSearchParams();
    const [search, setSearch] = useState(params.get('search') ?? '');
    const debouncedSearch = useDebouncedValue(search);

    const query = {
        search: debouncedSearch,
        role_id: params.get('role_id') ?? '',
        status: params.get('status') ?? '',
        sort: params.get('sort') ?? 'name',
        direction: (params.get('direction') as 'asc' | 'desc') ?? 'asc',
        page: params.get('page') ?? '1',
        per_page: '15',
    };

    const { data, loading, error, reload } = useApi<Paginated<User>>('/users', query);
    const roles = useApi<{ data: Role[] }>('/roles', { all: 1 });
    const actions = useUserActions(() => reload());

    const update = (changes: Record<string, string>) => {
        const next = new URLSearchParams(params);
        Object.entries(changes).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)));
        if (!('page' in changes)) next.delete('page');
        setParams(next, { replace: true });
    };

    const onSort = (field: string) => {
        update({ sort: field, direction: query.sort === field && query.direction === 'asc' ? 'desc' : 'asc' });
    };

    const filtersActive = Boolean(debouncedSearch || query.role_id || query.status);

    return (
        <>
            <PageHeader
                title="Users"
                description="Manage who can access the ERP and what they can do."
                actions={can('users.create') && <ButtonLink to="/settings/users/new" icon={<Plus className="h-4 w-4" />}>Add user</ButtonLink>}
            />

            <Card>
                <div className="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-[1fr_200px_160px]">
                    <label className="relative block">
                        <span className="sr-only">Search users</span>
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => {
                                setSearch(e.target.value);
                                update({ search: e.target.value });
                            }}
                            placeholder="Search name, email or employee code"
                            className="h-10 w-full rounded-md border border-slate-300 pl-9 pr-3 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
                        />
                    </label>
                    <select
                        aria-label="Filter by role"
                        value={query.role_id}
                        onChange={(e) => update({ role_id: e.target.value })}
                        className="h-10 rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
                    >
                        <option value="">All roles</option>
                        {roles.data?.data.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                    </select>
                    <select
                        aria-label="Filter by status"
                        value={query.status}
                        onChange={(e) => update({ status: e.target.value })}
                        className="h-10 rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100"
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                {error ? (
                    <div className="p-4"><Alert tone="danger">{error}</Alert></div>
                ) : loading && !data ? (
                    <LoadingBlock />
                ) : data && data.data.length === 0 ? (
                    <EmptyState
                        icon={<Users className="h-6 w-6" />}
                        title={filtersActive ? 'No users match your filters' : 'No users yet'}
                        description={filtersActive ? 'Try a different search or clear the filters.' : 'Add the first user to give your team access.'}
                        action={filtersActive ? <Button variant="secondary" onClick={() => { setSearch(''); setParams({}, { replace: true }); }}>Clear filters</Button> : undefined}
                    />
                ) : data ? (
                    <div className={loading ? 'opacity-60 transition-opacity' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <SortableTh label="User" field="name" sort={query.sort} direction={query.direction} onSort={onSort} />
                                    <SortableTh label="Emp. code" field="employee_code" sort={query.sort} direction={query.direction} onSort={onSort} className="hidden md:table-cell" />
                                    <Th>Roles</Th>
                                    <SortableTh label="Status" field="is_active" sort={query.sort} direction={query.direction} onSort={onSort} />
                                    <SortableTh label="Last login" field="last_login_at" sort={query.sort} direction={query.direction} onSort={onSort} className="hidden lg:table-cell" />
                                    <Th className="text-right"><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((u) => {
                                    const isSelf = u.id === me?.id;
                                    return (
                                        <tr key={u.id} className="hover:bg-slate-50">
                                            <Td>
                                                <Link to={`/settings/users/${u.id}`} className="flex items-center gap-3">
                                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{initials(u.full_name)}</span>
                                                    <span className="min-w-0">
                                                        <span className="block truncate font-medium text-slate-900">{u.full_name}{isSelf && <span className="ml-1.5 text-xs font-normal text-slate-400">(you)</span>}</span>
                                                        <span className="block truncate text-xs text-slate-500">{u.email}</span>
                                                    </span>
                                                </Link>
                                            </Td>
                                            <Td className="hidden font-mono text-xs md:table-cell">{u.employee_code ?? '—'}</Td>
                                            <Td>
                                                <div className="flex flex-wrap gap-1">
                                                    {u.roles?.map((r) => <Badge key={r.id} tone={r.is_active ? 'brand' : 'neutral'}>{r.name}</Badge>)}
                                                </div>
                                            </Td>
                                            <Td><StatusBadge active={u.is_active} /></Td>
                                            <Td className="hidden whitespace-nowrap text-xs text-slate-500 lg:table-cell">{formatDateTime(u.last_login_at, 'Never')}</Td>
                                            <Td className="whitespace-nowrap text-right">
                                                <div className="flex justify-end gap-1">
                                                    <ButtonLink to={`/settings/users/${u.id}`} variant="ghost" size="sm" aria-label={`View ${u.full_name}`} icon={<Eye className="h-4 w-4" />} />
                                                    {can('users.update') && (
                                                        <ButtonLink to={`/settings/users/${u.id}/edit`} variant="ghost" size="sm" aria-label={`Edit ${u.full_name}`} icon={<Pencil className="h-4 w-4" />} />
                                                    )}
                                                    {!isSelf && can(u.is_active ? 'users.deactivate' : 'users.activate') && (
                                                        <Button variant="ghost" size="sm" onClick={() => actions.toggleStatus(u)} aria-label={`${u.is_active ? 'Deactivate' : 'Activate'} ${u.full_name}`} icon={<Power className={`h-4 w-4 ${u.is_active ? 'text-red-500' : 'text-emerald-600'}`} />} />
                                                    )}
                                                    {u.is_active && can('users.reset-password') && (
                                                        <Button variant="ghost" size="sm" onClick={() => actions.sendReset(u)} aria-label={`Send password reset to ${u.full_name}`} icon={<KeyRound className="h-4 w-4" />} />
                                                    )}
                                                    {!isSelf && can('users.delete') && (
                                                        <Button variant="ghost" size="sm" onClick={() => actions.remove(u)} aria-label={`Delete ${u.full_name}`} icon={<Trash2 className="h-4 w-4 text-red-500" />} />
                                                    )}
                                                </div>
                                            </Td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={(page) => update({ page: String(page) })} />
                    </div>
                ) : null}
            </Card>
            {actions.dialog}
        </>
    );
}
