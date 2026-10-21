import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody } from '@/components/ui/Card';
import { Alert, PageHeader } from '@/components/ui/Feedback';
import { Checkbox, SelectInput, TextareaInput, TextInput } from '@/components/ui/Form';
import { Button, ButtonLink } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { cx } from '@/lib/format';
import { ITEM_TYPES, type CategoryNode, type Item, type ItemAttribute, type Uom } from '../types';

type Tab = 'general' | 'specifications' | 'stock';

const TEXT_FIELDS = [
    'code', 'name', 'description', 'technical_specification', 'material', 'shape', 'size', 'length', 'width', 'height', 'diameter', 'thickness',
    'surface_finish', 'color', 'weight_per_piece', 'customer_part_number', 'internal_part_number', 'drawing_number', 'drawing_revision',
    'additional_specifications', 'min_stock_level', 'max_stock_level', 'reorder_level',
] as const;
const ID_FIELDS = ['category_id', 'material_grade_id', 'base_uom_id', 'purchase_uom_id', 'production_uom_id', 'dimension_uom_id', 'weight_uom_id', 'default_warehouse_id', 'default_location_id'] as const;
const BOOL_FIELDS = ['is_stock_item', 'track_inventory', 'allow_negative_stock', 'requires_qc', 'is_purchased', 'is_manufactured', 'is_sold'] as const;

type FormState = Record<(typeof TEXT_FIELDS)[number] | (typeof ID_FIELDS)[number] | 'item_type' | 'revision_reason', string> &
    Record<(typeof BOOL_FIELDS)[number], boolean> & { attributes: Record<number, string>; box_factor: string };

const FIELD_TAB: Record<string, Tab> = {
    code: 'general', name: 'general', category_id: 'general', item_type: 'general', material_grade_id: 'general', base_uom_id: 'general',
    purchase_uom_id: 'general', production_uom_id: 'general', description: 'general', technical_specification: 'general',
    min_stock_level: 'stock', max_stock_level: 'stock', reorder_level: 'stock', default_warehouse_id: 'stock', default_location_id: 'stock',
};

function blank(): FormState {
    const f = {} as FormState;
    TEXT_FIELDS.forEach((k) => { f[k] = ''; });
    ID_FIELDS.forEach((k) => { f[k] = ''; });
    BOOL_FIELDS.forEach((k) => { f[k] = false; });
    return { ...f, item_type: '', revision_reason: '', is_stock_item: true, track_inventory: true, attributes: {}, box_factor: '' };
}

function flatten(nodes: CategoryNode[], depth = 0, parent: number | null = null, out: { node: CategoryNode; depth: number; parent: number | null }[] = []) {
    nodes.forEach((n) => { out.push({ node: n, depth, parent }); flatten(n.children, depth + 1, n.id, out); });
    return out;
}

export default function ItemFormPage() {
    const { id } = useParams();
    const isEdit = Boolean(id);
    const navigate = useNavigate();
    const toast = useToast();
    const { can } = useAuth();

    const existing = useApi<{ data: Item; meta: { locked_fields: string[]; lock_reasons: string[] } }>(isEdit ? `/items/${id}` : null);
    const uoms = useApi<{ data: Uom[] }>('/uoms', { all: 1, status: 'active' });
    const tree = useApi<{ data: CategoryNode[] }>('/item-categories/tree');
    const grades = useApi<{ data: { id: number; code: string; name: string }[] }>('/material-grades', { all: 1, status: 'active' });
    const attributes = useApi<{ data: (ItemAttribute & { categories?: { id: number; is_required: boolean }[] })[] }>('/item-attributes', { all: 1, status: 'active' });

    const [tab, setTab] = useState<Tab>('general');
    const [form, setForm] = useState<FormState>(blank);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    // Storage locations of all accessible factories (warehouses are internal).
    const locations = useApi<{ data: { id: number; code: string; name: string; factory?: { name: string } }[] }>(can('locations.view') ? '/locations' : null, { selectable: 1, per_page: 200 });
    const nextCode = useApi<{ data: { mode: string; example_prefix: string; suggested_item_type: string | null } }>(!isEdit && form.category_id ? '/items/next-code' : null, { category_id: form.category_id, material_grade_id: form.material_grade_id || undefined });

    useEffect(() => {
        const item = existing.data?.data;
        if (!item) return;
        const f = blank();
        TEXT_FIELDS.forEach((k) => { f[k] = (item[k] as string | null) ?? ''; });
        ID_FIELDS.forEach((k) => { f[k] = item[k] ? String(item[k]) : ''; });
        BOOL_FIELDS.forEach((k) => { f[k] = Boolean(item[k]); });
        f.item_type = item.item_type;
        f.attributes = Object.fromEntries((item.attribute_values ?? []).map((v) => [v.attribute_id, v.value]));
        setForm(f);
    }, [existing.data]);

    // Suggested item type follows the category for new items.
    useEffect(() => {
        const suggested = nextCode.data?.data.suggested_item_type;
        if (!isEdit && suggested) setForm((f) => (f.item_type ? f : { ...f, item_type: suggested }));
    }, [nextCode.data, isEdit]);

    const flat = useMemo(() => flatten(tree.data?.data ?? []), [tree.data]);
    const lineage = useMemo(() => {
        const ids: number[] = [];
        let cursor = flat.find((f) => String(f.node.id) === form.category_id);
        while (cursor && ids.length < 50) { ids.push(cursor.node.id); cursor = flat.find((f) => f.node.id === cursor!.parent); }
        return ids;
    }, [flat, form.category_id]);
    const applicableAttributes = (attributes.data?.data ?? []).filter((a) => a.categories?.some((c) => lineage.includes(c.id)));
    const isRequired = (a: (typeof applicableAttributes)[number]) => Boolean(a.categories?.some((c) => lineage.includes(c.id) && c.is_required));

    if (existing.loading || uoms.loading || tree.loading) return <LoadingBlock />;
    if (existing.error) return <Alert tone="danger">{existing.error}</Alert>;

    const locked = new Set(existing.data?.meta.locked_fields ?? []);
    const allUoms = uoms.data?.data ?? [];
    const set = <K extends keyof FormState>(key: K, value: FormState[K]) => setForm((f) => ({ ...f, [key]: value }));
    const text = (key: (typeof TEXT_FIELDS)[number], label: string, extra: Record<string, unknown> = {}) => (
        <TextInput label={label} value={form[key]} onChange={(e) => set(key, key === 'code' ? e.target.value.toUpperCase() : e.target.value)} error={errors[key]} {...extra} />
    );
    const uomSelect = (key: (typeof ID_FIELDS)[number], label: string, filter: (u: Uom) => boolean = () => true, extra: Record<string, unknown> = {}) => (
        <SelectInput label={label} value={form[key]} onChange={(e) => set(key, e.target.value)} error={errors[key]} {...extra}>
            <option value="">{extra.required ? 'Select unit' : '—'}</option>
            {allUoms.filter(filter).map((u) => <option key={u.id} value={u.id}>{u.code} — {u.name}</option>)}
        </SelectInput>
    );
    const base = allUoms.find((u) => String(u.id) === form.base_uom_id);
    const needsBox = !isEdit && form.purchase_uom_id && base && !base.universally_convertible && form.purchase_uom_id !== form.base_uom_id;

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        if (saving) return;
        const clientErrors: Record<string, string> = {};
        if (!form.name.trim()) clientErrors.name = 'Item name is required.';
        if (!form.category_id) clientErrors.category_id = 'Select a category.';
        if (!form.base_uom_id) clientErrors.base_uom_id = 'Select the base unit.';
        if (isEdit && !form.code.trim()) clientErrors.code = 'Item code is required.';
        setErrors(clientErrors);
        if (Object.keys(clientErrors).length) { setTab('general'); return; }

        const payload: Record<string, unknown> = {};
        TEXT_FIELDS.forEach((k) => { payload[k] = form[k].trim() === '' ? null : form[k].trim(); });
        ID_FIELDS.forEach((k) => { payload[k] = form[k] ? Number(form[k]) : null; });
        BOOL_FIELDS.forEach((k) => { payload[k] = form[k]; });
        payload.item_type = form.item_type || null;
        payload.attributes = form.attributes;
        if (isEdit) payload.revision_reason = form.revision_reason || null;
        if (needsBox && form.box_factor) payload.uom_conversions = [{ uom_id: Number(form.purchase_uom_id), factor: form.box_factor }];

        setSaving(true);
        setFormError(null);
        try {
            if (isEdit) {
                await api.put(`/items/${id}`, payload);
                toast.success('Item updated.');
                navigate(`/master-data/items/${id}`);
            } else {
                const { data } = await api.post<{ data: Item; message: string }>('/items', payload);
                toast.success(data.message);
                navigate(`/master-data/items/${data.data.id}`);
            }
        } catch (err) {
            const fields = fieldErrors(err);
            setErrors(fields);
            const first = Object.keys(fields)[0];
            if (first) setTab(FIELD_TAB[first] ?? (first.startsWith('attributes') || first.startsWith('uom_conversions') ? 'specifications' : 'specifications'));
            else setFormError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    const tabs: { key: Tab; label: string }[] = [{ key: 'general', label: 'General' }, { key: 'specifications', label: 'Specifications' }, { key: 'stock', label: 'Stock Configuration' }];
    const tabHasError = (t: Tab) => Object.keys(errors).some((k) => (FIELD_TAB[k] ?? 'specifications') === t);

    return (
        <form onSubmit={onSubmit} noValidate>
            <PageHeader
                title={isEdit ? `Edit item: ${existing.data?.data.code ?? ''}` : 'Add item'}
                breadcrumb={<Link to="/master-data/items" className="hover:text-slate-700">Items / Products</Link>}
                description={isEdit ? `Revision ${existing.data?.data.revision}. Specification changes create a new revision.` : undefined}
            />
            {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}
            {locked.size > 0 && <Alert tone="info" className="mb-4">Code, base unit and item type are locked because {existing.data?.meta.lock_reasons.join(' and ')}.</Alert>}

            <div role="tablist" className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
                {tabs.map((t) => (
                    <button key={t.key} type="button" role="tab" aria-selected={tab === t.key} onClick={() => setTab(t.key)}
                        className={cx('-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium', tab === t.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700')}>
                        {t.label}{tabHasError(t.key) && <span className="ml-1.5 inline-block h-2 w-2 rounded-full bg-red-500" aria-label="has errors" />}
                    </button>
                ))}
            </div>

            <fieldset disabled={saving}>
                {tab === 'general' && (
                    <Card><CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {text('code', 'Item code / SKU', {
                            maxLength: 50, disabled: locked.has('code'), required: isEdit,
                            hint: !isEdit ? (nextCode.data?.data.mode === 'auto' ? `Generated automatically (${nextCode.data.data.example_prefix}-…).` : `Leave blank to generate${nextCode.data ? ` (${nextCode.data.data.example_prefix}-…)` : ''}.`) : undefined,
                        })}
                        {text('name', 'Item name', { required: true, maxLength: 200, wrapperClassName: 'lg:col-span-2' })}
                        <SelectInput label="Item category" value={form.category_id} onChange={(e) => set('category_id', e.target.value)} error={errors.category_id} required>
                            <option value="">Select category</option>
                            {flat.filter(({ node }) => node.is_active || String(node.id) === form.category_id).map(({ node, depth }) => (
                                <option key={node.id} value={node.id}>{'  '.repeat(depth)}{node.name}</option>
                            ))}
                        </SelectInput>
                        <SelectInput label="Item type" value={form.item_type} onChange={(e) => set('item_type', e.target.value)} error={errors.item_type} disabled={locked.has('item_type')}>
                            <option value="">From category</option>
                            {ITEM_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                        </SelectInput>
                        <SelectInput label="Material grade (optional)" value={form.material_grade_id} onChange={(e) => set('material_grade_id', e.target.value)} error={errors.material_grade_id}>
                            <option value="">None</option>
                            {grades.data?.data.map((g) => <option key={g.id} value={g.id}>{g.code} — {g.name}</option>)}
                        </SelectInput>
                        {uomSelect('base_uom_id', 'Base unit of measurement', () => true, { required: true, disabled: locked.has('base_uom_id'), hint: 'Inventory is kept in this unit.' })}
                        {uomSelect('purchase_uom_id', 'Default purchase UOM')}
                        {uomSelect('production_uom_id', 'Default production UOM')}
                        {needsBox && (
                            <TextInput label={`1 ${allUoms.find((u) => String(u.id) === form.purchase_uom_id)?.code} = ? ${base?.code}`} value={form.box_factor}
                                onChange={(e) => set('box_factor', e.target.value)} error={errors['uom_conversions.0.factor']} inputMode="decimal" hint="Item-specific conversion for the purchase unit." />
                        )}
                        <TextareaInput label="Description" value={form.description} onChange={(e) => set('description', e.target.value)} error={errors.description} wrapperClassName="sm:col-span-2 lg:col-span-3" />
                        <TextareaInput label="Technical specification" value={form.technical_specification} onChange={(e) => set('technical_specification', e.target.value)} wrapperClassName="sm:col-span-2 lg:col-span-3" />
                    </CardBody></Card>
                )}

                {tab === 'specifications' && (
                    <Card><CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        {text('material', 'Material')}
                        {text('shape', 'Shape')}
                        {text('size', 'Size')}
                        {text('surface_finish', 'Surface finish')}
                        {text('length', 'Length', { inputMode: 'decimal' })}
                        {text('width', 'Width', { inputMode: 'decimal' })}
                        {text('height', 'Height', { inputMode: 'decimal' })}
                        {text('diameter', 'Diameter', { inputMode: 'decimal' })}
                        {text('thickness', 'Thickness', { inputMode: 'decimal' })}
                        {uomSelect('dimension_uom_id', 'Dimension unit', (u) => u.dimension === 'length')}
                        {text('weight_per_piece', 'Weight per piece', { inputMode: 'decimal' })}
                        {uomSelect('weight_uom_id', 'Weight unit', (u) => u.dimension === 'mass')}
                        {text('color', 'Color')}
                        {text('internal_part_number', 'Internal part no.')}
                        {text('customer_part_number', 'Customer part no.')}
                        {text('drawing_number', 'Drawing number')}
                        {text('drawing_revision', 'Drawing revision')}
                        <TextareaInput label="Additional specifications" value={form.additional_specifications} onChange={(e) => set('additional_specifications', e.target.value)} wrapperClassName="sm:col-span-2 lg:col-span-4" />
                        {applicableAttributes.length > 0 && (
                            <div className="sm:col-span-2 lg:col-span-4">
                                <p className="mb-2 text-sm font-semibold text-slate-800">Category attributes</p>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    {applicableAttributes.map((a) => a.data_type === 'select' || a.data_type === 'boolean' ? (
                                        <SelectInput key={a.id} label={a.name} required={isRequired(a)} value={form.attributes[a.id] ?? ''} error={errors[`attributes.${a.id}`]}
                                            onChange={(e) => set('attributes', { ...form.attributes, [a.id]: e.target.value })}>
                                            <option value="">—</option>
                                            {(a.data_type === 'boolean' ? ['1', '0'] : a.options).map((o) => <option key={o} value={o}>{a.data_type === 'boolean' ? (o === '1' ? 'Yes' : 'No') : o}</option>)}
                                        </SelectInput>
                                    ) : (
                                        <TextInput key={a.id} label={a.name} required={isRequired(a)} value={form.attributes[a.id] ?? ''} error={errors[`attributes.${a.id}`]}
                                            inputMode={a.data_type === 'number' ? 'decimal' : undefined} onChange={(e) => set('attributes', { ...form.attributes, [a.id]: e.target.value })} />
                                    ))}
                                </div>
                            </div>
                        )}
                        {isEdit && (
                            <TextInput label="Reason for change (revision note)" value={form.revision_reason} onChange={(e) => set('revision_reason', e.target.value)} maxLength={255}
                                hint="Recorded in the item's revision history when specifications change." wrapperClassName="sm:col-span-2 lg:col-span-4" />
                        )}
                    </CardBody></Card>
                )}

                {tab === 'stock' && (
                    <Card><CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <Alert tone="info" className="sm:col-span-2 lg:col-span-3">Configuration only. Opening stock and balances are handled by the Inventory module.</Alert>
                        {text('min_stock_level', `Minimum stock${base ? ` (${base.code})` : ''}`, { inputMode: 'decimal' })}
                        {text('max_stock_level', `Maximum stock${base ? ` (${base.code})` : ''}`, { inputMode: 'decimal' })}
                        {text('reorder_level', `Reorder level${base ? ` (${base.code})` : ''}`, { inputMode: 'decimal' })}
                        {locations.data && (
                            <SelectInput label="Default location" value={form.default_location_id} onChange={(e) => set('default_location_id', e.target.value)} error={errors.default_location_id ?? errors.default_warehouse_id}>
                                <option value="">None</option>
                                {locations.data.data.map((l) => <option key={l.id} value={l.id}>{l.code} — {l.name}{l.factory ? ` (${l.factory.name})` : ''}</option>)}
                            </SelectInput>
                        )}
                        <div className="grid grid-cols-1 gap-3 sm:col-span-2 sm:grid-cols-2 lg:col-span-3 lg:grid-cols-4">
                            <Checkbox label="Stock item" checked={form.is_stock_item} onChange={(e) => set('is_stock_item', e.target.checked)} />
                            <Checkbox label="Track inventory" checked={form.track_inventory} onChange={(e) => set('track_inventory', e.target.checked)} />
                            <Checkbox label="Allow negative stock" description="Off by default." checked={form.allow_negative_stock} onChange={(e) => set('allow_negative_stock', e.target.checked)} />
                            <Checkbox label="Requires QC" checked={form.requires_qc} onChange={(e) => set('requires_qc', e.target.checked)} />
                            <Checkbox label="Purchased" checked={form.is_purchased} onChange={(e) => set('is_purchased', e.target.checked)} />
                            <Checkbox label="Manufactured" description="Allows BOM and routing." checked={form.is_manufactured} onChange={(e) => set('is_manufactured', e.target.checked)} />
                            <Checkbox label="Sold" checked={form.is_sold} onChange={(e) => set('is_sold', e.target.checked)} />
                        </div>
                    </CardBody></Card>
                )}
            </fieldset>

            <div className="sticky bottom-0 mt-6 flex justify-end gap-2 border-t border-slate-200 bg-slate-50/95 py-3 backdrop-blur">
                <ButtonLink to={isEdit ? `/master-data/items/${id}` : '/master-data/items'} variant="secondary">Cancel</ButtonLink>
                <Button type="submit" loading={saving}>{isEdit ? 'Save changes' : 'Create item'}</Button>
            </div>
        </form>
    );
}
