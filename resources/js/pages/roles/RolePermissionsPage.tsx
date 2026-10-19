import { useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Lock } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, Badge, PageHeader } from '@/components/ui/Feedback';
import { Button } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { cx, humanize } from '@/lib/format';
import type { PermissionModule, Role } from '@/types';

interface Catalog {
    standard_actions: string[];
    modules: PermissionModule[];
}

/**
 * Role Permission Matrix: modules in rows, actions in columns. Cells exist
 * only where the action applies to the module; module-specific actions
 * (e.g. users.activate) are listed in the "Other" column.
 */
export default function RolePermissionsPage() {
    const { id } = useParams();
    const { can, user } = useAuth();
    const toast = useToast();
    const roleState = useApi<{ data: { role: Role; permissions: string[] } }>(`/roles/${id}/permissions`);
    const catalogState = useApi<{ data: Catalog }>('/permissions');

    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const original = roleState.data?.data.permissions;
    useEffect(() => {
        if (original) setSelected(new Set(original));
    }, [original]);

    const role = roleState.data?.data.role;
    const catalog = catalogState.data?.data;
    const mine = useMemo(() => new Set(user?.permissions ?? []), [user]);

    const holdsRole = Boolean(role && user?.roles.some((r) => r.id === role.id));
    const readOnlyReason = !role
        ? null
        : role.is_primary_admin
          ? 'The primary administrator role always holds every permission.'
          : !can('roles.assign-permissions')
            ? 'You can view this matrix but not change it.'
            : holdsRole
              ? 'You cannot change the permissions of a role assigned to you.'
              : null;
    const editable = readOnlyReason === null;

    const dirty = useMemo(() => {
        if (!original) return false;
        return original.length !== selected.size || original.some((p) => !selected.has(p));
    }, [original, selected]);

    if (roleState.loading || catalogState.loading) return <LoadingBlock />;
    if (roleState.error || catalogState.error || !role || !catalog) {
        return <Alert tone="danger">{roleState.error ?? catalogState.error ?? 'Unable to load permissions.'}</Alert>;
    }

    // You may only grant or revoke permissions you hold yourself (enforced by the API).
    const canToggle = (name: string) => editable && mine.has(name);

    const toggle = (name: string) => {
        if (!canToggle(name)) return;
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(name)) next.delete(name);
            else next.add(name);
            return next;
        });
    };

    const toggleRow = (module: PermissionModule) => {
        const names = module.permissions.map((p) => p.name).filter(canToggle);
        const allOn = names.every((n) => selected.has(n));
        setSelected((prev) => {
            const next = new Set(prev);
            names.forEach((n) => (allOn ? next.delete(n) : next.add(n)));
            return next;
        });
    };

    const save = async () => {
        setSaving(true);
        setError(null);
        try {
            const { data } = await api.put<{ message: string; data: { permissions: string[] } }>(`/roles/${role.id}/permissions`, {
                permissions: [...selected].sort(),
            });
            roleState.setData({ data: { role, permissions: data.data.permissions } });
            toast.success(data.message);
        } catch (err) {
            const fields = fieldErrors(err);
            setError(Object.values(fields)[0] ?? errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    const groups = catalog.modules.reduce<Record<string, PermissionModule[]>>((acc, m) => {
        (acc[m.group] ??= []).push(m);
        return acc;
    }, {});

    const cell = (name: string, label: string) => {
        const checked = selected.has(name);
        const disabled = !canToggle(name);
        return (
            <label className={cx('inline-flex items-center justify-center rounded p-1.5', !disabled && 'cursor-pointer hover:bg-brand-50')} title={name}>
                <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-slate-300 accent-brand-600 disabled:opacity-40"
                    checked={checked}
                    disabled={disabled}
                    onChange={() => toggle(name)}
                    aria-label={label}
                />
            </label>
        );
    };

    return (
        <>
            <PageHeader
                title={`Permissions: ${role.name}`}
                breadcrumb={
                    <>
                        <Link to="/settings/roles" className="hover:text-slate-700">Roles & Permissions</Link>
                        {' / '}
                        <Link to={`/settings/roles/${role.id}`} className="hover:text-slate-700">{role.name}</Link>
                    </>
                }
                description={`${selected.size} of ${catalog.modules.reduce((n, m) => n + m.permissions.length, 0)} permissions selected`}
                actions={
                    editable && (
                        <>
                            <Button variant="secondary" disabled={!dirty || saving} onClick={() => original && setSelected(new Set(original))}>Discard</Button>
                            <Button onClick={save} loading={saving} disabled={!dirty}>Save permissions</Button>
                        </>
                    )
                }
            />

            {readOnlyReason && (
                <Alert tone="info" className="mb-4">
                    <span className="inline-flex items-center gap-1.5"><Lock className="h-3.5 w-3.5" /> {readOnlyReason}</span>
                </Alert>
            )}
            {error && <Alert tone="danger" className="mb-4">{error}</Alert>}

            <Card>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50">
                                <th scope="col" className="sticky left-0 z-10 bg-slate-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Module</th>
                                {catalog.standard_actions.map((action) => (
                                    <th key={action} scope="col" className="px-2 py-3 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">{humanize(action)}</th>
                                ))}
                                <th scope="col" className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Other</th>
                            </tr>
                        </thead>
                        {Object.entries(groups).map(([group, modules]) => (
                            <tbody key={group} className="divide-y divide-slate-100">
                                <tr>
                                    <th colSpan={catalog.standard_actions.length + 2} scope="colgroup" className="bg-slate-50/60 px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                        {group}
                                    </th>
                                </tr>
                                {modules.map((module) => {
                                    const byAction = new Map(module.permissions.map((p) => [p.action, p]));
                                    const others = module.permissions.filter((p) => !p.standard);
                                    return (
                                        <tr key={module.key} className="hover:bg-slate-50/60">
                                            <th scope="row" className="sticky left-0 z-10 bg-white px-4 py-2.5 text-left font-medium text-slate-800">
                                                <div className="flex items-center gap-2">
                                                    {editable ? (
                                                        <button type="button" onClick={() => toggleRow(module)} className="text-left hover:text-brand-700" title="Toggle all in row">
                                                            {module.label}
                                                        </button>
                                                    ) : (
                                                        module.label
                                                    )}
                                                    {module.reserved && <Badge className="hidden sm:inline-flex">Future module</Badge>}
                                                </div>
                                            </th>
                                            {catalog.standard_actions.map((action) => {
                                                const permission = byAction.get(action);
                                                return (
                                                    <td key={action} className="px-2 py-1.5 text-center">
                                                        {permission ? cell(permission.name, `${module.label}: ${humanize(action)}`) : <span className="text-slate-300" aria-label="Not applicable">—</span>}
                                                    </td>
                                                );
                                            })}
                                            <td className="px-4 py-1.5">
                                                <div className="flex flex-wrap gap-x-4 gap-y-1">
                                                    {others.map((p) => (
                                                        <span key={p.name} className="inline-flex items-center gap-1 whitespace-nowrap text-xs text-slate-600">
                                                            {cell(p.name, `${module.label}: ${humanize(p.action)}`)}
                                                            {humanize(p.action)}
                                                        </span>
                                                    ))}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        ))}
                    </table>
                </div>
            </Card>

            {editable && dirty && (
                <div className="sticky bottom-4 mt-4 flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 shadow-sm">
                    <span>You have unsaved permission changes.</span>
                    <Button size="sm" onClick={save} loading={saving}>Save permissions</Button>
                </div>
            )}
        </>
    );
}
