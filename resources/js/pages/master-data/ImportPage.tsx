import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, Download, FileSpreadsheet, RefreshCw, Upload } from 'lucide-react';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Checkbox, SelectInput } from '@/components/ui/Form';
import { Button } from '@/components/ui/Button';
import { Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage } from '@/lib/api';
import { cx, formatDateTime } from '@/lib/format';
import { downloadFile } from './components/shared';
import { allErrors } from './documents/DocumentCommon';

interface ImportType { entity: string; label: string; can_update: boolean; columns: { key: string; label: string; required: boolean }[] }
interface RowResult { row: number; key: string | null; action: 'create' | 'update' | 'created' | 'updated' | 'error'; errors: string[]; values?: Record<string, string> }
interface Batch {
    id: number; entity: string; original_name: string; status: 'uploaded' | 'validated' | 'processing' | 'completed' | 'failed';
    update_existing: boolean; total_rows: number; valid_rows: number; error_rows: number; create_count: number; update_count: number;
    created_count: number; updated_count: number; skipped_count: number; failure_message: string | null; created_by: string | null; created_at: string;
    headers?: string[]; mapping?: Record<string, string | null>; columns?: ImportType['columns']; preview?: RowResult[]; errors?: RowResult[];
}

const actionTone: Record<RowResult['action'], 'success' | 'info' | 'danger'> = { create: 'success', created: 'success', update: 'info', updated: 'info', error: 'danger' };
const steps = ['Upload', 'Map columns', 'Validate & preview', 'Import'];

export default function ImportPage() {
    const toast = useToast();
    const types = useApi<{ data: ImportType[] }>('/imports/types');
    const history = useApi<{ data: Batch[] }>('/imports');
    const [entity, setEntity] = useState('');
    const [batch, setBatch] = useState<Batch | null>(null);
    const [mapping, setMapping] = useState<Record<string, string | null>>({});
    const [updateExisting, setUpdateExisting] = useState(false);
    const [confirmUpdates, setConfirmUpdates] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);
    const fileInput = useRef<HTMLInputElement>(null);

    useEffect(() => { if (!entity && types.data?.data.length) setEntity(types.data.data[0].entity); }, [types.data, entity]);

    // Poll queued imports until they finish.
    useEffect(() => {
        if (batch?.status !== 'processing') return;
        const timer = setInterval(async () => {
            const { data } = await api.get<{ data: Batch }>(`/imports/${batch.id}`);
            if (data.data.status !== 'processing') { setBatch(data.data); history.reload(); }
        }, 3000);
        return () => clearInterval(timer);
    }, [batch, history]);

    const type = types.data?.data.find((t) => t.entity === (batch?.entity ?? entity));
    const step = !batch ? 0 : batch.status === 'uploaded' ? 1 : batch.status === 'validated' ? 2 : 3;

    const call = async (fn: () => Promise<void>) => {
        setBusy(true);
        setErrors([]);
        try { await fn(); } catch (err) { setErrors(allErrors(err)); } finally { setBusy(false); }
    };

    const upload = (file: File) => call(async () => {
        const body = new FormData();
        body.append('entity', entity);
        body.append('file', file);
        const { data } = await api.post<{ data: Batch }>('/imports', body);
        setBatch(data.data);
        setMapping(data.data.mapping ?? {});
        setUpdateExisting(false);
        setConfirmUpdates(false);
    });

    const validate = () => call(async () => {
        const { data } = await api.post<{ data: Batch }>(`/imports/${batch!.id}/validate`, { mapping, update_existing: updateExisting });
        setBatch(data.data);
    });

    const commit = () => call(async () => {
        const { data } = await api.post<{ data: Batch; message: string }>(`/imports/${batch!.id}/commit`, { confirm: true, confirm_updates: confirmUpdates });
        setBatch(data.data);
        toast.success(data.message);
        history.reload();
    });

    const reset = () => { setBatch(null); setErrors([]); setMapping({}); if (fileInput.current) fileInput.current.value = ''; };

    if (types.loading) return <LoadingBlock />;
    if (!types.data?.data.length) return <Alert tone="info">You do not have permission to import any master data.</Alert>;

    return (
        <>
            <PageHeader title="Excel Import" description="Import categories, items, material grades, customers and suppliers from Excel (.xlsx) or CSV. Opening stock is not imported here." />

            <ol className="mb-6 flex flex-wrap gap-2 text-sm" aria-label="Import steps">
                {steps.map((s, i) => (
                    <li key={s} className={cx('flex items-center gap-2 rounded-full px-3 py-1', i === step ? 'bg-brand-600 text-white' : i < step ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-500')} aria-current={i === step ? 'step' : undefined}>
                        <span className="font-semibold">{i + 1}</span> {s}
                    </li>
                ))}
            </ol>

            {errors.length > 0 && (
                <Alert tone="danger" className="mb-4"><ul className="list-disc pl-5">{errors.map((e) => <li key={e}>{e}</li>)}</ul></Alert>
            )}

            {step === 0 && (
                <Card>
                    <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                        <SelectInput label="What are you importing?" value={entity} onChange={(e) => setEntity(e.target.value)}>
                            {types.data.data.map((t) => <option key={t.entity} value={t.entity}>{t.label}</option>)}
                        </SelectInput>
                        <Button variant="secondary" icon={<Download className="h-4 w-4" />} onClick={() => downloadFile(`/imports/templates/${entity}`, undefined, `${entity}-template.xlsx`).catch((e) => toast.error(errorMessage(e)))}>Download template</Button>
                        <div>
                            <input ref={fileInput} type="file" accept=".xlsx,.csv" className="hidden" onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
                            <Button icon={<Upload className="h-4 w-4" />} loading={busy} onClick={() => fileInput.current?.click()}>Upload file</Button>
                        </div>
                        <p className="text-xs text-slate-500 sm:col-span-3">The first row must contain column headings. Existing records are never replaced unless you enable updates and confirm them.</p>
                    </CardBody>
                </Card>
            )}

            {batch && step >= 1 && (
                <Card className="mb-6">
                    <CardHeader title={`${type?.label ?? batch.entity}: ${batch.original_name}`} description={`${batch.total_rows} data row(s)`}
                        actions={<Button size="sm" variant="ghost" icon={<RefreshCw className="h-4 w-4" />} onClick={reset}>Start over</Button>} />
                    {step === 1 && (
                        <CardBody>
                            <p className="mb-3 text-sm text-slate-600">Match each field to a column in your file. Required fields are marked *.</p>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {(batch.columns ?? type?.columns ?? []).map((c) => (
                                    <SelectInput key={c.key} label={`${c.label}${c.required ? ' *' : ''}`} value={mapping[c.key] ?? ''} onChange={(e) => setMapping({ ...mapping, [c.key]: e.target.value || null })}>
                                        <option value="">— not imported —</option>
                                        {(batch.headers ?? []).map((h) => <option key={h} value={h}>{h}</option>)}
                                    </SelectInput>
                                ))}
                            </div>
                            {type?.can_update && (
                                <div className="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3">
                                    <Checkbox label="Update existing records with matching codes" description="Off by default: rows whose code already exists are reported as errors and left unchanged." checked={updateExisting} onChange={(e) => setUpdateExisting(e.target.checked)} />
                                </div>
                            )}
                            <div className="mt-4 flex justify-end"><Button loading={busy} onClick={validate}>Validate rows</Button></div>
                        </CardBody>
                    )}
                    {step >= 2 && (
                        <CardBody className="space-y-4">
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {(step === 2
                                    ? [['To create', batch.create_count, 'text-emerald-700'], ['To update', batch.update_count, 'text-sky-700'], ['Errors (skipped)', batch.error_rows, 'text-red-700'], ['Total rows', batch.total_rows, 'text-slate-900']]
                                    : [['Created', batch.created_count, 'text-emerald-700'], ['Updated', batch.updated_count, 'text-sky-700'], ['Skipped', batch.skipped_count, 'text-red-700'], ['Total rows', batch.total_rows, 'text-slate-900']]
                                ).map(([label, value, tone]) => (
                                    <div key={label as string} className="rounded-md bg-slate-50 p-3">
                                        <p className={cx('text-2xl font-semibold tabular-nums', tone as string)}>{value as number}</p>
                                        <p className="text-xs text-slate-500">{label as string}</p>
                                    </div>
                                ))}
                            </div>
                            {batch.status === 'processing' && <Alert tone="info">Import is running on the queue. This page updates automatically.</Alert>}
                            {batch.status === 'failed' && <Alert tone="danger">{batch.failure_message}</Alert>}
                            {batch.status === 'completed' && <Alert tone="success"><span className="inline-flex items-center gap-1"><CheckCircle2 className="h-4 w-4" /> Import completed and recorded in the activity log.</span></Alert>}
                            {(batch.error_rows > 0 || batch.skipped_count > 0) && (
                                <Button size="sm" variant="secondary" icon={<Download className="h-4 w-4" />} onClick={() => downloadFile(`/imports/${batch.id}/errors`, undefined, `import-${batch.id}-errors.csv`).catch((e) => toast.error(errorMessage(e)))}>Download error report</Button>
                            )}
                            <div className="max-h-96 overflow-auto rounded-md border border-slate-200">
                                <Table>
                                    <thead><tr><Th>Row</Th><Th>Code</Th><Th>Result</Th><Th>Details</Th></tr></thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {[...(batch.errors ?? []), ...(batch.preview ?? []).filter((r) => r.action !== 'error')].sort((a, b) => a.row - b.row).slice(0, 200).map((r) => (
                                            <tr key={r.row}>
                                                <Td className="tabular-nums">{r.row}</Td>
                                                <Td className="font-mono text-xs">{r.key ?? '(auto)'}</Td>
                                                <Td><Badge tone={actionTone[r.action]}>{r.action}</Badge></Td>
                                                <Td className="text-xs">{r.errors.length ? <ul className="list-disc pl-4 text-red-700">{r.errors.map((e) => <li key={e}>{e}</li>)}</ul> : Object.entries(r.values ?? {}).slice(0, 4).map(([k, v]) => `${k}: ${v}`).join(' · ')}</Td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </Table>
                            </div>
                            {step === 2 && (
                                <div className="flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        {batch.update_count > 0 && <Checkbox label={`I confirm that ${batch.update_count} existing record(s) will be updated`} checked={confirmUpdates} onChange={(e) => setConfirmUpdates(e.target.checked)} />}
                                        {batch.error_rows > 0 && <p className="text-xs text-slate-500">Rows with errors are skipped; fix them in the file and import again.</p>}
                                    </div>
                                    <div className="flex gap-2">
                                        <Button variant="secondary" onClick={() => setBatch({ ...batch, status: 'uploaded' })}>Back to mapping</Button>
                                        <Button loading={busy} disabled={batch.valid_rows === 0 || (batch.update_count > 0 && !confirmUpdates)} onClick={commit}>Import {batch.valid_rows} row(s)</Button>
                                    </div>
                                </div>
                            )}
                        </CardBody>
                    )}
                </Card>
            )}

            <Card className="mt-6">
                <CardHeader title="Import history" description="Every import is kept as an audit record." />
                {history.loading ? <LoadingBlock /> : !history.data?.data.length ? <EmptyState icon={<FileSpreadsheet className="h-6 w-6" />} title="No imports yet" /> : (
                    <Table>
                        <thead><tr><Th>When</Th><Th>Type</Th><Th className="hidden sm:table-cell">File</Th><Th>Status</Th><Th className="hidden md:table-cell">Result</Th><Th className="hidden lg:table-cell">By</Th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {history.data.data.map((b) => (
                                <tr key={b.id}>
                                    <Td className="whitespace-nowrap text-xs">{formatDateTime(b.created_at)}</Td>
                                    <Td>{types.data?.data.find((t) => t.entity === b.entity)?.label ?? b.entity}</Td>
                                    <Td className="hidden max-w-[14rem] truncate text-xs sm:table-cell">{b.original_name}</Td>
                                    <Td><Badge tone={b.status === 'completed' ? 'success' : b.status === 'failed' ? 'danger' : 'neutral'}>{b.status}</Badge></Td>
                                    <Td className="hidden text-xs md:table-cell">{b.status === 'completed' ? `${b.created_count} created · ${b.updated_count} updated · ${b.skipped_count} skipped` : `${b.total_rows} rows`}</Td>
                                    <Td className="hidden text-xs lg:table-cell">{b.created_by ?? '—'}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </>
    );
}
