import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Eye, FileText, Paperclip, Pencil, Plus, Trash2, Upload } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { TextInput, TextareaInput, Toggle } from '@/components/ui/Form';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { formatDate, formatDateTime, humanize } from '@/lib/format';
import type { Paginated } from '@/types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '@/pages/structure/components/ListControls';
import { ItemPicker, downloadFile, type ItemOption as SharedItemOption } from '@/pages/master-data/components/shared';
import type { CodedRef } from '@/pages/master-data/types';
import {
    ApprovalTimeline, DocumentStatusBadge, FieldShell, LocationSelect, Qty, QuantityInput, ReasonSelect, StatusSelect, StockAvailability,
    FactorySelect, SupplierPicker, UomSelect, requestKey, statusLabel,
} from '../components';
import type { DocumentLine, InventoryDocument, StockStatus } from '../types';
import { ACTION_LABELS, ACTION_TEXT, type DocConfig } from './config';

type ItemOption = SharedItemOption;

// ---- List -------------------------------------------------------------------

export function InventoryDocumentListPage({ config }: { config: DocConfig }) {
    const { can } = useAuth();
    const list = useListParams({ sort: 'created_at', direction: 'desc' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);
    const { data, loading, error } = useApi<Paginated<InventoryDocument>>(`/inventory/${config.endpoint}`, {
        search: debounced, status: list.get('status'), factory_id: list.get('factory_id'), date_from: list.get('date_from'), date_to: list.get('date_to'),
        sort: list.sort, direction: list.direction, page: list.page, per_page: 15,
    });
    const showSupplier = config.type === 'grn';

    return (
        <>
            <PageHeader title={config.title} description={config.description}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {config.related && can(config.related.permission) && <ButtonLink to={config.related.to} variant="secondary">{config.related.label}</ButtonLink>}
                        {can(config.createPermission) && <ButtonLink to={`${config.base}/new`} icon={<Plus className="h-4 w-4" />}>New {config.noun}</ButtonLink>}
                    </div>
                } />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search number, reference or item" />
                    <select aria-label="Filter by status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        {config.statuses.map((s) => <option key={s} value={s}>{humanize(s)}</option>)}
                    </select>
                    <input type="date" aria-label="From date" value={list.get('date_from')} onChange={(e) => list.update({ date_from: e.target.value })} className={filterInputClass} />
                    <input type="date" aria-label="To date" value={list.get('date_to')} onChange={(e) => list.update({ date_to: e.target.value })} className={filterInputClass} />
                </FilterBar>
                {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                    <EmptyState icon={<FileText className="h-6 w-6" />} title={list.hasFilters ? `No ${config.noun}s match your filters` : `No ${config.noun}s yet`} />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <SortableTh label="Number" field="document_number" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Date" field="document_date" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th className="hidden md:table-cell">{showSupplier ? 'Supplier' : 'From → To'}</Th>
                                    <Th className="hidden lg:table-cell">Lines</Th>
                                    <SortableTh label="Status" field="status" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th className="text-right"><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((d) => (
                                    <tr key={d.id} className="hover:bg-slate-50">
                                        <Td>
                                            <Link to={`${config.base}/${d.id}`} className="font-mono text-xs font-semibold text-slate-900 hover:text-brand-700">{d.document_number}</Link>
                                            {d.reference_number && <span className="block text-xs text-slate-500">Ref {d.reference_number}</span>}
                                        </Td>
                                        <Td className="whitespace-nowrap">{formatDate(d.document_date)}</Td>
                                        <Td className="hidden text-xs md:table-cell">
                                            {showSupplier ? d.supplier?.name : (
                                                <>{d.source_location ? d.source_location.code : d.factory?.name ?? '—'}{d.destination_location && ` → ${d.destination_location.code}`}</>
                                            )}
                                            {d.reason_code && <span className="block text-slate-500">{d.reason_code.name}</span>}
                                        </Td>
                                        <Td className="hidden tabular-nums lg:table-cell">{d.line_count}</Td>
                                        <Td><DocumentStatusBadge status={d.status} /></Td>
                                        <Td className="whitespace-nowrap text-right">
                                            <ButtonLink to={`${config.base}/${d.id}`} variant="ghost" size="sm" aria-label={`View ${d.document_number}`} icon={<Eye className="h-4 w-4" />} />
                                            {d.status === 'draft' && can(config.createPermission) && (
                                                <ButtonLink to={`${config.base}/${d.id}/edit`} variant="ghost" size="sm" aria-label={`Edit ${d.document_number}`} icon={<Pencil className="h-4 w-4" />} />
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
        </>
    );
}

// ---- Editor -----------------------------------------------------------------

interface LineState {
    key: string;
    item: ItemOption | null;
    quantity: string;
    uom_id: string;
    source_location_id: string;
    destination_location_id: string;
    from_status: string;
    to_status: string;
    unit_cost: string;
    condition: string;
    inspection_required: boolean;
    remarks: string;
}

interface HeaderState {
    document_date: string;
    factory_id: string;
    supplier: CodedRef | null;
    supplier_invoice_number: string;
    reference_number: string;
    source_location_id: string;
    destination_location_id: string;
    requires_handover: boolean;
    original_document_id: string;
    reason_code_id: string;
    decision: string;
    remarks: string;
}

const today = () => new Date().toISOString().slice(0, 10);

function blankLine(config: DocConfig): LineState {
    return {
        key: requestKey(), item: null, quantity: '', uom_id: '', source_location_id: '', destination_location_id: '',
        from_status: config.line.fromStatuses?.[0] ?? 'available', to_status: config.line.toStatuses?.[0] ?? '', unit_cost: '',
        condition: 'good', inspection_required: false, remarks: '',
    };
}

function lineFromDocument(l: DocumentLine): LineState {
    return {
        key: String(l.id), item: l.item ? { ...l.item, base_uom: l.base_uom ?? undefined, is_active: true, item_type: '' } : null, quantity: l.quantity, uom_id: String(l.uom?.id ?? ''),
        source_location_id: String(l.source_location?.id ?? ''), destination_location_id: String(l.destination_location?.id ?? ''),
        from_status: l.from_status ?? 'available', to_status: l.to_status ?? '', unit_cost: l.unit_cost ?? '', condition: l.condition ?? 'good',
        inspection_required: l.to_status === 'qc_pending', remarks: l.remarks ?? '',
    };
}

export function InventoryDocumentEditorPage({ config }: { config: DocConfig }) {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const existing = useApi<{ data: InventoryDocument }>(id ? `/inventory/${config.endpoint}/${id}` : null);
    const [header, setHeader] = useState<HeaderState>({
        document_date: today(), factory_id: '', supplier: null, supplier_invoice_number: '', reference_number: '', source_location_id: '',
        destination_location_id: '', requires_handover: true, original_document_id: '', reason_code_id: '', decision: 'release', remarks: '',
    });
    const [lines, setLines] = useState<LineState[]>([blankLine(config)]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const issues = useApi<Paginated<InventoryDocument>>(config.type === 'material_return' ? '/inventory/material-issues' : null, { status: 'posted', per_page: 100 });

    useEffect(() => {
        const d = existing.data?.data;
        if (!d) return;
        setHeader({
            document_date: d.document_date, factory_id: String(d.factory?.id ?? ''), supplier: d.supplier ?? null,
            supplier_invoice_number: d.supplier_invoice_number ?? '', reference_number: d.reference_number ?? '',
            source_location_id: String(d.source_location?.id ?? ''), destination_location_id: String(d.destination_location?.id ?? ''),
            requires_handover: d.requires_handover, original_document_id: String(d.original_document?.id ?? ''), reason_code_id: String(d.reason_code?.id ?? ''),
            decision: d.decision ?? 'release', remarks: d.remarks ?? '',
        });
        setLines((d.lines ?? []).map(lineFromDocument));
    }, [existing.data]);

    const set = <K extends keyof HeaderState>(key: K, value: HeaderState[K]) => setHeader((h) => ({ ...h, [key]: value }));
    const setLine = (index: number, changes: Partial<LineState>) => setLines((ls) => ls.map((l, i) => (i === index ? { ...l, ...changes } : l)));

    const payload = () => {
        const base: Record<string, unknown> = { document_date: header.document_date, remarks: header.remarks || null, reference_number: header.reference_number || null };
        const t = config.type;
        if (t === 'grn') Object.assign(base, { supplier_id: header.supplier?.id ?? null, supplier_invoice_number: header.supplier_invoice_number || null });
        if (t === 'transfer') Object.assign(base, { source_location_id: header.source_location_id || null, destination_location_id: header.destination_location_id || null, requires_handover: header.requires_handover });
        if (t === 'material_issue') Object.assign(base, { source_location_id: header.source_location_id || null, destination_location_id: header.destination_location_id || null });
        if (t === 'material_return') Object.assign(base, { original_document_id: header.original_document_id || null, destination_location_id: header.destination_location_id || null, reason_code_id: header.reason_code_id || null });
        if (t === 'adjustment') Object.assign(base, { reason_code_id: header.reason_code_id || null });
        if (t === 'scrap') Object.assign(base, { reason_code_id: header.reason_code_id || null });
        if (t === 'qc_disposition') Object.assign(base, { decision: header.decision, reason_code_id: header.reason_code_id || null });
        base.lines = lines.map((l) => ({
            item_id: l.item?.id ?? null, quantity: l.quantity, uom_id: l.uom_id || l.item?.base_uom?.id || null, remarks: l.remarks || null,
            ...(config.line.destinationLocation ? { destination_location_id: l.destination_location_id || null } : {}),
            ...(config.line.sourceLocation ? { source_location_id: l.source_location_id || null } : {}),
            ...(config.line.fromStatuses ? { from_status: l.from_status } : {}),
            ...(config.line.toStatuses ? { to_status: l.to_status } : {}),
            ...(config.line.unitCost ? { unit_cost: l.unit_cost || null } : {}),
            ...(config.line.condition ? { condition: l.condition, inspection_required: l.inspection_required } : {}),
        }));
        return base;
    };

    const save = async (andPost = false) => {
        setSaving(true);
        setErrors({});
        setFormError(null);
        try {
            const { data } = id
                ? await api.put<{ data: InventoryDocument }>(`/inventory/${config.endpoint}/${id}`, payload())
                : await api.post<{ data: InventoryDocument }>(`/inventory/${config.endpoint}`, payload());
            if (andPost) {
                try {
                    await api.post(`/inventory/${config.endpoint}/${data.data.id}/post`, { remarks: header.remarks });
                    toast.success(`${data.data.document_number} posted.`);
                } catch (err) {
                    toast.error(`Saved as draft, but posting failed: ${errorMessage(err)}`);
                }
            } else {
                toast.success(`${data.data.document_number} saved.`);
            }
            navigate(`${config.base}/${data.data.id}`);
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors(fe);
            setFormError(Object.keys(fe).length ? 'Please correct the highlighted fields.' : errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    if (id && existing.loading && !existing.data) return <LoadingBlock />;
    if (existing.data && existing.data.data.status !== 'draft') {
        return <Alert tone="warning">Only draft documents can be edited. <Link to={`${config.base}/${id}`} className="underline">Back to document</Link></Alert>;
    }

    const lineFactory = config.type === 'grn' || config.type === 'adjustment' ? header.factory_id : undefined;
    const lineErrors = Object.keys(errors).filter((k) => k.startsWith('lines') && !/^lines\.\d+\./.test(k)).map((k) => errors[k]);
    const err = (key: string) => errors[key];

    return (
        <>
            <PageHeader title={id ? `Edit ${existing.data?.data.document_number}` : `New ${config.noun}`} description={config.description}
                breadcrumb={<Link to={config.base} className="hover:underline">{config.title}</Link>} />
            {formError && <Alert tone="danger" className="mb-4">{formError}{err('quantity') && <span className="block">{err('quantity')}</span>}{err('location') && <span className="block">{err('location')}</span>}</Alert>}

            <Card className="mb-4">
                <CardHeader title="Details" />
                <CardBody className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <TextInput label="Date" type="date" value={header.document_date} max={today()} onChange={(e) => set('document_date', e.target.value)} error={err('document_date')} />
                    {(config.type === 'grn' || config.type === 'adjustment') && (
                        <FactorySelect label={config.type === 'grn' ? 'Receiving factory' : 'Factory'} allowAll="All factories" value={header.factory_id} onChange={(v) => set('factory_id', v)} />
                    )}
                    {config.type === 'grn' && (
                        <>
                            <SupplierPicker value={header.supplier} onChange={(s) => set('supplier', s)} error={err('supplier_id')} />
                            <TextInput label="Supplier invoice / challan no." value={header.supplier_invoice_number} onChange={(e) => set('supplier_invoice_number', e.target.value)} error={err('supplier_invoice_number')} />
                        </>
                    )}
                    {(config.type === 'transfer' || config.type === 'material_issue') && (
                        <>
                            <LocationSelect label="Source location" required value={header.source_location_id} onChange={(v) => set('source_location_id', v)} error={err('source_location_id')} />
                            <LocationSelect label={config.type === 'material_issue' ? 'Production / WIP location' : 'Destination location'} required
                                purpose={config.type === 'material_issue' ? 'production' : undefined}
                                value={header.destination_location_id} onChange={(v) => set('destination_location_id', v)} error={err('destination_location_id')} />
                        </>
                    )}
                    {config.type === 'material_return' && (
                        <>
                            <FieldShell label="Original material issue" required error={err('original_document_id')}>
                                <select aria-label="Original material issue" value={header.original_document_id} onChange={(e) => set('original_document_id', e.target.value)} className={`${filterInputClass} h-11`}>
                                    <option value="">Select posted issue</option>
                                    {issues.data?.data.map((d) => <option key={d.id} value={d.id}>{d.document_number} — {d.destination_location?.code} ({formatDate(d.document_date)})</option>)}
                                </select>
                            </FieldShell>
                            <LocationSelect label="Return to store location" value={header.destination_location_id} placeholder="Original source location"
                                onChange={(v) => set('destination_location_id', v)} error={err('destination_location_id')} />
                        </>
                    )}
                    {(config.type === 'material_return' || config.type === 'adjustment' || config.type === 'scrap' || config.type === 'qc_disposition') && (
                        <ReasonSelect required={config.type === 'adjustment' || config.type === 'scrap'}
                            category={{ material_return: 'return,general', adjustment: 'adjustment,general', scrap: 'scrap,rejection,general', qc_disposition: 'qc,rejection,general' }[config.type]}
                            value={header.reason_code_id} onChange={(v) => set('reason_code_id', v)} error={err('reason_code_id')} />
                    )}
                    {config.type === 'qc_disposition' && (
                        <FieldShell label="Decision" required error={err('decision')}>
                            <div className="grid grid-cols-3 gap-2">
                                {[['release', 'Accept / release'], ['hold', 'Hold'], ['reject', 'Reject']].map(([v, l]) => (
                                    <button key={v} type="button" onClick={() => set('decision', v)} aria-pressed={header.decision === v}
                                        className={`h-11 rounded-md border text-sm font-medium ${header.decision === v ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-300 bg-white text-slate-700'}`}>{l}</button>
                                ))}
                            </div>
                        </FieldShell>
                    )}
                    {config.type !== 'qc_disposition' && (
                        <TextInput label={config.type === 'material_issue' ? 'Production reference (optional)' : 'Reference number'} value={header.reference_number}
                            onChange={(e) => set('reference_number', e.target.value)} error={err('reference_number')} />
                    )}
                    {config.type === 'transfer' && (
                        <div className="sm:col-span-2 lg:col-span-3">
                            <Toggle checked={header.requires_handover} onChange={(v) => set('requires_handover', v)} label="Requires physical handover"
                                description="On: issue → in transit → receive at destination (partial receipts allowed). Off: an authorised one-step move." />
                        </div>
                    )}
                    <div className="sm:col-span-2 lg:col-span-3">
                        <TextareaInput label={config.type === 'qc_disposition' ? 'Remarks / inspection reference' : 'Remarks'} required={config.type === 'qc_disposition'} rows={2}
                            value={header.remarks} onChange={(e) => set('remarks', e.target.value)} error={err('remarks')} />
                    </div>
                </CardBody>
            </Card>

            <Card>
                <CardHeader title="Lines" description={config.line.signedQuantity ? 'Enter + to add stock, − to remove stock.' : 'Quantities are converted to each item’s base unit on save.'}
                    actions={<Button variant="secondary" size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setLines((ls) => [...ls, blankLine(config)])}>Add line</Button>} />
                {lineErrors.length > 0 && <div className="px-4 pt-4"><Alert tone="danger">{lineErrors.join(' ')}</Alert></div>}
                <div className="divide-y divide-slate-100">
                    {lines.map((line, i) => {
                        const le = (field: string) => errors[`lines.${i}.${field}`];
                        const checkLocation = config.line.sourceLocation ? line.source_location_id : config.type === 'transfer' || config.type === 'material_issue' ? header.source_location_id : null;
                        return (
                            <div key={line.key} className="grid grid-cols-2 gap-3 p-4 md:grid-cols-12 md:items-start">
                                <div className="col-span-2 md:col-span-4">
                                    <ItemPicker label={`Line ${i + 1} item`} compact value={line.item} error={le('item_id')}
                                        onChange={(item) => setLine(i, { item, uom_id: item?.base_uom ? String(item.base_uom.id) : '' })} />
                                    {config.line.availability && (
                                        <div className="mt-1"><StockAvailability itemId={line.item?.id} locationId={checkLocation} status={line.from_status} /></div>
                                    )}
                                </div>
                                <div className="md:col-span-2">
                                    <span className="mb-1.5 block text-xs font-medium text-slate-600">Quantity</span>
                                    <QuantityInput label={`Line ${i + 1} quantity`} value={line.quantity} allowNegative={config.line.signedQuantity} onChange={(v) => setLine(i, { quantity: v })} error={le('quantity')} />
                                </div>
                                <div className="md:col-span-1">
                                    <span className="mb-1.5 block text-xs font-medium text-slate-600">UOM</span>
                                    <UomSelect label={`Line ${i + 1} UOM`} value={line.uom_id} baseUomId={line.item?.base_uom?.id} onChange={(v) => setLine(i, { uom_id: v })} error={le('uom_id')} />
                                </div>
                                {config.line.sourceLocation && (
                                    <div className="col-span-2 md:col-span-2">
                                        <span className="mb-1.5 block text-xs font-medium text-slate-600">Location</span>
                                        <LocationSelect compact label={`Line ${i + 1} location`} factoryId={lineFactory} value={line.source_location_id} onChange={(v) => setLine(i, { source_location_id: v })} error={le('source_location_id')} />
                                    </div>
                                )}
                                {config.line.destinationLocation && (
                                    <div className="col-span-2 md:col-span-2">
                                        <span className="mb-1.5 block text-xs font-medium text-slate-600">{config.line.destinationLocation === 'optional' ? 'Move to (optional)' : 'Put-away location'}</span>
                                        <LocationSelect compact label={`Line ${i + 1} destination`} factoryId={lineFactory} placeholder={config.line.destinationLocation === 'optional' ? 'Same location' : undefined}
                                            value={line.destination_location_id} onChange={(v) => setLine(i, { destination_location_id: v })} error={le('destination_location_id')} />
                                    </div>
                                )}
                                {config.line.fromStatuses && (
                                    <div className="md:col-span-2">
                                        <span className="mb-1.5 block text-xs font-medium text-slate-600">{config.line.toStatuses ? 'From status' : 'Stock status'}</span>
                                        <StatusSelect compact label={`Line ${i + 1} status`} options={config.line.fromStatuses} value={line.from_status} onChange={(v) => setLine(i, { from_status: v as StockStatus })} error={le('from_status')} />
                                    </div>
                                )}
                                {config.line.toStatuses && (
                                    <div className="md:col-span-2">
                                        <span className="mb-1.5 block text-xs font-medium text-slate-600">To status</span>
                                        <StatusSelect compact label={`Line ${i + 1} target status`} options={config.line.toStatuses} value={line.to_status} onChange={(v) => setLine(i, { to_status: v })} error={le('to_status')} />
                                    </div>
                                )}
                                {config.line.unitCost && (
                                    <div className="md:col-span-1">
                                        <span className="mb-1.5 block text-xs font-medium text-slate-600">Unit cost</span>
                                        <QuantityInput label={`Line ${i + 1} unit cost`} value={line.unit_cost} onChange={(v) => setLine(i, { unit_cost: v })} error={le('unit_cost')} />
                                    </div>
                                )}
                                {config.line.condition && (
                                    <div className="md:col-span-3">
                                        <span className="mb-1.5 block text-xs font-medium text-slate-600">Condition</span>
                                        <div className="flex items-center gap-3">
                                            <StatusSelect compact label={`Line ${i + 1} condition`} options={['good', 'damaged']} value={line.condition} onChange={(v) => setLine(i, { condition: v })} error={le('condition')} />
                                            <label className="flex items-center gap-1.5 whitespace-nowrap text-xs text-slate-600">
                                                <input type="checkbox" checked={line.inspection_required} onChange={(e) => setLine(i, { inspection_required: e.target.checked })} /> Needs QC
                                            </label>
                                        </div>
                                    </div>
                                )}
                                <div className="flex items-end justify-end md:col-span-1">
                                    <Button variant="ghost" size="sm" aria-label={`Remove line ${i + 1}`} disabled={lines.length === 1} onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}
                                        icon={<Trash2 className="h-4 w-4 text-red-500" />} />
                                </div>
                            </div>
                        );
                    })}
                </div>
            </Card>

            <div className="sticky bottom-0 mt-4 flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50/95 py-3 backdrop-blur">
                <ButtonLink to={id ? `${config.base}/${id}` : config.base} variant="secondary">Cancel</ButtonLink>
                {config.type === 'qc_disposition' ? (
                    <Button loading={saving} onClick={() => save(true)}>Record decision</Button>
                ) : (
                    <Button loading={saving} onClick={() => save(false)}>Save draft</Button>
                )}
            </div>
        </>
    );
}

// ---- Details ----------------------------------------------------------------

export function InventoryDocumentDetailsPage({ config }: { config: DocConfig }) {
    const { id } = useParams();
    const { can } = useAuth();
    const toast = useToast();
    const { data, loading, error, reload } = useApi<{ data: InventoryDocument }>(`/inventory/${config.endpoint}/${id}`);
    const [pending, setPending] = useState<string | null>(null);
    const [remarks, setRemarks] = useState('');
    const [receipts, setReceipts] = useState<Record<number, string>>({});
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string[]>([]);
    const [receiveKey, setReceiveKey] = useState(requestKey());
    const doc = data?.data;

    const actions = useMemo(() => (doc?.allowed_actions ?? []).filter((a) => a !== 'edit'), [doc]);

    if (loading && !doc) return <LoadingBlock />;
    if (error || !doc) return <Alert tone="danger">{error ?? 'Not found.'}</Alert>;

    const run = async () => {
        if (!pending) return;
        setBusy(true);
        setActionError([]);
        try {
            const body: Record<string, unknown> = { remarks: remarks || null };
            if (pending === 'receive') {
                body.idempotency_key = receiveKey;
                body.receipts = Object.entries(receipts).filter(([, q]) => q && Number(q) > 0).map(([lineId, q]) => ({ line_id: Number(lineId), base_quantity: q }));
            }
            await api.post(`/inventory/${config.endpoint}/${doc.id}/${pending}`, body);
            toast.success(`${doc.document_number}: ${ACTION_LABELS[pending] ?? pending} done.`);
            setPending(null);
            setRemarks('');
            setReceipts({});
            setReceiveKey(requestKey());
            reload();
        } catch (err) {
            const fe = fieldErrors(err);
            setActionError(Object.keys(fe).length ? Object.values(fe) : [errorMessage(err)]);
        } finally {
            setBusy(false);
        }
    };

    const needsRemarks = pending === 'cancel' || (pending === 'close' && doc.status === 'partially_received');
    const lines = doc.lines ?? [];
    const t = config.type;

    const info = [
        { label: 'Status', value: <DocumentStatusBadge status={doc.status} /> },
        { label: 'Date', value: formatDate(doc.document_date) },
        doc.factory && { label: 'Factory', value: doc.factory.name },
        doc.supplier && { label: 'Supplier', value: doc.supplier.name },
        doc.supplier_invoice_number && { label: 'Invoice / challan', value: doc.supplier_invoice_number },
        doc.source_location && { label: 'From', value: `${doc.source_location.code} — ${doc.source_location.name}` },
        doc.destination_location && { label: 'To', value: `${doc.destination_location.code} — ${doc.destination_location.name}` },
        doc.department && { label: 'Department', value: doc.department.name },
        doc.original_document && { label: 'Original issue', value: <Link className="text-brand-700 hover:underline" to={`/inventory/material-issues/${doc.original_document.id}`}>{doc.original_document.document_number}</Link> },
        doc.reason_code && { label: 'Reason', value: doc.reason_code.name },
        doc.decision && { label: 'Decision', value: humanize(doc.decision) },
        t === 'transfer' && { label: 'Handover', value: doc.requires_handover ? 'Issue → in transit → receive' : 'One-step transfer' },
        doc.reference_number && { label: 'Reference', value: doc.reference_number },
        doc.requested_by && { label: 'Requested by', value: doc.requested_by.full_name },
        doc.approved_by && { label: 'Approved by', value: `${doc.approved_by.full_name} · ${formatDateTime(doc.approved_at)}` },
        doc.issued_by && { label: 'Issued by', value: `${doc.issued_by.full_name} · ${formatDateTime(doc.issued_at)}` },
        doc.received_by && { label: 'Received by', value: `${doc.received_by.full_name} · ${formatDateTime(doc.received_at)}` },
        doc.posted_by && { label: 'Posted by', value: `${doc.posted_by.full_name} · ${formatDateTime(doc.posted_at)}` },
        doc.remarks && { label: 'Remarks', value: doc.remarks },
    ].filter(Boolean) as { label: string; value: React.ReactNode }[];

    return (
        <>
            <PageHeader title={`${config.noun.charAt(0).toUpperCase()}${config.noun.slice(1)} ${doc.document_number}`} breadcrumb={<Link to={config.base} className="hover:underline">{config.title}</Link>}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {doc.allowed_actions?.includes('edit') && <ButtonLink to={`${config.base}/${doc.id}/edit`} variant="secondary" icon={<Pencil className="h-4 w-4" />}>Edit</ButtonLink>}
                        {actions.map((a) => (
                            <Button key={a} variant={a === 'cancel' ? 'danger' : a === 'close' ? 'secondary' : 'primary'} onClick={() => { setPending(a); setActionError([]); }}>
                                {t === 'scrap' && a === 'approve' ? 'Approve & post' : ACTION_LABELS[a] ?? humanize(a)}
                            </Button>
                        ))}
                    </div>
                } />
            {actionError.length > 0 && !pending && <Alert tone="danger" className="mb-4">{actionError.join(' ')}</Alert>}
            {doc.status === 'partially_received' && (
                <Alert tone="warning" className="mb-4">Part of this transfer is still in transit. Missing quantities stay visible as In Transit at the destination until they are received or corrected by an authorised adjustment.</Alert>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader title="Document" />
                        <CardBody><DescriptionList items={info} /></CardBody>
                    </Card>
                    <Card>
                        <CardHeader title="Lines" />
                        <Table>
                            <thead>
                                <tr>
                                    <Th>#</Th>
                                    <Th>Item</Th>
                                    <Th className="text-right">Quantity</Th>
                                    <Th className="hidden text-right md:table-cell">Base qty</Th>
                                    <Th className="hidden md:table-cell">Location</Th>
                                    <Th>Status</Th>
                                    {t === 'transfer' && <Th className="text-right">Received</Th>}
                                    {t === 'adjustment' && <Th className="hidden text-right lg:table-cell">System → New</Th>}
                                    {(t === 'grn' || t === 'adjustment') && <Th className="hidden text-right lg:table-cell">Unit cost</Th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {lines.map((l) => (
                                    <tr key={l.id}>
                                        <Td className="text-xs text-slate-500">{l.line_no}</Td>
                                        <Td><span className="font-mono text-xs font-semibold">{l.item?.code}</span><span className="block text-xs text-slate-500">{l.item?.name}</span>{l.remarks && <span className="block text-xs italic text-slate-500">{l.remarks}</span>}</Td>
                                        <Td className="text-right"><Qty value={l.quantity} uom={l.uom?.code} /></Td>
                                        <Td className="hidden text-right md:table-cell"><Qty value={l.base_quantity} uom={l.base_uom?.code} /></Td>
                                        <Td className="hidden text-xs md:table-cell">
                                            {l.source_location?.code}
                                            {l.source_location && l.destination_location && l.source_location.id !== l.destination_location.id && ' → '}
                                            {l.destination_location && (!l.source_location || l.source_location.id !== l.destination_location.id) && l.destination_location.code}
                                        </Td>
                                        <Td className="text-xs">
                                            {l.from_status && l.to_status && l.from_status !== l.to_status ? `${statusLabel(l.from_status)} → ${statusLabel(l.to_status)}` : statusLabel(l.to_status ?? l.from_status)}
                                            {l.condition && <span className="block text-slate-500">{humanize(l.condition)}</span>}
                                        </Td>
                                        {t === 'transfer' && <Td className="text-right"><Qty value={l.received_base_quantity} /><span className="block text-xs text-slate-500">of {l.base_quantity}</span></Td>}
                                        {t === 'adjustment' && <Td className="hidden text-right text-xs lg:table-cell"><Qty value={l.system_base_quantity} /> → <Qty value={l.new_base_quantity} /></Td>}
                                        {(t === 'grn' || t === 'adjustment') && <Td className="hidden text-right lg:table-cell">{l.unit_cost ? <Qty value={l.unit_cost} /> : <span className="text-xs text-slate-400">—</span>}</Td>}
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </Card>
                    <Attachments doc={doc} config={config} canUpload={can(config.createPermission) && ['draft', 'submitted', 'approved'].includes(doc.status)} onChanged={reload} />
                </div>
                <Card>
                    <CardHeader title="Approval timeline" />
                    <CardBody><ApprovalTimeline events={doc.events ?? []} /></CardBody>
                </Card>
            </div>

            {pending && (
                <Modal open title={`${t === 'scrap' && pending === 'approve' ? 'Approve & post' : ACTION_LABELS[pending] ?? humanize(pending)} — ${doc.document_number}`} onClose={() => !busy && setPending(null)}
                    footer={<>
                        <Button variant="secondary" onClick={() => setPending(null)} disabled={busy}>Back</Button>
                        <Button variant={pending === 'cancel' ? 'danger' : 'primary'} loading={busy} onClick={run}>Confirm</Button>
                    </>}>
                    <p className="text-sm text-slate-600">{t === 'scrap' && pending === 'approve' ? 'Approving posts the stock movement immediately.' : ACTION_TEXT[pending]}</p>
                    {pending === 'receive' && (
                        <div className="mt-4 space-y-3">
                            <p className="text-xs text-slate-500">Enter the quantity physically received now, in each item’s base unit. Leave blank for nothing received.</p>
                            {lines.filter((l) => Number(l.outstanding_base_quantity) > 0).map((l) => (
                                <div key={l.id} className="grid grid-cols-3 items-center gap-2">
                                    <span className="col-span-2 text-sm"><span className="font-mono text-xs font-semibold">{l.item?.code}</span> · outstanding <Qty value={l.outstanding_base_quantity} uom={l.base_uom?.code} /></span>
                                    <QuantityInput large label={`Received ${l.item?.code}`} value={receipts[l.id] ?? ''} onChange={(v) => setReceipts((r) => ({ ...r, [l.id]: v }))} />
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="mt-4">
                        <TextareaInput label={needsRemarks ? 'Reason (required)' : 'Remarks (optional)'} rows={2} value={remarks} onChange={(e) => setRemarks(e.target.value)} />
                    </div>
                    {actionError.length > 0 && <Alert tone="danger" className="mt-3">{actionError.join(' ')}</Alert>}
                </Modal>
            )}
        </>
    );
}

function Attachments({ doc, config, canUpload, onChanged }: { doc: InventoryDocument; config: DocConfig; canUpload: boolean; onChanged: () => void }) {
    const toast = useToast();
    const [uploading, setUploading] = useState(false);
    const files = doc.attachments ?? [];

    const upload = async (file: File) => {
        setUploading(true);
        try {
            const form = new FormData();
            form.append('file', file);
            await api.post(`/inventory/${config.endpoint}/${doc.id}/attachments`, form);
            toast.success('Attachment uploaded.');
            onChanged();
        } catch (err) {
            toast.error(errorMessage(err, 'Upload failed.'));
        } finally {
            setUploading(false);
        }
    };

    return (
        <Card>
            <CardHeader title="Attachments" actions={canUpload && (
                <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <Upload className="h-4 w-4" aria-hidden="true" /> {uploading ? 'Uploading…' : 'Upload'}
                    <input type="file" className="sr-only" disabled={uploading} onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
                </label>
            )} />
            {files.length === 0 ? <p className="px-4 pb-4 text-sm text-slate-500">No attachments.</p> : (
                <ul className="divide-y divide-slate-100">
                    {files.map((f) => (
                        <li key={f.id} className="flex items-center justify-between px-4 py-2 text-sm">
                            <span className="flex items-center gap-2"><Paperclip className="h-4 w-4 text-slate-400" aria-hidden="true" />{f.original_name}</span>
                            <Button variant="ghost" size="sm" onClick={() => downloadFile(`/documents/${f.id}/download`, undefined, f.original_name)}>Download</Button>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
