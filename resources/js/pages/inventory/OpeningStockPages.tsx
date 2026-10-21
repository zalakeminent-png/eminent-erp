import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Download, FileSpreadsheet, Upload } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Pagination, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { TextareaInput } from '@/components/ui/Form';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { cx, formatDateTime, humanize } from '@/lib/format';
import type { PaginationMeta } from '@/types';
import { filterInputClass } from '@/pages/structure/components/ListControls';
import { downloadFile } from '@/pages/master-data/components/shared';
import type { CodedRef, UomRef } from '@/pages/master-data/types';
import { DocumentStatusBadge, Qty, statusLabel } from './components';
import type { LocationRef, UserRef } from './types';

interface OpeningImport {
    id: number;
    import_number: string;
    original_name: string;
    status: string;
    headers: string[];
    mapping: Record<string, string | null>;
    total_rows: number;
    accepted_rows: number;
    rejected_rows: number;
    transaction_id: number | null;
    failure_message: string | null;
    reversal_reason: string | null;
    created_by: UserRef | null;
    approved_by: UserRef | null;
    posted_by: UserRef | null;
    posted_at: string | null;
    created_at: string;
    allowed_actions: string[];
    columns?: { key: string; label: string; required: boolean }[];
    rows?: {
        id: number; row_number: number; status: 'accepted' | 'rejected'; errors: string[]; raw: Record<string, string>; item: CodedRef | null;
        location: LocationRef | null; stock_status: string | null; quantity: string | null; uom: UomRef | null; base_quantity: string | null; unit_cost: string | null;
    }[];
    rows_meta?: PaginationMeta;
}

const STEPS = ['Upload', 'Map & validate', 'Preview (draft)', 'Submit', 'Approve', 'Post'];

function stepIndex(status: string): number {
    return { uploaded: 1, draft: 2, submitted: 4, approved: 5, posting: 5, failed: 5, posted: 6, reversed: 6 }[status] ?? 0;
}

export function OpeningStockListPage() {
    const { can } = useAuth();
    const navigate = useNavigate();
    const toast = useToast();
    const { data, loading, error } = useApi<{ data: OpeningImport[]; meta: PaginationMeta }>('/inventory/opening-stock/imports', { per_page: 20 });
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);

    const upload = async (file: File) => {
        setUploading(true);
        setUploadError(null);
        try {
            const form = new FormData();
            form.append('file', file);
            const res = await api.post<{ data: OpeningImport }>('/inventory/opening-stock/import', form);
            toast.success('File uploaded. Check the column mapping.');
            navigate(`/inventory/opening-stock/${res.data.data.id}`);
        } catch (err) {
            const fe = fieldErrors(err);
            setUploadError(fe.file ?? errorMessage(err));
        } finally {
            setUploading(false);
        }
    };

    return (
        <>
            <PageHeader title="Opening Stock" description="Load existing stock from Excel. Rows are validated against items, warehouses, locations and units; nothing is posted until the import is approved and posted."
                actions={<Button variant="secondary" icon={<Download className="h-4 w-4" />} onClick={() => downloadFile('/inventory/opening-stock/template', undefined, 'opening-stock-template.xlsx')}>Template</Button>} />
            {can('inventory.opening-stock.import') && (
                <Card className="mb-4">
                    <CardBody>
                        <label className={cx('flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-slate-300 p-8 text-center hover:border-brand-400', uploading && 'opacity-60')}>
                            <Upload className="h-8 w-8 text-slate-400" aria-hidden="true" />
                            <span className="text-sm font-medium text-slate-700">{uploading ? 'Uploading…' : 'Upload .xlsx or .csv'}</span>
                            <span className="text-xs text-slate-500">Columns: Item Code, Item Name (reference), Warehouse Code, Location Code, Stock Status, Quantity, UOM, Unit Cost, Remarks</span>
                            <input type="file" accept=".xlsx,.csv" className="sr-only" aria-label="Opening stock file" disabled={uploading} onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
                        </label>
                        {uploadError && <Alert tone="danger" className="mt-3">{uploadError}</Alert>}
                    </CardBody>
                </Card>
            )}
            <Card>
                <CardHeader title="Imports" />
                {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                    <EmptyState icon={<FileSpreadsheet className="h-6 w-6" />} title="No opening stock imports yet" />
                ) : data ? (
                    <Table>
                        <thead><tr><Th>Import</Th><Th className="hidden md:table-cell">File</Th><Th className="text-right">Accepted / Rejected</Th><Th>Status</Th><Th className="hidden lg:table-cell">Created</Th></tr></thead>
                        <tbody className="divide-y divide-slate-100 bg-white">
                            {data.data.map((i) => (
                                <tr key={i.id} className="hover:bg-slate-50">
                                    <Td><Link to={`/inventory/opening-stock/${i.id}`} className="font-mono text-xs font-semibold text-slate-900 hover:text-brand-700">{i.import_number}</Link></Td>
                                    <Td className="hidden text-xs md:table-cell">{i.original_name}</Td>
                                    <Td className="text-right text-xs tabular-nums"><span className="text-emerald-700">{i.accepted_rows}</span> / <span className="text-red-600">{i.rejected_rows}</span></Td>
                                    <Td><DocumentStatusBadge status={i.status} /></Td>
                                    <Td className="hidden text-xs lg:table-cell">{i.created_by?.full_name} · {formatDateTime(i.created_at)}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                ) : null}
            </Card>
        </>
    );
}

export function OpeningStockDetailsPage() {
    const { id } = useParams();
    const toast = useToast();
    const [rowStatus, setRowStatus] = useState('');
    const [page, setPage] = useState(1);
    const { data, loading, error, reload } = useApi<{ data: OpeningImport }>(`/inventory/opening-stock/imports/${id}`, { row_status: rowStatus, page, per_page: 50 });
    const [mapping, setMapping] = useState<Record<string, string | null>>({});
    const [busy, setBusy] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string[]>([]);
    const [reversing, setReversing] = useState(false);
    const [reason, setReason] = useState('');
    const imp = data?.data;

    useEffect(() => { if (imp) setMapping(imp.mapping ?? {}); }, [imp?.id, imp?.status]); // eslint-disable-line react-hooks/exhaustive-deps

    if (loading && !imp) return <LoadingBlock />;
    if (error || !imp) return <Alert tone="danger">{error ?? 'Not found.'}</Alert>;

    const act = async (action: string, body: Record<string, unknown> = {}) => {
        setBusy(action);
        setActionError([]);
        try {
            await api.post(`/inventory/opening-stock/imports/${imp.id}/${action}`, body);
            toast.success(action === 'validate' ? 'File validated.' : `Import ${humanize(action).toLowerCase()} done.`);
            setReversing(false);
            reload();
        } catch (err) {
            const fe = fieldErrors(err);
            setActionError(Object.keys(fe).length ? Object.values(fe) : [errorMessage(err)]);
        } finally {
            setBusy(null);
        }
    };

    const step = stepIndex(imp.status);
    const mappable = imp.allowed_actions.includes('validate');

    return (
        <>
            <PageHeader title={`Opening stock ${imp.import_number}`} description={imp.original_name} breadcrumb={<Link to="/inventory/opening-stock" className="hover:underline">Opening Stock</Link>}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {imp.rejected_rows > 0 && <Button variant="secondary" icon={<Download className="h-4 w-4" />} onClick={() => downloadFile(`/inventory/opening-stock/imports/${imp.id}/errors`, undefined, `${imp.import_number}-errors.xlsx`)}>Error report</Button>}
                        {imp.allowed_actions.includes('submit') && <Button loading={busy === 'submit'} onClick={() => act('submit')}>Submit for approval</Button>}
                        {imp.allowed_actions.includes('reject') && <Button variant="secondary" loading={busy === 'reject'} onClick={() => act('reject')}>Return to draft</Button>}
                        {imp.allowed_actions.includes('approve') && <Button loading={busy === 'approve'} onClick={() => act('approve')}>Approve</Button>}
                        {imp.allowed_actions.includes('post') && <Button loading={busy === 'post'} onClick={() => act('post')}>Post to stock ledger</Button>}
                        {imp.allowed_actions.includes('reverse') && <Button variant="danger" onClick={() => setReversing(true)}>Reverse</Button>}
                    </div>
                } />

            <ol className="mb-4 flex flex-wrap gap-2 text-xs" aria-label="Import progress">
                {STEPS.map((s, i) => (
                    <li key={s} className={cx('rounded-full px-3 py-1', i < step ? 'bg-brand-600 text-white' : i === step ? 'bg-brand-50 font-medium text-brand-700 ring-1 ring-brand-200' : 'bg-slate-100 text-slate-500')}>{i + 1}. {s}</li>
                ))}
            </ol>

            {actionError.length > 0 && <Alert tone="danger" className="mb-4">{actionError.join(' ')}</Alert>}
            {imp.status === 'posting' && <Alert tone="info" className="mb-4">Posting is running in the background. Refresh in a moment.</Alert>}
            {imp.failure_message && imp.status === 'failed' && <Alert tone="danger" className="mb-4">Posting failed and nothing was posted: {imp.failure_message}</Alert>}
            {imp.status === 'posted' && <Alert tone="success" className="mb-4">Posted by {imp.posted_by?.full_name} on {formatDateTime(imp.posted_at)}. Corrections need a reversal (authorised) or a stock adjustment.</Alert>}
            {imp.status === 'reversed' && <Alert tone="warning" className="mb-4">Reversed: {imp.reversal_reason}</Alert>}

            <div className="mb-4 grid grid-cols-3 gap-4">
                <Card className="p-4"><p className="text-xs uppercase text-slate-500">Rows</p><p className="text-2xl font-semibold">{imp.total_rows}</p></Card>
                <Card className="p-4"><p className="text-xs uppercase text-slate-500">Accepted</p><p className="text-2xl font-semibold text-emerald-700">{imp.accepted_rows}</p></Card>
                <Card className="p-4"><p className="text-xs uppercase text-slate-500">Rejected</p><p className="text-2xl font-semibold text-red-600">{imp.rejected_rows}</p></Card>
            </div>

            {mappable && (
                <Card className="mb-4">
                    <CardHeader title="Column mapping" description="Match each field to a column of your file, then validate. Validation never posts stock." />
                    <CardBody className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {imp.columns?.map((c) => (
                            <label key={c.key} className="text-sm">
                                <span className="mb-1 block font-medium text-slate-700">{c.label}{c.required && <span className="text-red-500"> *</span>}</span>
                                <select aria-label={c.label} value={mapping[c.key] ?? ''} onChange={(e) => setMapping((m) => ({ ...m, [c.key]: e.target.value || null }))} className={filterInputClass}>
                                    <option value="">— not in file —</option>
                                    {imp.headers.map((h) => <option key={h} value={h}>{h}</option>)}
                                </select>
                            </label>
                        ))}
                        <div className="flex items-end"><Button loading={busy === 'validate'} onClick={() => act('validate', { mapping })}>Validate rows</Button></div>
                    </CardBody>
                </Card>
            )}

            {imp.status !== 'uploaded' && (
                <Card>
                    <CardHeader title="Preview" actions={
                        <select aria-label="Show rows" value={rowStatus} onChange={(e) => { setRowStatus(e.target.value); setPage(1); }} className={`${filterInputClass} w-40`}>
                            <option value="">All rows</option><option value="accepted">Accepted</option><option value="rejected">Rejected</option>
                        </select>
                    } />
                    <Table>
                        <thead><tr><Th>Row</Th><Th>Item</Th><Th className="hidden md:table-cell">Location</Th><Th className="hidden sm:table-cell">Status</Th><Th className="text-right">Quantity</Th><Th className="hidden text-right lg:table-cell">Base qty</Th><Th>Result</Th></tr></thead>
                        <tbody className="divide-y divide-slate-100 bg-white">
                            {imp.rows?.map((r) => (
                                <tr key={r.id} className={r.status === 'rejected' ? 'bg-red-50/40' : undefined}>
                                    <Td className="text-xs tabular-nums">{r.row_number}</Td>
                                    <Td className="text-xs"><span className="font-mono font-semibold">{r.item?.code ?? r.raw.item_code}</span><span className="block text-slate-500">{r.item?.name ?? r.raw.item_name}</span></Td>
                                    <Td className="hidden text-xs md:table-cell">{r.location ? `${r.location.warehouse_code}/${r.location.code}` : `${r.raw.warehouse_code}/${r.raw.location_code}`}</Td>
                                    <Td className="hidden text-xs sm:table-cell">{statusLabel(r.stock_status ?? r.raw.stock_status ?? 'available')}</Td>
                                    <Td className="text-right"><Qty value={r.quantity ?? r.raw.quantity} uom={r.uom?.code ?? r.raw.uom} /></Td>
                                    <Td className="hidden text-right lg:table-cell"><Qty value={r.base_quantity} /></Td>
                                    <Td className="text-xs">{r.status === 'accepted' ? <span className="text-emerald-700">Accepted</span> : <ul className="list-disc pl-4 text-red-700">{r.errors.map((e) => <li key={e}>{e}</li>)}</ul>}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                    {imp.rows_meta && <Pagination meta={imp.rows_meta} onPage={setPage} />}
                </Card>
            )}

            {reversing && (
                <Modal open title={`Reverse ${imp.import_number}`} onClose={() => setReversing(false)}
                    footer={<><Button variant="secondary" onClick={() => setReversing(false)}>Back</Button><Button variant="danger" loading={busy === 'reverse'} onClick={() => act('reverse', { reason })}>Reverse posting</Button></>}>
                    <p className="text-sm text-slate-600">Posts compensating entries that remove this opening stock. The original entries remain in the ledger. Fails if the stock has already been used.</p>
                    <div className="mt-3"><TextareaInput label="Reason (required)" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} /></div>
                </Modal>
            )}
        </>
    );
}
