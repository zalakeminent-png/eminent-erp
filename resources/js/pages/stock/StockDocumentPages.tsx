import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ArrowDownToLine, ArrowUpFromLine, Eye, FileText, Pencil, Plus } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { TextInput, TextareaInput } from '@/components/ui/Form';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import type { PaginationMeta } from '@/types';
import { SearchInput, filterInputClass, useListParams } from '@/pages/structure/components/ListControls';
import { DeleteButton, DocStatusBadge, ExportButton, FormField, HeaderActions, StockFilters, SelectField, SkuDetails, SkuPicker, VendorCheckBadge, VendorPicker, formatQty, useStockLookups } from './components';
import type { DocType, Sku, StockDocument, Vendor } from './types';

export interface DocConfig {
    type: DocType;
    endpoint: string;
    base: string;
    module: string;
    title: string;
    noun: string;
    description: string;
}

export const DOC_CONFIGS: Record<DocType, DocConfig> = {
    inward: {
        type: 'inward', endpoint: '/inward', base: '/inventory/inward', module: 'inward', title: 'Inward Material', noun: 'inward entry',
        description: 'Material received from vendors or production. Only posted entries add stock; rejected quantity is recorded but not added.',
    },
    outward: {
        type: 'outward', endpoint: '/outward', base: '/inventory/outward', module: 'outward', title: 'Outward Material', noun: 'outward entry',
        description: 'Material going out — production use, job work, samples, dispatch. Stock cannot go below zero.',
    },
    opening: {
        type: 'opening', endpoint: '/opening-stock', base: '/inventory/opening-stock', module: 'opening-stock', title: 'Opening Stock', noun: 'opening stock entry',
        description: 'Starting quantity of a SKU at a storage location, set once before inward/outward entries begin.',
    },
};

/** Confirmation text for deleting an entry, depending on its status. */
function deleteMessage(d: StockDocument) {
    if (d.status === 'posted') {
        return <>Delete posted entry <strong>{d.number}</strong>? Its stock effect is removed and stock is recalculated (refused if that stock was already used). The entry disappears from the list; a full copy stays in Activity Logs.</>;
    }
    if (d.status === 'reversed') {
        return <>Delete reversed entry <strong>{d.number}</strong>? It no longer affects stock; it will be hidden from the list and kept in Activity Logs.</>;
    }
    return <>Delete <strong>{d.number}</strong>? It never affected stock and will be removed.</>;
}

// ---- List -------------------------------------------------------------------

export function StockDocumentListPage({ config }: { config: DocConfig }) {
    const { can } = useAuth();
    const [query] = useSearchParams();
    const list = useListParams({ sort: 'document_date', direction: 'desc' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);
    const lookups = useStockLookups();
    const [vendor, setVendor] = useState<Vendor | null>(null);
    const vendorId = vendor?.id ?? (query.get('vendor_id') || undefined);
    const params = {
        search: debounced, status: list.get('status'), factory_id: list.get('factory_id'), vendor_id: vendorId, date_from: list.get('date_from'),
        date_to: list.get('date_to'), vendor_check: list.get('vendor_check'), sort: list.sort, direction: list.direction,
    };
    const { data, loading, error, reload } = useApi<{ data: StockDocument[]; meta: PaginationMeta }>(config.endpoint, { ...params, page: list.page, per_page: 25 });
    const t = config.type;

    return (
        <>
            <PageHeader title={config.title} description={config.description}
                actions={
                    <HeaderActions>
                        {t === 'opening' && <ButtonLink to="/inventory/stock" variant="secondary">Stock Overview</ButtonLink>}
                        {can(`${config.module}.export`) && <ExportButton url={config.endpoint} params={params} name={`${t}-material`} />}
                        {can(`${config.module}.create`) && <ButtonLink to={`${config.base}/new`} icon={<Plus className="h-4 w-4" />}>New {config.noun}</ButtonLink>}
                    </HeaderActions>
                } />
            <Card>
                <StockFilters>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search number, SKU, product, reference or job no." />
                    <select aria-label="Filter by status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        {['draft', 'posted', 'reversed', 'cancelled'].map((s) => <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>)}
                        {can(`${config.module}.delete`) && <option value="deleted">Deleted</option>}
                    </select>
                    {(lookups.data?.data.factories.length ?? 0) > 1 && (
                        <select aria-label="Filter by factory" value={list.get('factory_id')} onChange={(e) => list.update({ factory_id: e.target.value })} className={filterInputClass}>
                            <option value="">All factories</option>
                            {lookups.data?.data.factories.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                        </select>
                    )}
                    {t !== 'opening' && <div className="lg:min-w-[220px]"><VendorPicker label={t === 'inward' ? 'Vendor' : 'Party / vendor'} value={vendor} onChange={setVendor} /></div>}
                    {t === 'inward' && (
                        <select aria-label="Vendor check" value={list.get('vendor_check')} onChange={(e) => list.update({ vendor_check: e.target.value })} className={filterInputClass}>
                            <option value="">Any vendor check</option>
                            <option value="unmapped">Not mapped only</option>
                        </select>
                    )}
                    <input type="date" aria-label="From date" value={list.get('date_from')} onChange={(e) => list.update({ date_from: e.target.value })} className={filterInputClass} />
                    <input type="date" aria-label="To date" value={list.get('date_to')} onChange={(e) => list.update({ date_to: e.target.value })} className={filterInputClass} />
                </StockFilters>
                {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                    <EmptyState icon={<FileText className="h-6 w-6" />} title={list.hasFilters || vendorId ? `No ${config.noun}s match your filters` : `No ${config.noun}s yet`} />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <SortableTh label="Number" field="number" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Date" field="document_date" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th>SKU / Product</Th>
                                    {t !== 'opening' && <Th className="hidden md:table-cell">{t === 'inward' ? 'Vendor' : 'Issued to'}</Th>}
                                    <Th className="hidden lg:table-cell">Location</Th>
                                    {t === 'inward' ? <><Th className="text-right">Received</Th><Th className="hidden text-right sm:table-cell">Rejected</Th><Th className="hidden text-right sm:table-cell">Accepted</Th></>
                                        : <Th className="text-right">Quantity</Th>}
                                    <SortableTh label="Status" field="status" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th className="text-right"><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((d) => (
                                    <tr key={d.id} className="hover:bg-slate-50">
                                        <Td><Link to={`${config.base}/${d.id}`} className="font-mono text-xs font-semibold text-slate-900 hover:text-brand-700">{d.number}</Link>
                                            {d.job_no && <span className="block text-xs text-slate-500">Job {d.job_no}</span>}</Td>
                                        <Td className="whitespace-nowrap">{formatDate(d.document_date)}</Td>
                                        <Td className="min-w-[12rem] text-xs"><span className="font-mono font-semibold text-slate-900">{d.sku?.sku}</span><span className="block text-slate-600">{d.sku?.product_name}</span></Td>
                                        {t !== 'opening' && (
                                            <Td className="hidden text-xs md:table-cell">
                                                {t === 'inward' ? <>{d.vendor?.name}{d.vendor_mapped === false && <span className="block"><VendorCheckBadge mapped={false} /></span>}</>
                                                    : (d.department?.name ?? d.recipient ?? d.vendor?.name ?? '—')}
                                                {t === 'outward' && d.reference && <span className="block text-slate-500">{d.reference}</span>}
                                            </Td>
                                        )}
                                        <Td className="hidden text-xs lg:table-cell">{d.location?.code}</Td>
                                        {t === 'inward' ? (
                                            <>
                                                <Td className="text-right tabular-nums">{formatQty(d.received_qty)}</Td>
                                                <Td className="hidden text-right tabular-nums text-red-600 sm:table-cell">{d.rejected_qty !== '0' ? formatQty(d.rejected_qty) : '—'}</Td>
                                                <Td className="hidden text-right font-medium tabular-nums sm:table-cell">{formatQty(d.accepted_qty)}</Td>
                                            </>
                                        ) : <Td className="text-right font-medium tabular-nums">{formatQty(d.quantity)}</Td>}
                                        <Td><DocStatusBadge status={d.status} /></Td>
                                        <Td className="whitespace-nowrap text-right">
                                            <ButtonLink to={`${config.base}/${d.id}`} variant="ghost" size="sm" aria-label={`View ${d.number}`} icon={<Eye className="h-4 w-4" />} />
                                            {(d.allowed_actions.includes('edit') || d.allowed_actions.includes('edit_posted')) && <ButtonLink to={`${config.base}/${d.id}/edit`} variant="ghost" size="sm" aria-label={`Edit ${d.number}`} icon={<Pencil className="h-4 w-4" />} />}
                                            {d.allowed_actions.includes('delete') && <DeleteButton url={`${config.endpoint}/${d.id}`} label={d.number} onDeleted={reload}
                                                reason={d.status === 'posted' || d.status === 'reversed' ? 'required' : 'optional'} message={deleteMessage(d)} />}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={(p) => list.update({ page: String(p) })} />
                    </div>
                ) : null}
            </Card>
        </>
    );
}

// ---- Form -------------------------------------------------------------------

const today = () => new Date().toISOString().slice(0, 10);

export function StockDocumentFormPage({ config }: { config: DocConfig }) {
    const { id } = useParams();
    const [query] = useSearchParams();
    const navigate = useNavigate();
    const toast = useToast();
    const { can } = useAuth();
    const t = config.type;
    const lookups = useStockLookups();
    const existing = useApi<{ data: StockDocument }>(id ? `${config.endpoint}/${id}` : null);
    const [form, setForm] = useState({
        document_date: today(), factory_id: '', location_id: '', quantity: '', received_qty: '', rejected_qty: '', department_id: '',
        recipient: '', job_no: '', reference: '', remarks: '',
    });
    const [sku, setSku] = useState<Sku | null>(null);
    const [vendor, setVendor] = useState<Vendor | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState<'draft' | 'post' | null>(null);
    const [editReason, setEditReason] = useState('');
    const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }));

    // One factory: select it automatically.
    useEffect(() => {
        const factories = lookups.data?.data.factories.filter((f) => f.is_active) ?? [];
        if (!id && !form.factory_id && factories.length === 1) set('factory_id', String(factories[0].id));
    }, [lookups.data]); // eslint-disable-line react-hooks/exhaustive-deps

    // Prefill from the Stock Overview ("Set opening stock" / history links).
    useEffect(() => {
        const skuId = query.get('sku_id');
        if (!id && skuId) api.get<{ data: Sku }>(`/skus/${skuId}`).then((r) => setSku(r.data.data)).catch(() => undefined);
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        const d = existing.data?.data;
        if (!d) return;
        setForm({
            document_date: d.document_date, factory_id: String(d.factory?.id ?? ''), location_id: String(d.location?.id ?? ''), quantity: d.quantity === '0' ? '' : d.quantity,
            received_qty: d.received_qty === '0' ? '' : d.received_qty, rejected_qty: d.rejected_qty === '0' ? '' : d.rejected_qty, department_id: String(d.department?.id ?? ''),
            recipient: d.recipient ?? '', job_no: d.job_no ?? '', reference: d.reference ?? '', remarks: d.remarks ?? '',
        });
        setSku(d.sku);
        setVendor(d.vendor ? { ...d.vendor, contact_person: null, phone: null, email: null, city: null, address: null, notes: null, is_active: true } : null);
    }, [existing.data]);

    const factories = lookups.data?.data.factories.filter((f) => f.is_active || String(f.id) === form.factory_id) ?? [];
    const locations = (lookups.data?.data.locations ?? []).filter((l) => String(l.factory_id) === form.factory_id && (l.is_active || String(l.id) === form.location_id));
    const departments = (lookups.data?.data.departments ?? []).filter((d) => String(d.factory_id) === form.factory_id && d.is_active);
    const availability = useApi<{ data: { available: string } }>(t === 'outward' && sku && form.location_id ? '/stock/availability' : null, { sku_id: sku?.id, location_id: form.location_id });
    const mapping = useApi<{ data: Vendor[] }>(t === 'inward' && sku && vendor ? '/vendors/options' : null, { sku_id: sku?.id, search: vendor?.name });
    const vendorMapped = vendor ? mapping.data?.data.find((v) => v.id === vendor.id)?.mapped ?? null : null;

    const accepted = useMemo(() => {
        const r = Number(form.received_qty || 0);
        const j = Number(form.rejected_qty || 0);
        return Number.isFinite(r - j) ? Math.round((r - j) * 1000) / 1000 : 0;
    }, [form.received_qty, form.rejected_qty]);
    const available = availability.data?.data.available;
    const editingPosted = existing.data?.data.status === 'posted';
    // When correcting a posted outward, its own quantity is still counted as issued.
    const original = existing.data?.data;
    const ownQty = editingPosted && t === 'outward' && original?.sku?.id === sku?.id && String(original?.location?.id) === form.location_id ? Number(original?.quantity ?? 0) : 0;
    const overIssue = t === 'outward' && available !== undefined && form.quantity !== '' && Number(form.quantity) > Number(available) + ownQty;

    if (id && existing.loading && !existing.data) return <LoadingBlock />;
    if (existing.data && existing.data.data.status !== 'draft' && !(editingPosted && existing.data.data.allowed_actions.includes('edit_posted'))) {
        return <Alert tone="warning">This entry cannot be edited. <Link to={`${config.base}/${id}`} className="underline">Back to {existing.data.data.number}</Link></Alert>;
    }

    const save = async (andPost: boolean) => {
        setSaving(andPost ? 'post' : 'draft');
        setErrors({});
        setFormError(null);
        const payload: Record<string, unknown> = {
            document_date: form.document_date, factory_id: form.factory_id || null, location_id: form.location_id || null, sku_id: sku?.id ?? null,
            job_no: form.job_no || null, reference: form.reference || null, remarks: form.remarks || null,
        };
        if (t === 'inward') Object.assign(payload, { vendor_id: vendor?.id ?? null, received_qty: form.received_qty, rejected_qty: form.rejected_qty || null });
        if (t === 'outward') Object.assign(payload, { quantity: form.quantity, department_id: form.department_id || null, recipient: form.recipient || null, vendor_id: vendor?.id ?? null });
        if (t === 'opening') Object.assign(payload, { quantity: form.quantity });
        if (editingPosted) Object.assign(payload, { edit_reason: editReason, vendor_check_reason: editReason });
        try {
            const { data } = id ? await api.put<{ data: StockDocument }>(`${config.endpoint}/${id}`, payload) : await api.post<{ data: StockDocument }>(config.endpoint, payload);
            if (andPost) {
                try {
                    await api.post(`${config.endpoint}/${data.data.id}/post`);
                    toast.success(`${data.data.number} posted. Stock updated.`);
                } catch (err) {
                    const fe = fieldErrors(err);
                    toast.error(`Saved as draft ${data.data.number}, but not posted: ${Object.values(fe)[0] ?? errorMessage(err)}`);
                }
            } else {
                toast.success(editingPosted ? `${data.data.number} updated. Stock recalculated.` : `${data.data.number} saved as draft.`);
            }
            navigate(`${config.base}/${data.data.id}`);
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors(fe);
            setFormError(Object.keys(fe).length ? 'Please correct the highlighted fields.' : errorMessage(err));
        } finally {
            setSaving(null);
        }
    };

    return (
        <>
            <PageHeader title={id ? `Edit ${existing.data?.data.number}` : `New ${config.noun}`} description={config.description}
                breadcrumb={<Link to={config.base} className="hover:underline">{config.title}</Link>} />
            {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}
            {editingPosted && (
                <Card className="mb-4 border-amber-300">
                    <CardBody className="space-y-3">
                        <Alert tone="warning">This entry is <strong>posted</strong>. Saving changes corrects the stock automatically (old quantities out, new quantities in) and records the old and new values in Activity Logs. It is refused if stock would go below zero.</Alert>
                        <TextareaInput label="Reason for the change" required rows={2} value={editReason} onChange={(e) => setEditReason(e.target.value)}
                            error={errors.edit_reason} hint="e.g. Counted wrongly at the gate, wrong SKU selected." />
                    </CardBody>
                </Card>
            )}
            <Card>
                <CardBody className="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3">
                    <TextInput label="Date" type="date" required value={form.document_date} max={today()} onChange={(e) => set('document_date', e.target.value)} error={errors.document_date} />
                    {factories.length > 1 || !form.factory_id ? (
                        <SelectField label="Factory" required value={form.factory_id} onChange={(v) => { set('factory_id', v); set('location_id', ''); set('department_id', ''); }}
                            options={factories.map((f) => ({ value: f.id, label: f.name }))} error={errors.factory_id} />
                    ) : <FormField label="Factory"><p className="flex h-10 items-center text-sm text-slate-700">{factories[0]?.name}</p></FormField>}
                    <SelectField label="Storage location" required value={form.location_id} onChange={(v) => set('location_id', v)} disabled={!form.factory_id}
                        options={locations.map((l) => ({ value: l.id, label: `${l.code} — ${l.name}` }))} error={errors.location_id}
                        hint={form.factory_id && locations.length === 0 ? 'No storage locations in this factory yet (Factory Setup → Storage Locations).' : undefined} />

                    <div className="md:col-span-2 lg:col-span-3 grid grid-cols-1 gap-5 md:grid-cols-2">
                        {t === 'inward' && <VendorPicker required value={vendor} onChange={setVendor} skuId={sku?.id} error={errors.vendor_id} />}
                        <SkuPicker required value={sku} onChange={setSku} vendorId={t === 'inward' ? vendor?.id : undefined} error={errors.sku_id} />
                    </div>
                    <div className="md:col-span-2 lg:col-span-3 space-y-2">
                        <SkuDetails sku={sku} />
                        {t === 'inward' && vendorMapped === false && (
                            <Alert tone="warning">{vendor?.name} is not mapped to {sku?.sku} in Vendor Master.{' '}
                                {can('inward.override-vendor-check') ? 'You can still post it by giving a reason.' : 'Add the mapping in Vendor Master, or ask a supervisor to post it with a reason.'}</Alert>
                        )}
                    </div>

                    {t === 'inward' ? (
                        <>
                            <TextInput label="Received quantity" required inputMode="decimal" value={form.received_qty} onChange={(e) => set('received_qty', e.target.value)} error={errors.received_qty} />
                            <TextInput label="Rejected quantity" inputMode="decimal" value={form.rejected_qty} onChange={(e) => set('rejected_qty', e.target.value)} error={errors.rejected_qty} hint="Leave empty if nothing was rejected." />
                            <FormField label="Accepted quantity" hint="Received − rejected. Only this is added to stock.">
                                <p className="flex h-10 items-center rounded-md border border-slate-200 bg-slate-50 px-3 text-sm font-semibold tabular-nums text-slate-900">{formatQty(String(accepted))}{sku?.unit ? ` ${sku.unit}` : ''}</p>
                            </FormField>
                        </>
                    ) : (
                        <TextInput label={t === 'outward' ? 'Issue quantity' : 'Opening quantity'} required inputMode="decimal" value={form.quantity} onChange={(e) => set('quantity', e.target.value)}
                            error={errors.quantity ?? (overIssue ? `Only ${formatQty(available)} available at this location.` : undefined)}
                            hint={t === 'outward' ? (available !== undefined ? `Available now: ${formatQty(available)}${sku?.unit ? ` ${sku.unit}` : ''}` : 'Select the SKU and location to see available stock.') : undefined} />
                    )}

                    {t === 'outward' && (
                        <>
                            <SelectField label="Department" value={form.department_id} onChange={(v) => set('department_id', v)} options={departments.map((d) => ({ value: d.id, label: d.name }))}
                                placeholder="None" error={errors.department_id} />
                            <TextInput label="Recipient" value={form.recipient} onChange={(e) => set('recipient', e.target.value)} error={errors.recipient} placeholder="Person or customer" />
                            <VendorPicker label="Party / vendor (job work)" value={vendor} onChange={setVendor} error={errors.vendor_id} />
                        </>
                    )}
                    {t !== 'opening' && <TextInput label="Job no." value={form.job_no} onChange={(e) => set('job_no', e.target.value)} error={errors.job_no} />}
                    {t !== 'opening' && (
                        <TextInput label={t === 'inward' ? 'Vendor reference / invoice no.' : 'Purpose / reference'} value={form.reference} onChange={(e) => set('reference', e.target.value)}
                            error={errors.reference} placeholder={t === 'inward' ? 'Invoice, challan or PO no.' : 'e.g. Production, sample, challan no.'} />
                    )}
                    <div className="md:col-span-2 lg:col-span-3"><TextareaInput label="Remarks" rows={2} value={form.remarks} onChange={(e) => set('remarks', e.target.value)} error={errors.remarks} /></div>
                </CardBody>
            </Card>
            <div className="mt-4 flex flex-wrap justify-end gap-2">
                <ButtonLink to={id ? `${config.base}/${id}` : config.base} variant="secondary">Cancel</ButtonLink>
                {editingPosted ? (
                    <Button loading={saving === 'draft'} disabled={saving !== null || overIssue || editReason.trim().length < 3} onClick={() => save(false)}>Save changes &amp; recalculate stock</Button>
                ) : <Button variant="secondary" loading={saving === 'draft'} disabled={saving !== null} onClick={() => save(false)}>Save draft</Button>}
                {!editingPosted && can(`${config.module}.post`) && (t !== 'inward' || vendorMapped !== false) && (
                    <Button loading={saving === 'post'} disabled={saving !== null || overIssue} onClick={() => save(true)}>Save &amp; post</Button>
                )}
            </div>
        </>
    );
}

// ---- Details ----------------------------------------------------------------

export function StockDocumentDetailsPage({ config }: { config: DocConfig }) {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const { data, loading, error, reload } = useApi<{ data: StockDocument }>(`${config.endpoint}/${id}`);
    const [pending, setPending] = useState<'post' | 'reverse' | 'cancel' | null>(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    if (loading && !data) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Not found.'}</Alert>;
    const d = data.data;
    const t = config.type;
    const needsVendorReason = pending === 'post' && t === 'inward' && d.vendor_mapped === false;

    const run = async () => {
        if (!pending) return;
        setBusy(true);
        setActionError(null);
        try {
            const body = pending === 'post' ? { vendor_check_reason: reason || null } : { reason: reason || null };
            const { data: res } = await api.post<{ message: string }>(`${config.endpoint}/${d.id}/${pending}`, body);
            toast.success(res.message);
            setPending(null);
            setReason('');
            reload();
        } catch (err) {
            const fe = fieldErrors(err);
            setActionError(Object.values(fe)[0] ?? errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    const items = [
        { label: 'Status', value: <DocStatusBadge status={d.status} /> },
        { label: 'Date', value: formatDate(d.document_date) },
        { label: 'Factory', value: d.factory?.name },
        { label: 'Storage location', value: d.location ? `${d.location.code} — ${d.location.name}` : '—' },
        { label: 'SKU', value: <span className="font-mono">{d.sku?.sku}</span> },
        { label: 'Product name', value: d.sku?.product_name },
        { label: 'Category / material', value: [d.sku?.category, d.sku?.material, d.sku?.size].filter(Boolean).join(' · ') || '—' },
        ...(t === 'inward' ? [
            { label: 'Vendor', value: <>{d.vendor?.name} <VendorCheckBadge mapped={d.vendor_mapped} /></> },
            ...(d.vendor_check_reason ? [{ label: 'Vendor check reason', value: d.vendor_check_reason }] : []),
            { label: 'Received', value: formatQty(d.received_qty) },
            { label: 'Rejected', value: formatQty(d.rejected_qty) },
            { label: 'Accepted (added to stock)', value: <strong>{formatQty(d.accepted_qty)}</strong> },
        ] : [{ label: t === 'outward' ? 'Issue quantity' : 'Opening quantity', value: <strong>{formatQty(d.quantity)}</strong> }]),
        ...(t === 'outward' ? [
            { label: 'Department', value: d.department?.name ?? '—' },
            { label: 'Recipient', value: d.recipient ?? '—' },
            { label: 'Party / vendor', value: d.vendor?.name ?? '—' },
        ] : []),
        ...(t !== 'opening' ? [{ label: 'Job no.', value: d.job_no ?? '—' }, { label: t === 'inward' ? 'Vendor reference' : 'Purpose / reference', value: d.reference ?? '—' }] : []),
        { label: 'Remarks', value: d.remarks ?? '—' },
    ];

    const timeline = [
        { label: 'Created', who: d.created_by, when: d.created_at },
        { label: 'Posted', who: d.posted_by, when: d.posted_at },
        { label: `Reversed${d.reversal_reason ? ` — ${d.reversal_reason}` : ''}`, who: d.reversed_by, when: d.reversed_at },
        { label: `Cancelled${d.cancel_reason ? ` — ${d.cancel_reason}` : ''}`, who: d.cancelled_by, when: d.cancelled_at },
        { label: `Last edited after posting (${d.edit_count}×)`, who: d.edited_by, when: d.edited_at },
        { label: `Deleted${d.delete_reason ? ` — ${d.delete_reason}` : ''}`, who: d.deleted_by, when: d.deleted_at },
    ].filter((e) => e.when);

    return (
        <>
            <PageHeader title={`${config.title.split(' ')[0]} ${d.number}`} breadcrumb={<Link to={config.base} className="hover:underline">{config.title}</Link>}
                actions={
                    <HeaderActions>
                        {d.allowed_actions.includes('edit') && <ButtonLink to={`${config.base}/${d.id}/edit`} variant="secondary" icon={<Pencil className="h-4 w-4" />}>Edit draft</ButtonLink>}
                        {d.allowed_actions.includes('edit_posted') && <ButtonLink to={`${config.base}/${d.id}/edit`} variant="secondary" icon={<Pencil className="h-4 w-4" />}>Edit</ButtonLink>}
                        {d.allowed_actions.includes('delete') && <DeleteButton asButton url={`${config.endpoint}/${d.id}`} label={d.number} onDeleted={() => navigate(config.base)}
                            reason={d.status === 'posted' || d.status === 'reversed' ? 'required' : 'optional'} message={deleteMessage(d)} />}
                        {d.allowed_actions.includes('cancel') && <Button variant="secondary" onClick={() => setPending('cancel')}>Cancel draft</Button>}
                        {d.allowed_actions.includes('post') && <Button icon={t === 'outward' ? <ArrowUpFromLine className="h-4 w-4" /> : <ArrowDownToLine className="h-4 w-4" />} onClick={() => setPending('post')}>Post</Button>}
                        {d.allowed_actions.includes('reverse') && <Button variant="danger" onClick={() => setPending('reverse')}>Reverse</Button>}
                    </HeaderActions>
                } />
            {d.status === 'draft' && <Alert tone="info" className="mb-4">This is a draft. It does not affect stock until it is posted.</Alert>}
            {d.is_deleted && <Alert tone="danger" className="mb-4">Deleted by {d.deleted_by?.full_name ?? 'an administrator'} on {formatDateTime(d.deleted_at)}{d.delete_reason ? ` — ${d.delete_reason}` : ''}. Its stock effect was removed; it is kept here only for the record.</Alert>}
            {d.edit_count > 0 && !d.is_deleted && <Alert tone="info" className="mb-4">Edited after posting {d.edit_count} time(s); last by {d.edited_by?.full_name} on {formatDateTime(d.edited_at)}. Old and new values are in Activity Logs.</Alert>}
            {d.status === 'reversed' && <Alert tone="warning" className="mb-4">This entry was reversed; its stock effect has been undone. It stays here for the record.</Alert>}
            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2"><CardHeader title="Entry" /><CardBody><DescriptionList items={items} /></CardBody></Card>
                <Card>
                    <CardHeader title="History" />
                    <CardBody>
                        <ol className="space-y-3 text-sm">
                            {timeline.map((e) => <li key={e.label}><p className="font-medium text-slate-900">{e.label}</p><p className="text-xs text-slate-500">{e.who?.full_name ?? 'System'} · {formatDateTime(e.when)}</p></li>)}
                        </ol>
                        {(d.history ?? []).length > 0 && (
                            <div className="mt-4 border-t border-slate-200 pt-3">
                                <p className="mb-2 text-xs font-semibold uppercase text-slate-500">Stock movements</p>
                                <ul className="space-y-1 text-xs">
                                    {d.history?.map((h) => (
                                        <li key={h.id} className="flex justify-between gap-2">
                                            <span>{h.is_reversal ? 'Reversal' : 'Posted'}: {h.qty_in !== '0' && `in ${formatQty(h.qty_in)} `}{h.qty_rejected !== '0' && `rejected ${formatQty(h.qty_rejected)} `}{h.qty_out !== '0' && `out ${formatQty(h.qty_out)}`}</span>
                                            <span className="text-slate-500">balance {formatQty(h.balance_after)}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </CardBody>
                </Card>
            </div>

            {pending && (
                <Modal open title={{ post: `Post ${d.number}`, reverse: `Reverse ${d.number}`, cancel: `Cancel ${d.number}` }[pending]} onClose={() => !busy && setPending(null)}
                    footer={<><Button variant="secondary" disabled={busy} onClick={() => setPending(null)}>Back</Button>
                        <Button variant={pending === 'post' ? 'primary' : 'danger'} loading={busy} onClick={run}>{{ post: 'Post', reverse: 'Reverse', cancel: 'Cancel draft' }[pending]}</Button></>}>
                    <div className="space-y-3">
                        <p>{{
                            post: t === 'outward' ? `Post this entry? ${formatQty(d.quantity)} of ${d.sku?.sku} will be taken out of stock at ${d.location?.code}.`
                                : t === 'inward' ? `Post this entry? ${formatQty(d.accepted_qty)} of ${d.sku?.sku} will be added to stock at ${d.location?.code}${d.rejected_qty !== '0' ? ` (${formatQty(d.rejected_qty)} rejected, not added)` : ''}.`
                                    : `Post opening stock of ${formatQty(d.quantity)} for ${d.sku?.sku} at ${d.location?.code}?`,
                            reverse: 'Reversing undoes the stock effect of this entry with a correcting movement. The entry stays in history, marked as reversed.',
                            cancel: 'Cancel this draft? It never affected stock and stays in the list as cancelled.',
                        }[pending]}</p>
                        {needsVendorReason && <Alert tone="warning">{d.vendor?.name} is not mapped to {d.sku?.sku}. {d.can_override_vendor_check ? 'Give a reason to post it anyway.' : 'Add the mapping in Vendor Master first.'}</Alert>}
                        {(pending !== 'post' || (needsVendorReason && d.can_override_vendor_check)) && (
                            <TextareaInput label={pending === 'reverse' ? 'Reason (required)' : pending === 'post' ? 'Reason for posting without mapping (required)' : 'Reason (optional)'}
                                rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
                        )}
                        {actionError && <Alert tone="danger">{actionError}</Alert>}
                    </div>
                </Modal>
            )}
        </>
    );
}
