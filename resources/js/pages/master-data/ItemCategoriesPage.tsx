import { useMemo, useState, type FormEvent } from 'react';
import { ChevronDown, ChevronRight, FolderTree, Pencil, Plus, Power, Trash2 } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Checkbox, SelectInput, TextareaInput, TextInput } from '@/components/ui/Form';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { cx } from '@/lib/format';
import { SearchInput, filterInputClass } from '@/pages/structure/components/ListControls';
import { useStatusAction } from '@/pages/structure/components/useStatusAction';
import { SimpleMasterPage, emptyToNull, tagsToArray, type SimpleMasterConfig } from './components/SimpleMasterPage';
import { ITEM_TYPES, type CategoryNode, type ItemAttribute, type ItemCategory } from './types';

type FormState = {
    code: string; name: string; parent_id: string; sku_prefix: string; default_item_type: string; description: string;
    attributes: { attribute_id: number; is_required: boolean }[];
};

function flatten(nodes: CategoryNode[], depth = 0, out: { node: CategoryNode; depth: number }[] = []) {
    nodes.forEach((n) => { out.push({ node: n, depth }); flatten(n.children, depth + 1, out); });
    return out;
}

function descendantIds(node: CategoryNode): number[] {
    return [node.id, ...node.children.flatMap(descendantIds)];
}

const attributeConfig: SimpleMasterConfig<ItemAttribute> = {
    title: 'Item attributes',
    description: 'Configurable attributes; assign them to categories (inherited by subcategories) and mark them required where needed.',
    endpoint: '/item-attributes',
    noun: 'attribute',
    embedded: true,
    defaultSort: 'sort_order',
    permissions: { create: 'item-categories.create', update: 'item-categories.update', deactivate: 'item-categories.update' },
    columns: [
        { key: 'code', label: 'Code', sortable: true, render: (a) => <span className="font-mono text-xs font-semibold">{a.code}</span> },
        { key: 'name', label: 'Name', sortable: true },
        { key: 'data_type', label: 'Type', render: (a) => a.data_type + (a.options.length ? `: ${a.options.join(', ')}` : '') },
    ],
    fields: [
        { name: 'code', label: 'Attribute code', type: 'text', required: true, uppercase: true, maxLength: 30 },
        { name: 'name', label: 'Attribute name', type: 'text', required: true, maxLength: 100 },
        { name: 'data_type', label: 'Data type', type: 'select', required: true, options: [{ value: 'text', label: 'Text' }, { value: 'number', label: 'Number' }, { value: 'select', label: 'Selection' }, { value: 'boolean', label: 'Yes / No' }] },
        { name: 'uom_id', label: 'Unit (numbers)', type: 'select', showWhen: (f) => f.data_type === 'number', source: { url: '/uoms', params: { status: 'active' }, map: (u) => ({ value: u.id as number, label: u.code as string }) } },
        { name: 'options', label: 'Options', type: 'tags', showWhen: (f) => f.data_type === 'select' },
        { name: 'sort_order', label: 'Sort order', type: 'number' },
    ],
    toForm: (a) => ({ code: a?.code ?? '', name: a?.name ?? '', data_type: a?.data_type ?? 'text', uom_id: a?.uom_id ?? '', options: a?.options ?? [], sort_order: a?.sort_order ?? 1 }),
    toPayload: (f) => ({ ...f, uom_id: f.data_type === 'number' && f.uom_id ? Number(f.uom_id) : null, options: f.data_type === 'select' ? tagsToArray(f.options) : null, sort_order: Number(f.sort_order) || 1 }),
};

export default function ItemCategoriesPage() {
    const { can } = useAuth();
    const toast = useToast();
    const tree = useApi<{ data: CategoryNode[] }>('/item-categories/tree');
    const attributes = useApi<{ data: ItemAttribute[] }>(can('item-categories.view') ? '/item-attributes' : null, { all: 1, status: 'active' });
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [collapsed, setCollapsed] = useState<Set<number>>(new Set());
    const [editing, setEditing] = useState<{ id: number | null } | null>(null);
    const [form, setForm] = useState<FormState | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState<CategoryNode | null>(null);
    const statusAction = useStatusAction<CategoryNode>('item-categories', 'category', () => tree.reload());

    const flat = useMemo(() => flatten(tree.data?.data ?? []), [tree.data]);
    const term = search.trim().toLowerCase();
    const matches = (n: CategoryNode): boolean =>
        (!term || n.code.toLowerCase().includes(term) || n.name.toLowerCase().includes(term)) && (!status || (status === 'active') === n.is_active);
    const visibleIds = useMemo(() => {
        const ids = new Set<number>();
        const walk = (nodes: CategoryNode[], ancestors: number[]) => nodes.forEach((n) => {
            if (matches(n)) [...ancestors, n.id].forEach((id) => ids.add(id));
            walk(n.children, [...ancestors, n.id]);
        });
        walk(tree.data?.data ?? [], []);
        return ids;
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tree.data, term, status]);

    const open = async (node: CategoryNode | null, parentId?: number) => {
        setErrors({});
        if (!node) {
            setForm({ code: '', name: '', parent_id: parentId ? String(parentId) : '', sku_prefix: '', default_item_type: '', description: '', attributes: [] });
            setEditing({ id: null });
            return;
        }
        try {
            const { data } = await api.get<{ data: ItemCategory }>(`/item-categories/${node.id}`);
            const c = data.data;
            setForm({
                code: c.code, name: c.name, parent_id: c.parent_id ? String(c.parent_id) : '', sku_prefix: c.sku_prefix ?? '',
                default_item_type: c.default_item_type ?? '', description: c.description ?? '',
                attributes: (c.attributes ?? []).map((a) => ({ attribute_id: a.id, is_required: a.is_required })),
            });
            setEditing({ id: c.id });
        } catch (err) {
            toast.error(errorMessage(err));
        }
    };

    const save = async (event: FormEvent) => {
        event.preventDefault();
        if (!form || !editing || saving) return;
        if (!form.code.trim() || !form.name.trim()) {
            setErrors({ ...(form.code.trim() ? {} : { code: 'Code is required.' }), ...(form.name.trim() ? {} : { name: 'Name is required.' }) });
            return;
        }
        setSaving(true);
        const payload = {
            ...form, parent_id: form.parent_id ? Number(form.parent_id) : null, sku_prefix: emptyToNull(form.sku_prefix),
            default_item_type: emptyToNull(form.default_item_type), description: emptyToNull(form.description),
        };
        try {
            if (editing.id) await api.put(`/item-categories/${editing.id}`, payload);
            else await api.post('/item-categories', payload);
            toast.success('Category saved.');
            setEditing(null);
            tree.reload();
        } catch (err) {
            setErrors(fieldErrors(err));
            if (!Object.keys(fieldErrors(err)).length) toast.error(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    const remove = async () => {
        if (!deleting) return;
        try {
            await api.delete(`/item-categories/${deleting.id}`);
            toast.success('Category deleted.');
            tree.reload();
        } catch (err) {
            toast.error(errorMessage(err));
        } finally {
            setDeleting(null);
        }
    };

    const editingNode = editing?.id ? flat.find((f) => f.node.id === editing.id)?.node : null;
    const excluded = new Set(editingNode ? descendantIds(editingNode) : []);

    const renderNodes = (nodes: CategoryNode[], depth: number) => (
        <ul className={depth ? 'ml-5 border-l border-slate-200 pl-2' : undefined}>
            {nodes.filter((n) => visibleIds.has(n.id)).map((n) => {
                const isOpen = term !== '' || !collapsed.has(n.id);
                return (
                    <li key={n.id}>
                        <div className="group flex min-h-11 flex-wrap items-center gap-2 rounded-md px-2 py-1.5 hover:bg-slate-50">
                            {n.children.length > 0 ? (
                                <button type="button" className="rounded p-1 text-slate-400 hover:bg-slate-200" aria-label={`${isOpen ? 'Collapse' : 'Expand'} ${n.code}`}
                                    onClick={() => setCollapsed((s) => { const next = new Set(s); next.has(n.id) ? next.delete(n.id) : next.add(n.id); return next; })}>
                                    {isOpen ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                </button>
                            ) : <span className="w-6" />}
                            <span className={cx('font-mono text-xs font-semibold', !n.is_active && 'text-slate-400 line-through')}>{n.code}</span>
                            <span className={cx('text-sm', n.is_active ? 'text-slate-800' : 'text-slate-400')}>{n.name}</span>
                            {n.sku_prefix && <Badge tone="info">SKU {n.sku_prefix}</Badge>}
                            {n.is_system && <Badge>Default</Badge>}
                            {!n.is_active && <Badge>Inactive</Badge>}
                            <span className="text-xs text-slate-400">{n.items_count} item(s)</span>
                            <span className="ml-auto flex gap-1">
                                {can('item-categories.create') && n.is_active && <Button variant="ghost" size="sm" aria-label={`Add subcategory under ${n.code}`} icon={<Plus className="h-4 w-4" />} onClick={() => open(null, n.id)} />}
                                {can('item-categories.update') && <Button variant="ghost" size="sm" aria-label={`Edit ${n.code}`} icon={<Pencil className="h-4 w-4" />} onClick={() => open(n)} />}
                                {can(n.is_active ? 'item-categories.deactivate' : 'item-categories.update') && (
                                    <Button variant="ghost" size="sm" aria-label={`${n.is_active ? 'Deactivate' : 'Activate'} ${n.code}`} icon={<Power className={`h-4 w-4 ${n.is_active ? 'text-red-500' : 'text-emerald-600'}`} />} onClick={() => statusAction.request(n)} />
                                )}
                                {can('item-categories.deactivate') && !n.is_system && n.items_count === 0 && n.children.length === 0 && (
                                    <Button variant="ghost" size="sm" aria-label={`Delete ${n.code}`} icon={<Trash2 className="h-4 w-4 text-red-500" />} onClick={() => setDeleting(n)} />
                                )}
                            </span>
                        </div>
                        {n.children.length > 0 && isOpen && renderNodes(n.children, depth + 1)}
                    </li>
                );
            })}
        </ul>
    );

    return (
        <>
            <PageHeader
                title="Item Categories"
                description="Hierarchical categories. Used categories cannot be deleted — deactivate them instead."
                actions={can('item-categories.create') && <Button icon={<Plus className="h-4 w-4" />} onClick={() => open(null)}>Add category</Button>}
            />
            <Card>
                <div className="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-[1fr_200px]">
                    <SearchInput value={search} onChange={setSearch} placeholder="Search code or name" />
                    <select aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)} className={filterInputClass}>
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div className="p-2 sm:p-4">
                    {tree.error ? <Alert tone="danger">{tree.error}</Alert> : tree.loading ? <LoadingBlock /> :
                        visibleIds.size === 0 ? <EmptyState icon={<FolderTree className="h-6 w-6" />} title="No categories match" /> : renderNodes(tree.data?.data ?? [], 0)}
                </div>
            </Card>

            {can('item-categories.view') && <div className="mt-8"><SimpleMasterPage config={attributeConfig} /></div>}

            {statusAction.dialog}
            <ConfirmDialog open={deleting !== null} tone="danger" title="Delete category" confirmLabel="Delete" onCancel={() => setDeleting(null)} onConfirm={remove}
                message={<>Permanently delete <strong>{deleting?.code}</strong>? Only unused categories can be deleted.</>} />

            <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing?.id ? 'Edit category' : 'Add category'}>
                {form && (
                    <form onSubmit={save} noValidate className="space-y-4">
                        <div className="grid max-h-[65vh] grid-cols-1 gap-4 overflow-y-auto pr-1 sm:grid-cols-2">
                            <TextInput label="Category code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} error={errors.code} required maxLength={30} />
                            <TextInput label="Category name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} required maxLength={150} />
                            <SelectInput label="Parent category" value={form.parent_id} onChange={(e) => setForm({ ...form, parent_id: e.target.value })} error={errors.parent_id} wrapperClassName="sm:col-span-2">
                                <option value="">None (top level)</option>
                                {flat.filter(({ node }) => !excluded.has(node.id) && (node.is_active || String(node.id) === form.parent_id)).map(({ node, depth }) => (
                                    <option key={node.id} value={node.id}>{'  '.repeat(depth)}{node.code} — {node.name}</option>
                                ))}
                            </SelectInput>
                            <TextInput label="SKU prefix" value={form.sku_prefix} onChange={(e) => setForm({ ...form, sku_prefix: e.target.value.toUpperCase() })} error={errors.sku_prefix} maxLength={12} hint="Inherited from the parent when empty." />
                            <SelectInput label="Default item type" value={form.default_item_type} onChange={(e) => setForm({ ...form, default_item_type: e.target.value })} error={errors.default_item_type}>
                                <option value="">Inherit / none</option>
                                {ITEM_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                            </SelectInput>
                            <TextareaInput label="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} wrapperClassName="sm:col-span-2" />
                            {(attributes.data?.data.length ?? 0) > 0 && (
                                <fieldset className="sm:col-span-2">
                                    <legend className="mb-1.5 text-sm font-medium text-slate-700">Attributes for items in this category</legend>
                                    <div className="space-y-2 rounded-md border border-slate-200 p-3">
                                        {attributes.data?.data.map((a) => {
                                            const assigned = form.attributes.find((x) => x.attribute_id === a.id);
                                            return (
                                                <div key={a.id} className="flex flex-wrap items-center justify-between gap-2">
                                                    <Checkbox label={`${a.name} (${a.data_type})`} checked={Boolean(assigned)}
                                                        onChange={(e) => setForm({ ...form, attributes: e.target.checked ? [...form.attributes, { attribute_id: a.id, is_required: false }] : form.attributes.filter((x) => x.attribute_id !== a.id) })} />
                                                    {assigned && (
                                                        <Checkbox label="Required" checked={assigned.is_required}
                                                            onChange={(e) => setForm({ ...form, attributes: form.attributes.map((x) => x.attribute_id === a.id ? { ...x, is_required: e.target.checked } : x) })} />
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                </fieldset>
                            )}
                        </div>
                        <div className="flex justify-end gap-2 border-t border-slate-100 pt-3">
                            <Button variant="secondary" onClick={() => setEditing(null)} disabled={saving}>Cancel</Button>
                            <Button type="submit" loading={saving}>Save</Button>
                        </div>
                    </form>
                )}
            </Modal>
        </>
    );
}
