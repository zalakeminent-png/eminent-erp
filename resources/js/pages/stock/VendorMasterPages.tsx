import { useState, type FormEvent } from 'react';
import { Link, NavLink, useParams } from 'react-router-dom';
import { Eye, Link2, Pencil, Plus, Power, Trash2, Truck, Upload } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { TextInput, TextareaInput } from '@/components/ui/Form';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { cx, formatDate } from '@/lib/format';
import type { PaginationMeta } from '@/types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '@/pages/structure/components/ListControls';
import { ExportButton, ImportDialog, SkuPicker, VendorPicker, formatQty } from './components';
import type { Mapping, Sku, Vendor } from './types';

function Tabs() {
    const { can } = useAuth();
    const tab = (to: string, label: string, end?: boolean) => (
        <NavLink to={to} end={end} className={({ isActive }) => cx('border-b-2 px-4 py-2 text-sm font-medium', isActive ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700')}>{label}</NavLink>
    );
    return (
        <nav className="mb-4 flex gap-2 border-b border-slate-200" aria-label="Vendor Master sections">
            {tab('/inventory/vendors', 'Vendors', true)}
            {can('vendor-skus.view') && tab('/inventory/vendors/mapping', 'Vendor-SKU Mapping')}
        </nav>
    );
}

export function VendorListPage() {
    const { can } = useAuth();
    const toast = useToast();
    const list = useListParams({ sort: 'name' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);
    const [skuFilter, setSkuFilter] = useState<Sku | null>(null);
    const params = { search: debounced, status: list.get('status'), sku_id: skuFilter?.id, sort: list.sort, direction: list.direction };
    const { data, loading, error, reload } = useApi<{ data: Vendor[]; meta: PaginationMeta }>('/vendors', { ...params, page: list.page, per_page: 25 });
    const [editing, setEditing] = useState<Vendor | 'new' | null>(null);
    const [statusTarget, setStatusTarget] = useState<Vendor | null>(null);
    const [importing, setImporting] = useState(false);
    const [busy, setBusy] = useState(false);

    const changeStatus = async () => {
        if (!statusTarget) return;
        setBusy(true);
        try {
            const { data: res } = await api.patch<{ message: string }>(`/vendors/${statusTarget.id}/status`, { is_active: !statusTarget.is_active });
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
            <PageHeader title="Vendor Master" description="Register each vendor once and record which SKUs they supply. The mapping drives the vendor check on inward entries."
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can('vendors.export') && <ExportButton url="/vendors" params={params} name="vendor-master" />}
                        {can('vendors.import') && <Button variant="secondary" icon={<Upload className="h-4 w-4" />} onClick={() => setImporting(true)}>Import</Button>}
                        {can('vendors.create') && <Button icon={<Plus className="h-4 w-4" />} onClick={() => setEditing('new')}>Add vendor</Button>}
                    </div>
                } />
            <Tabs />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search name, code, contact or city" />
                    <select aria-label="Filter by status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <div className="lg:min-w-[260px]"><SkuPicker label="Supplies SKU" value={skuFilter} onChange={setSkuFilter} /></div>
                </FilterBar>
                {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                    <EmptyState icon={<Truck className="h-6 w-6" />} title={list.hasFilters || skuFilter ? 'No vendors match your filters' : 'No vendors yet'} />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <SortableTh label="Code" field="code" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Vendor name" field="name" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Contact person" field="contact_person" sort={list.sort} direction={list.direction} onSort={list.onSort} className="hidden md:table-cell" />
                                    <SortableTh label="City" field="city" sort={list.sort} direction={list.direction} onSort={list.onSort} className="hidden md:table-cell" />
                                    <Th className="hidden lg:table-cell">Phone / Email</Th>
                                    <Th className="hidden text-right sm:table-cell">SKUs</Th>
                                    <Th>Status</Th>
                                    <Th className="text-right"><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((v) => (
                                    <tr key={v.id} className="hover:bg-slate-50">
                                        <Td className="font-mono text-xs">{v.code}</Td>
                                        <Td><Link to={`/inventory/vendors/${v.id}`} className="font-medium text-slate-900 hover:text-brand-700">{v.name}</Link>{v.notes && <span className="block max-w-xs truncate text-xs text-slate-500">{v.notes}</span>}</Td>
                                        <Td className="hidden md:table-cell">{v.contact_person ?? '—'}</Td>
                                        <Td className="hidden md:table-cell">{v.city ?? '—'}</Td>
                                        <Td className="hidden text-xs lg:table-cell">{v.phone ?? '—'}{v.email && <span className="block text-slate-500">{v.email}</span>}</Td>
                                        <Td className="hidden text-right tabular-nums sm:table-cell">{v.skus_count ?? 0}</Td>
                                        <Td><StatusBadge active={v.is_active} /></Td>
                                        <Td className="whitespace-nowrap text-right">
                                            <ButtonLink to={`/inventory/vendors/${v.id}`} variant="ghost" size="sm" aria-label={`View ${v.name}`} icon={<Eye className="h-4 w-4" />} />
                                            {can('vendors.update') && <Button variant="ghost" size="sm" aria-label={`Edit ${v.name}`} icon={<Pencil className="h-4 w-4" />} onClick={() => setEditing(v)} />}
                                            {can(v.is_active ? 'vendors.deactivate' : 'vendors.update') && (
                                                <Button variant="ghost" size="sm" aria-label={`${v.is_active ? 'Deactivate' : 'Activate'} ${v.name}`} onClick={() => setStatusTarget(v)}
                                                    icon={<Power className={`h-4 w-4 ${v.is_active ? 'text-red-500' : 'text-emerald-600'}`} />} />
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
            {editing && <VendorForm vendor={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); reload(); }} />}
            {importing && <ImportDialog title="Import vendors" endpoint="/vendors/import" templateUrl="/vendors/template"
                columns="Vendor Name*, Contact Person, Phone, Email, City, Address, Notes" onClose={() => setImporting(false)} onImported={reload} />}
            {statusTarget && (
                <ConfirmDialog open loading={busy} tone={statusTarget.is_active ? 'danger' : 'primary'} confirmLabel={statusTarget.is_active ? 'Deactivate' : 'Activate'}
                    title={`${statusTarget.is_active ? 'Deactivate' : 'Activate'} vendor`} onCancel={() => setStatusTarget(null)} onConfirm={changeStatus}
                    message={statusTarget.is_active
                        ? <>Deactivate <strong>{statusTarget.name}</strong>? Past entries are kept, but the vendor can no longer be selected for new inward entries or mappings.</>
                        : <>Activate <strong>{statusTarget.name}</strong> again?</>} />
            )}
        </>
    );
}

export function VendorForm({ vendor, onClose, onSaved }: { vendor: Vendor | null; onClose: () => void; onSaved: () => void }) {
    const toast = useToast();
    const [form, setForm] = useState({
        name: vendor?.name ?? '', contact_person: vendor?.contact_person ?? '', phone: vendor?.phone ?? '', email: vendor?.email ?? '',
        city: vendor?.city ?? '', address: vendor?.address ?? '', notes: vendor?.notes ?? '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }));

    const submit = async (e?: FormEvent) => {
        e?.preventDefault();
        if (!form.name.trim()) {
            setErrors({ name: 'Vendor name is required.' });
            return;
        }
        setSaving(true);
        try {
            const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v.trim() === '' ? null : v]));
            const { data } = vendor ? await api.put<{ message: string }>(`/vendors/${vendor.id}`, payload) : await api.post<{ message: string }>('/vendors', payload);
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
        <Modal open size="lg" title={vendor ? `Edit ${vendor.name}` : 'Add vendor'} onClose={onClose}
            footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button loading={saving} onClick={() => submit()}>{vendor ? 'Save changes' : 'Create vendor'}</Button></>}>
            <form onSubmit={submit} noValidate className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2"><TextInput label="Vendor name" required value={form.name} onChange={(e) => set('name', e.target.value)} error={errors.name} maxLength={150} autoFocus
                    hint={vendor ? `Vendor code ${vendor.code}` : 'A unique vendor code (VEN-0001…) is created automatically.'} /></div>
                <TextInput label="Contact person" value={form.contact_person} onChange={(e) => set('contact_person', e.target.value)} error={errors.contact_person} />
                <TextInput label="Phone" type="tel" value={form.phone} onChange={(e) => set('phone', e.target.value)} error={errors.phone} placeholder="+91 98765 43210" />
                <TextInput label="Email" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} error={errors.email} />
                <TextInput label="City" value={form.city} onChange={(e) => set('city', e.target.value)} error={errors.city} />
                <div className="sm:col-span-2"><TextInput label="Address" value={form.address} onChange={(e) => set('address', e.target.value)} error={errors.address} /></div>
                <div className="sm:col-span-2"><TextareaInput label="Notes" rows={2} value={form.notes} onChange={(e) => set('notes', e.target.value)} error={errors.notes} /></div>
            </form>
        </Modal>
    );
}

export function VendorDetailsPage() {
    const { id } = useParams();
    const { can } = useAuth();
    const { data, loading, error, reload } = useApi<{ data: Vendor & { mappings: Mapping[]; inward_summary: { posted_entries: number; recent: { id: number; number: string; date: string; sku: string; product_name: string; received_qty: string; rejected_qty: string; location: string }[] } } }>(`/vendors/${id}`);
    const [editing, setEditing] = useState(false);
    const [adding, setAdding] = useState(false);
    if (loading && !data) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Vendor not found.'}</Alert>;
    const v = data.data;

    return (
        <>
            <PageHeader title={v.name} description={<span className="font-mono">{v.code}</span>} breadcrumb={<Link to="/inventory/vendors" className="hover:underline">Vendor Master</Link>}
                actions={can('vendors.update') && <Button variant="secondary" icon={<Pencil className="h-4 w-4" />} onClick={() => setEditing(true)}>Edit</Button>} />
            {!v.is_active && <Alert tone="warning" className="mb-4">This vendor is inactive and cannot be used for new inward entries or mappings.</Alert>}
            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader title="Details" />
                    <CardBody>
                        <DescriptionList items={[
                            { label: 'Status', value: <StatusBadge active={v.is_active} /> },
                            { label: 'Contact person', value: v.contact_person ?? '—' },
                            { label: 'Phone', value: v.phone ?? '—' },
                            { label: 'Email', value: v.email ?? '—' },
                            { label: 'City', value: v.city ?? '—' },
                            { label: 'Address', value: v.address ?? '—' },
                            { label: 'Notes', value: v.notes ?? '—' },
                            { label: 'Posted inward entries', value: v.inward_summary.posted_entries },
                        ]} />
                    </CardBody>
                </Card>
                <Card className="lg:col-span-2">
                    <CardHeader title={`SKUs supplied (${v.mappings.filter((m) => m.is_active).length})`}
                        actions={can('vendor-skus.create') && v.is_active && <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setAdding(true)}>Map SKUs</Button>} />
                    <MappingTable rows={v.mappings} showVendor={false} onChanged={reload} empty="No SKUs mapped to this vendor yet." />
                </Card>
                <Card className="lg:col-span-3">
                    <CardHeader title="Recent inward from this vendor" actions={can('inward.view') && <Link to={`/inventory/inward?vendor_id=${v.id}`} className="text-sm text-brand-700 hover:underline">All inward entries</Link>} />
                    {v.inward_summary.recent.length === 0 ? <p className="px-4 pb-4 text-sm text-slate-500">No posted inward entries yet.</p> : (
                        <Table>
                            <thead><tr><Th>Inward no.</Th><Th>Date</Th><Th>SKU</Th><Th className="hidden md:table-cell">Location</Th><Th className="text-right">Received</Th><Th className="text-right">Rejected</Th></tr></thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {v.inward_summary.recent.map((r) => (
                                    <tr key={r.id}>
                                        <Td><Link to={`/inventory/inward/${r.id}`} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{r.number}</Link></Td>
                                        <Td className="whitespace-nowrap">{formatDate(r.date)}</Td>
                                        <Td className="text-xs"><span className="font-mono font-semibold">{r.sku}</span><span className="block text-slate-500">{r.product_name}</span></Td>
                                        <Td className="hidden md:table-cell">{r.location}</Td>
                                        <Td className="text-right tabular-nums">{formatQty(r.received_qty)}</Td>
                                        <Td className="text-right tabular-nums">{formatQty(r.rejected_qty)}</Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
            </div>
            {editing && <VendorForm vendor={v} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); reload(); }} />}
            {adding && <AddMappingDialog vendor={v} onClose={() => setAdding(false)} onSaved={() => { setAdding(false); reload(); }} />}
        </>
    );
}

export function VendorMappingPage() {
    const { can } = useAuth();
    const list = useListParams({ sort: 'vendor' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);
    const [vendor, setVendor] = useState<Vendor | null>(null);
    const [sku, setSku] = useState<Sku | null>(null);
    const params = { search: debounced, status: list.get('status'), vendor_id: vendor?.id, sku_id: sku?.id };
    const { data, loading, error, reload } = useApi<{ data: Mapping[]; meta: PaginationMeta }>('/vendor-skus', { ...params, page: list.page, per_page: 25 });
    const [adding, setAdding] = useState(false);
    const [importing, setImporting] = useState(false);

    return (
        <>
            <PageHeader title="Vendor Master" description="One vendor can supply many SKUs, and one SKU can come from many vendors. Product names come from SKU Master."
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can('vendors.export') && <ExportButton url="/vendor-skus" params={params} name="vendor-sku-mapping" />}
                        {can('vendor-skus.import') && <Button variant="secondary" icon={<Upload className="h-4 w-4" />} onClick={() => setImporting(true)}>Import mappings</Button>}
                        {can('vendor-skus.create') && <Button icon={<Link2 className="h-4 w-4" />} onClick={() => setAdding(true)}>Map SKUs to vendor</Button>}
                    </div>
                } />
            <Tabs />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search vendor, SKU or product" />
                    <div className="lg:min-w-[240px]"><VendorPicker label="Vendor" value={vendor} onChange={setVendor} /></div>
                    <div className="lg:min-w-[240px]"><SkuPicker label="SKU" value={sku} onChange={setSku} /></div>
                    <select aria-label="Filter by status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </FilterBar>
                {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <MappingTable rows={data.data} showVendor onChanged={reload} empty={list.hasFilters || vendor || sku ? 'No mappings match your filters.' : 'No vendor-SKU mappings yet.'} />
                        <Pagination meta={data.meta} onPage={(p) => list.update({ page: String(p) })} />
                    </div>
                ) : null}
            </Card>
            {adding && <AddMappingDialog vendor={null} onClose={() => setAdding(false)} onSaved={() => { setAdding(false); reload(); }} />}
            {importing && <ImportDialog title="Import vendor-SKU mappings" endpoint="/vendor-skus/import" templateUrl="/vendor-skus/template"
                columns="Vendor Name*, SKU Supplied* (both must already exist)" onClose={() => setImporting(false)} onImported={reload} />}
        </>
    );
}

function MappingTable({ rows, showVendor, onChanged, empty }: { rows: Mapping[]; showVendor: boolean; onChanged: () => void; empty: string }) {
    const { can } = useAuth();
    const toast = useToast();
    const [removing, setRemoving] = useState<Mapping | null>(null);
    const [busy, setBusy] = useState(false);

    const toggle = async (m: Mapping) => {
        try {
            const { data } = await api.put<{ message: string }>(`/vendor-skus/${m.id}`, { is_active: !m.is_active, remarks: m.remarks });
            toast.success(data.message);
            onChanged();
        } catch (err) {
            toast.error(Object.values(fieldErrors(err))[0] ?? errorMessage(err));
        }
    };
    const remove = async () => {
        if (!removing) return;
        setBusy(true);
        try {
            const { data } = await api.delete<{ message: string }>(`/vendor-skus/${removing.id}`);
            toast.success(data.message);
            onChanged();
        } catch (err) {
            toast.error(errorMessage(err));
        } finally {
            setBusy(false);
            setRemoving(null);
        }
    };

    if (rows.length === 0) return <EmptyState icon={<Link2 className="h-6 w-6" />} title={empty} />;
    return (
        <>
            <Table>
                <thead><tr>{showVendor && <Th>Vendor</Th>}<Th>SKU supplied</Th><Th>Product name</Th><Th className="hidden md:table-cell">Material</Th><Th>Status</Th><Th className="text-right"><span className="sr-only">Actions</span></Th></tr></thead>
                <tbody className="divide-y divide-slate-100 bg-white">
                    {rows.map((m) => (
                        <tr key={m.id}>
                            {showVendor && <Td><Link to={`/inventory/vendors/${m.vendor.id}`} className="font-medium text-slate-900 hover:text-brand-700">{m.vendor.name}</Link></Td>}
                            <Td className="whitespace-nowrap font-mono text-xs font-semibold">{m.sku.sku}</Td>
                            <Td className="min-w-[14rem]">{m.sku.product_name}</Td>
                            <Td className="hidden md:table-cell">{m.sku.material ?? '—'}</Td>
                            <Td>
                                {m.is_active ? <Badge tone="success">Active</Badge> : <Badge>Inactive</Badge>}
                                {(!m.vendor.is_active || !m.sku.is_active) && <span className="ml-1 text-xs text-slate-500">({!m.sku.is_active ? 'SKU' : 'vendor'} inactive)</span>}
                            </Td>
                            <Td className="whitespace-nowrap text-right">
                                {can('vendor-skus.update') && <Button variant="ghost" size="sm" onClick={() => toggle(m)}>{m.is_active ? 'Deactivate' : 'Activate'}</Button>}
                                {can('vendor-skus.delete') && <Button variant="ghost" size="sm" aria-label={`Remove ${m.vendor.name} → ${m.sku.sku}`} icon={<Trash2 className="h-4 w-4 text-red-500" />} onClick={() => setRemoving(m)} />}
                            </Td>
                        </tr>
                    ))}
                </tbody>
            </Table>
            {removing && (
                <ConfirmDialog open tone="danger" loading={busy} title="Remove mapping" confirmLabel="Remove" onCancel={() => setRemoving(null)} onConfirm={remove}
                    message={<>Remove <strong>{removing.vendor.name} → {removing.sku.sku}</strong>? Past inward entries keep their recorded vendor check; new inward entries from this vendor for this SKU will be flagged as not mapped.</>} />
            )}
        </>
    );
}

function AddMappingDialog({ vendor, onClose, onSaved }: { vendor: Vendor | null; onClose: () => void; onSaved: () => void }) {
    const toast = useToast();
    const [selectedVendor, setSelectedVendor] = useState<Vendor | null>(vendor);
    const [skus, setSkus] = useState<Sku[]>([]);
    const [picker, setPicker] = useState<Sku | null>(null);
    const [errors, setErrors] = useState<string[]>([]);
    const [saving, setSaving] = useState(false);

    const addSku = (s: Sku | null) => {
        if (s && !skus.some((x) => x.id === s.id)) setSkus((list) => [...list, s]);
        setPicker(null);
    };
    const save = async () => {
        setSaving(true);
        setErrors([]);
        try {
            const { data } = await api.post<{ message: string }>('/vendor-skus', { vendor_id: selectedVendor?.id, sku_ids: skus.map((s) => s.id) });
            toast.success(data.message);
            onSaved();
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors(Object.keys(fe).length ? Object.values(fe) : [errorMessage(err)]);
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open size="lg" title="Map SKUs to vendor" onClose={onClose}
            footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button loading={saving} disabled={!selectedVendor || skus.length === 0} onClick={save}>Save {skus.length || ''} mapping(s)</Button></>}>
            <div className="space-y-4">
                {errors.length > 0 && <Alert tone="danger"><ul className="list-disc pl-4">{errors.map((e) => <li key={e}>{e}</li>)}</ul></Alert>}
                {vendor ? <p>Vendor: <strong>{vendor.name}</strong> ({vendor.code})</p> : <VendorPicker required value={selectedVendor} onChange={setSelectedVendor} />}
                <SkuPicker label="Add SKU" value={picker} onChange={addSku} vendorId={selectedVendor?.id} />
                {skus.length > 0 && (
                    <ul className="divide-y divide-slate-100 rounded-md border border-slate-200">
                        {skus.map((s) => (
                            <li key={s.id} className="flex items-center justify-between gap-2 px-3 py-2">
                                <span><span className="font-mono text-xs font-semibold">{s.sku}</span> <span className="text-slate-600">{s.product_name}</span>
                                    {s.mapped && <Badge tone="warning" className="ml-2">Already mapped</Badge>}</span>
                                <button type="button" className="text-slate-400 hover:text-red-600" aria-label={`Remove ${s.sku}`} onClick={() => setSkus((list) => list.filter((x) => x.id !== s.id))}><Trash2 className="h-4 w-4" /></button>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-xs text-slate-500">Only active vendors and SKUs can be mapped. Product names are taken from SKU Master automatically.</p>
            </div>
        </Modal>
    );
}
