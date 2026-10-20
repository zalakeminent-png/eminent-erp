import { useState, type FormEvent, type ReactNode } from 'react';
import { Pencil, Plus, Power } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Checkbox, SelectInput, TextareaInput, TextInput, Toggle } from '@/components/ui/Form';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import type { Paginated } from '@/types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '@/pages/structure/components/ListControls';
import { useStatusAction } from '@/pages/structure/components/useStatusAction';

export type Option = { value: string | number; label: string };

export interface FieldConfig {
    name: string;
    label: string;
    type: 'text' | 'textarea' | 'number' | 'select' | 'toggle' | 'checkbox' | 'multiselect' | 'tags';
    options?: Option[];
    /** Loads options from the API (e.g. units, departments). */
    source?: { url: string; params?: Record<string, unknown>; map: (row: Record<string, unknown>) => Option };
    required?: boolean;
    hint?: ReactNode;
    uppercase?: boolean;
    maxLength?: number;
    placeholder?: string;
    createOnly?: boolean;
    lockedOnEdit?: boolean;
    showWhen?: (form: Record<string, unknown>) => boolean;
    wide?: boolean;
}

export interface ColumnConfig<T> {
    key: string;
    label: string;
    render?: (row: T) => ReactNode;
    className?: string;
    sortable?: boolean;
}

export interface SimpleMasterConfig<T> {
    title: string;
    description?: string;
    endpoint: string;
    noun: string;
    permissions: { create: string; update: string; deactivate: string };
    columns: ColumnConfig<T>[];
    filters?: { key: string; label: string; options: Option[] }[];
    searchPlaceholder?: string;
    defaultSort?: string;
    fields: FieldConfig[];
    toForm: (row: T | null) => Record<string, unknown>;
    toPayload?: (form: Record<string, unknown>, row: T | null) => Record<string, unknown>;
    /** Optional extra content above the list (e.g. a related sub-list). */
    headerActions?: ReactNode;
    embedded?: boolean;
}

type Row = { id: number; name: string; code?: string; is_active: boolean };

function OptionsField({ field, value, onChange, error, disabled }: { field: FieldConfig; value: unknown; onChange: (v: unknown) => void; error?: string; disabled?: boolean }) {
    const { data } = useApi<{ data: Record<string, unknown>[] }>(field.source ? field.source.url : null, { all: 1, ...field.source?.params });
    const options = field.options ?? (data?.data ?? []).map(field.source!.map);

    if (field.type === 'multiselect') {
        const selected = (value as number[] | undefined) ?? [];
        return (
            <fieldset className={field.wide ? 'sm:col-span-2' : undefined}>
                <legend className="mb-1.5 text-sm font-medium text-slate-700">{field.label}</legend>
                <div className="grid max-h-48 grid-cols-1 gap-2 overflow-y-auto rounded-md border border-slate-200 p-3 sm:grid-cols-2">
                    {options.map((o) => (
                        <Checkbox
                            key={o.value}
                            label={o.label}
                            checked={selected.includes(Number(o.value))}
                            disabled={disabled}
                            onChange={(e) => onChange(e.target.checked ? [...selected, Number(o.value)] : selected.filter((v) => v !== Number(o.value)))}
                        />
                    ))}
                    {options.length === 0 && <p className="text-xs text-slate-500">No options available.</p>}
                </div>
                {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
            </fieldset>
        );
    }

    return (
        <SelectInput label={field.label} value={(value as string | number | null) ?? ''} onChange={(e) => onChange(e.target.value)} error={error} required={field.required} hint={field.hint} disabled={disabled} wrapperClassName={field.wide ? 'sm:col-span-2' : undefined}>
            {!field.required && <option value="">—</option>}
            {field.required && <option value="">Select…</option>}
            {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </SelectInput>
    );
}

/** Generic list + add/edit modal page for simple master data. */
export function SimpleMasterPage<T extends Row>({ config }: { config: SimpleMasterConfig<T> }) {
    const { can } = useAuth();
    const toast = useToast();
    const list = useListParams({ sort: config.defaultSort ?? 'code' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);

    const filterValues = Object.fromEntries((config.filters ?? []).map((f) => [f.key, list.get(f.key)]));
    const { data, loading, error, reload } = useApi<Paginated<T>>(config.endpoint, {
        search: debounced, status: list.get('status'), sort: list.sort, direction: list.direction, page: list.page, per_page: 15, ...filterValues,
    });

    const [editing, setEditing] = useState<{ row: T | null } | null>(null);
    const [form, setForm] = useState<Record<string, unknown>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const status = useStatusAction<T>(config.endpoint.replace(/^\//, ''), config.noun, () => reload());

    const open = (row: T | null) => {
        setEditing({ row });
        setForm(config.toForm(row));
        setErrors({});
    };

    const save = async (event: FormEvent) => {
        event.preventDefault();
        if (!editing || saving) return;
        const missing = config.fields.filter((f) => f.required && (f.showWhen?.(form) ?? true) && (form[f.name] === '' || form[f.name] === null || form[f.name] === undefined));
        if (missing.length) {
            setErrors(Object.fromEntries(missing.map((f) => [f.name, `${f.label} is required.`])));
            return;
        }
        setSaving(true);
        const payload = config.toPayload ? config.toPayload(form, editing.row) : form;
        try {
            if (editing.row) await api.put(`${config.endpoint}/${editing.row.id}`, payload);
            else await api.post(config.endpoint, payload);
            toast.success(`${config.noun[0].toUpperCase()}${config.noun.slice(1)} saved.`);
            setEditing(null);
            reload();
        } catch (err) {
            const fields = fieldErrors(err);
            // Fold "options.0" style keys onto their field.
            const folded: Record<string, string> = {};
            Object.entries(fields).forEach(([k, v]) => { folded[k.split('.')[0]] ??= v; });
            setErrors(folded);
            if (!Object.keys(fields).length) toast.error(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    const set = (name: string, value: unknown) => setForm((f) => ({ ...f, [name]: value }));

    const renderField = (field: FieldConfig) => {
        if (field.createOnly && editing?.row) return null;
        if (field.showWhen && !field.showWhen(form)) return null;
        const disabled = Boolean(field.lockedOnEdit && editing?.row);
        const value = form[field.name];
        const error = errors[field.name];
        const wrapper = field.wide ? 'sm:col-span-2' : undefined;

        switch (field.type) {
            case 'textarea':
                return <TextareaInput key={field.name} label={field.label} value={(value as string) ?? ''} onChange={(e) => set(field.name, e.target.value)} error={error} hint={field.hint} maxLength={field.maxLength} wrapperClassName="sm:col-span-2" />;
            case 'select':
            case 'multiselect':
                return <OptionsField key={field.name} field={field} value={value} onChange={(v) => set(field.name, v)} error={error} disabled={disabled} />;
            case 'toggle':
                return <div key={field.name} className="sm:col-span-2"><Toggle label={field.label} description={typeof field.hint === 'string' ? field.hint : undefined} checked={Boolean(value)} onChange={(v) => set(field.name, v)} /></div>;
            case 'checkbox':
                return <Checkbox key={field.name} className="sm:pt-7" label={field.label} description={field.hint} checked={Boolean(value)} onChange={(e) => set(field.name, e.target.checked)} />;
            case 'tags':
                return (
                    <TextInput
                        key={field.name}
                        label={field.label}
                        value={Array.isArray(value) ? (value as string[]).join(', ') : ((value as string) ?? '')}
                        onChange={(e) => set(field.name, e.target.value)}
                        error={error}
                        hint={field.hint ?? 'Separate values with commas.'}
                        wrapperClassName={wrapper ?? 'sm:col-span-2'}
                    />
                );
            default:
                return (
                    <TextInput
                        key={field.name}
                        label={field.label}
                        type={field.type === 'number' ? 'text' : 'text'}
                        inputMode={field.type === 'number' ? 'decimal' : undefined}
                        value={(value as string | number) ?? ''}
                        onChange={(e) => set(field.name, field.uppercase ? e.target.value.toUpperCase() : e.target.value)}
                        error={error}
                        required={field.required}
                        hint={field.hint}
                        maxLength={field.maxLength}
                        placeholder={field.placeholder}
                        disabled={disabled}
                        wrapperClassName={wrapper}
                    />
                );
        }
    };

    const content = (
        <Card>
            <FilterBar>
                <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder={config.searchPlaceholder ?? 'Search code or name'} />
                {(config.filters ?? []).map((f) => (
                    <select key={f.key} aria-label={f.label} value={list.get(f.key)} onChange={(e) => list.update({ [f.key]: e.target.value })} className={filterInputClass}>
                        <option value="">{f.label}: all</option>
                        {f.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                ))}
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
                <EmptyState title={list.hasFilters ? `No ${config.noun}s match your filters` : `No ${config.noun}s yet`} />
            ) : data ? (
                <div className={loading ? 'opacity-60' : undefined}>
                    <Table>
                        <thead>
                            <tr>
                                {config.columns.map((c) => c.sortable ? (
                                    <SortableTh key={c.key} label={c.label} field={c.key} sort={list.sort} direction={list.direction} onSort={list.onSort} className={c.className} />
                                ) : (
                                    <Th key={c.key} className={c.className}>{c.label}</Th>
                                ))}
                                <Th>Status</Th>
                                <Th className="text-right"><span className="sr-only">Actions</span></Th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 bg-white">
                            {data.data.map((row) => (
                                <tr key={row.id} className="hover:bg-slate-50">
                                    {config.columns.map((c) => (
                                        <Td key={c.key} className={c.className}>{c.render ? c.render(row) : String((row as Record<string, unknown>)[c.key] ?? '—')}</Td>
                                    ))}
                                    <Td><StatusBadge active={row.is_active} /></Td>
                                    <Td className="whitespace-nowrap text-right">
                                        <div className="flex justify-end gap-1">
                                            {can(config.permissions.update) && (
                                                <Button variant="ghost" size="sm" onClick={() => open(row)} aria-label={`Edit ${row.code ?? row.name}`} icon={<Pencil className="h-4 w-4" />} />
                                            )}
                                            {can(row.is_active ? config.permissions.deactivate : config.permissions.update) && (
                                                <Button variant="ghost" size="sm" onClick={() => status.request(row)} aria-label={`${row.is_active ? 'Deactivate' : 'Activate'} ${row.code ?? row.name}`} icon={<Power className={`h-4 w-4 ${row.is_active ? 'text-red-500' : 'text-emerald-600'}`} />} />
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
    );

    return (
        <>
            {config.embedded ? (
                <div className="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h2 className="text-base font-semibold text-slate-900">{config.title}</h2>
                        {config.description && <p className="text-sm text-slate-500">{config.description}</p>}
                    </div>
                    {can(config.permissions.create) && <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => open(null)}>Add {config.noun}</Button>}
                </div>
            ) : (
                <PageHeader
                    title={config.title}
                    description={config.description}
                    actions={<>{config.headerActions}{can(config.permissions.create) && <Button icon={<Plus className="h-4 w-4" />} onClick={() => open(null)}>Add {config.noun}</Button>}</>}
                />
            )}
            {content}
            {status.dialog}
            <Modal open={editing !== null} onClose={() => setEditing(null)} title={`${editing?.row ? 'Edit' : 'Add'} ${config.noun}`}>
                <form onSubmit={save} noValidate className="space-y-4">
                    <div className="grid max-h-[65vh] grid-cols-1 gap-4 overflow-y-auto pr-1 sm:grid-cols-2">{config.fields.map(renderField)}</div>
                    {errors.code === undefined && Object.keys(errors).some((k) => !config.fields.some((f) => f.name === k)) && (
                        <Alert tone="danger">{Object.entries(errors).filter(([k]) => !config.fields.some((f) => f.name === k)).map(([, v]) => v).join(' ')}</Alert>
                    )}
                    <div className="flex justify-end gap-2 border-t border-slate-100 pt-3">
                        <Button variant="secondary" onClick={() => setEditing(null)} disabled={saving}>Cancel</Button>
                        <Button type="submit" loading={saving}>Save</Button>
                    </div>
                </form>
            </Modal>
        </>
    );
}

export const tagsToArray = (value: unknown): string[] =>
    Array.isArray(value) ? (value as string[]) : String(value ?? '').split(',').map((s) => s.trim()).filter(Boolean);

export const emptyToNull = (value: unknown) => (value === '' || value === undefined ? null : value);
