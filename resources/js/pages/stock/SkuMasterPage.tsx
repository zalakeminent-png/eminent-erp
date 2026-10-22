import { useState, type FormEvent } from 'react';
import { Eye, Package, Pencil, Plus, Power, Upload } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { TextInput } from '@/components/ui/Form';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import type { PaginationMeta } from '@/types';
import { SearchInput, filterInputClass, useListParams } from '@/pages/structure/components/ListControls';
import { DeleteButton, ExportButton, HeaderActions, ImportDialog, StockFilters, formatQty } from './components';
import type { Mapping, Sku } from './types';

interface SkuList {
    data: Sku[];
    meta: PaginationMeta;
    filters: { categories: string[]; materials: string[] };
}

export default function SkuMasterPage() {
    const { can } = useAuth();
    const toast = useToast();
    const list = useListParams({ sort: 'sku' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);
    const params = { search: debounced, category: list.get('category'), material: list.get('material'), status: list.get('status'), sort: list.sort, direction: list.direction };
    const { data, loading, error, reload } = useApi<SkuList>('/skus', { ...params, page: list.page, per_page: 25 });
    const [editing, setEditing] = useState<Sku | 'new' | null>(null);
    const [viewing, setViewing] = useState<Sku | null>(null);
    const [statusTarget, setStatusTarget] = useState<Sku | null>(null);
    const [importing, setImporting] = useState(false);
    const [busy, setBusy] = useState(false);

    const changeStatus = async () => {
        if (!statusTarget) return;
        setBusy(true);
        try {
            const { data: res } = await api.patch<{ message: string }>(`/skus/${statusTarget.id}/status`, { is_active: !statusTarget.is_active });
            toast.success(res.message);
            reload();
        } catch (err) {
            toast.error(errorMessage(err));
        } finally {
            setBusy(false);
            setStatusTarget(null);
        }
    };

    return (
        <>
            <PageHeader title="SKU Master" description="Every product you keep in stock. SKU codes are unique; deactivated SKUs keep their history but cannot be used for new entries."
                actions={
                    <HeaderActions>
                        {can('skus.export') && <ExportButton url="/skus" params={params} name="sku-master" />}
                        {can('skus.import') && <Button variant="secondary" icon={<Upload className="h-4 w-4" />} onClick={() => setImporting(true)}>Import</Button>}
                        {can('skus.create') && <Button icon={<Plus className="h-4 w-4" />} onClick={() => setEditing('new')}>Add SKU</Button>}
                    </HeaderActions>
                } />
            <Card>
                <StockFilters>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search SKU or product name" />
                    <select aria-label="Filter by category" value={list.get('category')} onChange={(e) => list.update({ category: e.target.value })} className={filterInputClass}>
                        <option value="">All categories</option>
                        {data?.filters.categories.map((c) => <option key={c} value={c}>{c}</option>)}
                    </select>
                    <select aria-label="Filter by material" value={list.get('material')} onChange={(e) => list.update({ material: e.target.value })} className={filterInputClass}>
                        <option value="">All materials</option>
                        {data?.filters.materials.map((m) => <option key={m} value={m}>{m}</option>)}
                    </select>
                    <select aria-label="Filter by status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </StockFilters>
                {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                    <EmptyState icon={<Package className="h-6 w-6" />} title={list.hasFilters ? 'No SKUs match your filters' : 'No SKUs yet'}
                        description={list.hasFilters ? undefined : 'Add SKUs one by one or import your existing SKU list from a CSV file.'} />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <SortableTh label="SKU" field="sku" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Product Name" field="product_name" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Category" field="category" sort={list.sort} direction={list.direction} onSort={list.onSort} className="hidden md:table-cell" />
                                    <SortableTh label="Material" field="material" sort={list.sort} direction={list.direction} onSort={list.onSort} className="hidden sm:table-cell" />
                                    <SortableTh label="Size" field="size" sort={list.sort} direction={list.direction} onSort={list.onSort} className="hidden lg:table-cell" />
                                    <Th className="hidden text-right lg:table-cell">Reorder level</Th>
                                    <Th>Status</Th>
                                    <Th className="text-right"><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((s) => (
                                    <tr key={s.id} className="hover:bg-slate-50">
                                        <Td className="whitespace-nowrap font-mono text-xs font-semibold text-slate-900">{s.sku}</Td>
                                        <Td className="min-w-[14rem]">{s.product_name}</Td>
                                        <Td className="hidden md:table-cell">{s.category ?? '—'}</Td>
                                        <Td className="hidden sm:table-cell">{s.material ?? '—'}</Td>
                                        <Td className="hidden lg:table-cell">{s.size ?? '—'}</Td>
                                        <Td className="hidden text-right tabular-nums lg:table-cell">{s.reorder_level ? formatQty(s.reorder_level) : <span className="text-xs text-slate-400">Not set</span>}</Td>
                                        <Td><StatusBadge active={s.is_active} /></Td>
                                        <Td className="whitespace-nowrap text-right">
                                            <Button variant="ghost" size="sm" aria-label={`View ${s.sku}`} icon={<Eye className="h-4 w-4" />} onClick={() => setViewing(s)} />
                                            {can('skus.update') && <Button variant="ghost" size="sm" aria-label={`Edit ${s.sku}`} icon={<Pencil className="h-4 w-4" />} onClick={() => setEditing(s)} />}
                                            {can('skus.delete') && (
                                                <DeleteButton url={`/skus/${s.id}`} label={s.sku} onDeleted={reload}
                                                    message={<>Permanently delete <strong>{s.sku}</strong> ({s.product_name}) and its vendor mappings? This is only possible while no stock entry uses the SKU; otherwise deactivate it.</>} />
                                            )}
                                            {can(s.is_active ? 'skus.deactivate' : 'skus.update') && (
                                                <Button variant="ghost" size="sm" aria-label={`${s.is_active ? 'Deactivate' : 'Activate'} ${s.sku}`} onClick={() => setStatusTarget(s)}
                                                    icon={<Power className={`h-4 w-4 ${s.is_active ? 'text-red-500' : 'text-emerald-600'}`} />} />
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={(p) => list.update({ page: String(p) })} />
                    </div>
                ) : null}
            </Card>

            {editing && <SkuForm sku={editing === 'new' ? null : editing} categories={data?.filters.categories ?? []} materials={data?.filters.materials ?? []}
                onClose={() => setEditing(null)} onSaved={() => { setEditing(null); reload(); }} />}
            {viewing && <SkuDetails sku={viewing} onClose={() => setViewing(null)} />}
            {importing && <ImportDialog title="Import SKUs" endpoint="/skus/import" templateUrl="/skus/template"
                columns="SKU*, Product Name*, Category, Material, Size, Unit, Reorder Level" onClose={() => setImporting(false)} onImported={reload} />}
            {statusTarget && (
                <ConfirmDialog open loading={busy} tone={statusTarget.is_active ? 'danger' : 'primary'} confirmLabel={statusTarget.is_active ? 'Deactivate' : 'Activate'}
                    title={`${statusTarget.is_active ? 'Deactivate' : 'Activate'} SKU`} onCancel={() => setStatusTarget(null)} onConfirm={changeStatus}
                    message={statusTarget.is_active
                        ? <>Deactivate <strong>{statusTarget.sku}</strong>? It stays in all history and reports but can no longer be used for new inward/outward entries or mappings.</>
                        : <>Activate <strong>{statusTarget.sku}</strong> so it can be used again?</>} />
            )}
        </>
    );
}

function SkuForm({ sku, categories, materials, onClose, onSaved }: { sku: Sku | null; categories: string[]; materials: string[]; onClose: () => void; onSaved: () => void }) {
    const toast = useToast();
    const [form, setForm] = useState({
        sku: sku?.sku ?? '', product_name: sku?.product_name ?? '', category: sku?.category ?? '', material: sku?.material ?? '',
        size: sku?.size ?? '', unit: sku?.unit ?? '', reorder_level: sku?.reorder_level ?? '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }));

    const submit = async (e?: FormEvent) => {
        e?.preventDefault();
        const client: Record<string, string> = {};
        if (!form.sku.trim()) client.sku = 'SKU is required.';
        if (!form.product_name.trim()) client.product_name = 'Product name is required.';
        if (form.reorder_level && !/^\d+(\.\d{1,3})?$/.test(form.reorder_level)) client.reorder_level = 'Enter a number, or leave empty.';
        setErrors(client);
        if (Object.keys(client).length) return;
        setSaving(true);
        try {
            const payload = { ...form, reorder_level: form.reorder_level || null };
            const { data } = sku ? await api.put<{ message: string }>(`/skus/${sku.id}`, payload) : await api.post<{ message: string }>('/skus', payload);
            toast.success(data.message);
            onSaved();
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors(fe);
            if (!Object.keys(fe).length) toast.error(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open size="lg" title={sku ? `Edit ${sku.sku}` : 'Add SKU'} onClose={onClose}
            footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button loading={saving} onClick={() => submit()}>{sku ? 'Save changes' : 'Create SKU'}</Button></>}>
            <form onSubmit={submit} noValidate className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <TextInput label="SKU" required value={form.sku} onChange={(e) => set('sku', e.target.value)} error={errors.sku} maxLength={60} hint="Unique code, e.g. ET-WTS001-316." autoFocus />
                <TextInput label="Category" value={form.category} onChange={(e) => set('category', e.target.value)} error={errors.category} list="sku-categories" placeholder="e.g. Stud" />
                <div className="sm:col-span-2">
                    <TextInput label="Product name" required value={form.product_name} onChange={(e) => set('product_name', e.target.value)} error={errors.product_name} maxLength={255} />
                </div>
                <TextInput label="Material" value={form.material} onChange={(e) => set('material', e.target.value)} error={errors.material} list="sku-materials" placeholder="e.g. 304 or 316" />
                <TextInput label="Size" value={form.size} onChange={(e) => set('size', e.target.value)} error={errors.size} placeholder="e.g. 35MM" />
                <TextInput label="Unit" value={form.unit} onChange={(e) => set('unit', e.target.value)} error={errors.unit} placeholder="e.g. PCS or KG" hint="Optional, shown next to quantities." />
                <TextInput label="Reorder level" inputMode="decimal" value={form.reorder_level} onChange={(e) => set('reorder_level', e.target.value)} error={errors.reorder_level}
                    hint="Stock at or below this shows LOW STOCK. Leave empty if not used." />
                <datalist id="sku-categories">{categories.map((c) => <option key={c} value={c} />)}</datalist>
                <datalist id="sku-materials">{materials.map((m) => <option key={m} value={m} />)}</datalist>
            </form>
        </Modal>
    );
}

function SkuDetails({ sku, onClose }: { sku: Sku; onClose: () => void }) {
    const { data, loading } = useApi<{ data: Sku & { vendors: Pick<Mapping, 'id' | 'is_active' | 'vendor'>[] } }>(`/skus/${sku.id}`);
    const d = data?.data;
    return (
        <Modal open size="lg" title={sku.sku} onClose={onClose} footer={<Button variant="secondary" onClick={onClose}>Close</Button>}>
            {loading || !d ? <LoadingBlock /> : (
                <div className="space-y-4">
                    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <div className="col-span-2 sm:col-span-3"><dt className="text-xs text-slate-500">Product name</dt><dd className="font-medium text-slate-900">{d.product_name}</dd></div>
                        <div><dt className="text-xs text-slate-500">Category</dt><dd>{d.category ?? '—'}</dd></div>
                        <div><dt className="text-xs text-slate-500">Material</dt><dd>{d.material ?? '—'}</dd></div>
                        <div><dt className="text-xs text-slate-500">Size</dt><dd>{d.size ?? '—'}</dd></div>
                        <div><dt className="text-xs text-slate-500">Unit</dt><dd>{d.unit ?? '—'}</dd></div>
                        <div><dt className="text-xs text-slate-500">Reorder level</dt><dd>{d.reorder_level ? formatQty(d.reorder_level) : 'Not set'}</dd></div>
                        <div><dt className="text-xs text-slate-500">Status</dt><dd><StatusBadge active={d.is_active} /></dd></div>
                    </dl>
                    <div>
                        <p className="mb-2 text-sm font-medium text-slate-700">Supplied by</p>
                        {d.vendors.length === 0 ? <p className="text-slate-500">No vendor is mapped to this SKU yet (Vendor Master → Vendor-SKU Mapping).</p> : (
                            <ul className="divide-y divide-slate-100 rounded-md border border-slate-200">
                                {d.vendors.map((m) => (
                                    <li key={m.id} className="flex items-center justify-between px-3 py-2">
                                        <span><span className="font-medium text-slate-900">{m.vendor.name}</span> <span className="text-xs text-slate-500">{m.vendor.code}</span></span>
                                        {!m.is_active ? <Badge>Mapping inactive</Badge> : !m.vendor.is_active ? <Badge>Vendor inactive</Badge> : null}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            )}
        </Modal>
    );
}
