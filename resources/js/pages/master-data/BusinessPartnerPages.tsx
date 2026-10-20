import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Eye, Pencil, Plus, Power, Trash2, Users } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Checkbox, SelectInput, TextareaInput, TextInput } from '@/components/ui/Form';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import type { Paginated } from '@/types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '@/pages/structure/components/ListControls';
import { useStatusAction } from '@/pages/structure/components/useStatusAction';
import type { BusinessPartner } from './types';

type Contact = NonNullable<BusinessPartner['contacts']>[number];
type Address = NonNullable<BusinessPartner['addresses']>[number];

const partyLabel = { customer: 'Customer', supplier: 'Supplier', both: 'Customer & Supplier', none: '—' } as const;
const PartyBadge = ({ type }: { type: BusinessPartner['party_type'] }) => <Badge tone={type === 'both' ? 'brand' : type === 'customer' ? 'info' : 'neutral'}>{partyLabel[type]}</Badge>;

export function BusinessPartnerListPage() {
    const { can } = useAuth();
    const list = useListParams({ sort: 'name' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);
    const { data, loading, error, reload } = useApi<Paginated<BusinessPartner>>('/business-partners', {
        search: debounced, party_type: list.get('party_type'), status: list.get('status'), sort: list.sort, direction: list.direction, page: list.page, per_page: 15,
    });
    const status = useStatusAction<BusinessPartner>('business-partners', 'party', () => reload());

    return (
        <>
            <PageHeader title="Customers & Suppliers" description="Business partners. A party can be a customer, a supplier, or both. Orders and invoices come in later modules."
                actions={can('business-partners.create') && <ButtonLink to="/master-data/partners/new" icon={<Plus className="h-4 w-4" />}>Add party</ButtonLink>} />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search code, name, GSTIN, email or contact" />
                    <select aria-label="Filter by party type" value={list.get('party_type')} onChange={(e) => list.update({ party_type: e.target.value })} className={filterInputClass}>
                        <option value="">Customers & suppliers</option>
                        <option value="customer">Customers</option>
                        <option value="supplier">Suppliers</option>
                        <option value="both">Both</option>
                    </select>
                    <select aria-label="Filter by status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </FilterBar>
                {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                    <EmptyState icon={<Users className="h-6 w-6" />} title={list.hasFilters ? 'No parties match your filters' : 'No customers or suppliers yet'} />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <SortableTh label="Code" field="code" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Party" field="name" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th>Type</Th>
                                    <Th className="hidden md:table-cell">Contact</Th>
                                    <Th className="hidden lg:table-cell">GSTIN</Th>
                                    <Th>Status</Th>
                                    <Th className="text-right"><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((p) => (
                                    <tr key={p.id} className="hover:bg-slate-50">
                                        <Td className="font-mono text-xs font-semibold">{p.code}</Td>
                                        <Td><Link to={`/master-data/partners/${p.id}`} className="font-medium text-slate-900 hover:text-brand-700">{p.name}</Link></Td>
                                        <Td><PartyBadge type={p.party_type} /></Td>
                                        <Td className="hidden text-xs md:table-cell">{p.contact_person ?? '—'}{p.phone && <span className="block text-slate-500">{p.phone}</span>}</Td>
                                        <Td className="hidden font-mono text-xs lg:table-cell">{p.gstin ?? '—'}</Td>
                                        <Td><StatusBadge active={p.is_active} /></Td>
                                        <Td className="whitespace-nowrap text-right">
                                            <ButtonLink to={`/master-data/partners/${p.id}`} variant="ghost" size="sm" aria-label={`View ${p.name}`} icon={<Eye className="h-4 w-4" />} />
                                            {can('business-partners.update') && <ButtonLink to={`/master-data/partners/${p.id}/edit`} variant="ghost" size="sm" aria-label={`Edit ${p.name}`} icon={<Pencil className="h-4 w-4" />} />}
                                            {can(p.is_active ? 'business-partners.deactivate' : 'business-partners.update') && (
                                                <Button variant="ghost" size="sm" aria-label={`${p.is_active ? 'Deactivate' : 'Activate'} ${p.name}`} icon={<Power className={`h-4 w-4 ${p.is_active ? 'text-red-500' : 'text-emerald-600'}`} />} onClick={() => status.request(p)} />
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={(pg) => list.update({ page: String(pg) })} />
                    </div>
                ) : null}
            </Card>
            {status.dialog}
        </>
    );
}

const blankAddress = (type: Address['address_type']): Address => ({ address_type: type, label: '', address_line_1: '', address_line_2: '', city: '', state: '', postal_code: '', country: 'India', is_default: true });

export function BusinessPartnerFormPage() {
    const { id } = useParams();
    const isEdit = Boolean(id);
    const navigate = useNavigate();
    const toast = useToast();
    const existing = useApi<{ data: BusinessPartner }>(isEdit ? `/business-partners/${id}` : null);
    const [form, setForm] = useState({
        code: '', name: '', is_customer: true, is_supplier: false, contact_person: '', email: '', phone: '', website: '',
        gstin: '', pan: '', currency: 'INR', payment_terms: '', remarks: '',
    });
    const [contacts, setContacts] = useState<Contact[]>([]);
    const [addresses, setAddresses] = useState<Address[]>([blankAddress('billing')]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const p = existing.data?.data;
        if (!p) return;
        setForm({
            code: p.code, name: p.name, is_customer: p.is_customer, is_supplier: p.is_supplier, contact_person: p.contact_person ?? '', email: p.email ?? '',
            phone: p.phone ?? '', website: p.website ?? '', gstin: p.gstin ?? '', pan: p.pan ?? '', currency: p.currency, payment_terms: p.payment_terms ?? '', remarks: p.remarks ?? '',
        });
        setContacts(p.contacts ?? []);
        setAddresses(p.addresses?.length ? p.addresses : [blankAddress('billing')]);
    }, [existing.data]);

    if (existing.loading) return <LoadingBlock />;
    if (existing.error) return <Alert tone="danger">{existing.error}</Alert>;

    const set = (k: keyof typeof form, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }));
    const field = (k: keyof typeof form, label: string, extra: Record<string, unknown> = {}) => (
        <TextInput label={label} value={form[k] as string} onChange={(e) => set(k, ['gstin', 'pan', 'currency', 'code'].includes(k) ? e.target.value.toUpperCase() : e.target.value)} error={errors[k]} {...extra} />
    );

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        if (saving) return;
        if (!form.name.trim()) { setErrors({ name: 'Party name is required.' }); return; }
        if (!form.is_customer && !form.is_supplier) { setErrors({ is_customer: 'Select customer, supplier or both.' }); return; }
        setSaving(true);
        setErrors({});
        setFormError(null);
        const clean = (v: string) => (v.trim() === '' ? null : v.trim());
        const payload = {
            ...form, code: clean(form.code), contact_person: clean(form.contact_person), email: clean(form.email), phone: clean(form.phone), website: clean(form.website),
            gstin: clean(form.gstin), pan: clean(form.pan), payment_terms: clean(form.payment_terms), remarks: clean(form.remarks),
            contacts: contacts.filter((c) => c.name.trim()).map((c) => ({ ...c, designation: c.designation || null, email: c.email || null, phone: c.phone || null })),
            addresses: addresses.filter((a) => a.address_line_1.trim()),
        };
        try {
            if (isEdit) {
                await api.put(`/business-partners/${id}`, payload);
                toast.success('Party updated.');
                navigate(`/master-data/partners/${id}`);
            } else {
                const { data } = await api.post<{ data: BusinessPartner }>('/business-partners', payload);
                toast.success(`Party ${data.data.code} created.`);
                navigate(`/master-data/partners/${data.data.id}`);
            }
        } catch (err) {
            setErrors(fieldErrors(err));
            if (!Object.keys(fieldErrors(err)).length) setFormError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    return (
        <form onSubmit={onSubmit} noValidate>
            <PageHeader title={isEdit ? `Edit ${existing.data?.data.name}` : 'Add customer / supplier'} breadcrumb={<Link to="/master-data/partners" className="hover:text-slate-700">Customers & Suppliers</Link>} />
            {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}
            <fieldset disabled={saving} className="space-y-6">
                <Card>
                    <CardHeader title="Party details" />
                    <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {field('code', 'Party code', { maxLength: 30, hint: isEdit ? undefined : 'Leave blank to generate (BP-00001).' })}
                        {field('name', 'Party name', { required: true, maxLength: 200, wrapperClassName: 'lg:col-span-2' })}
                        <div className="flex flex-wrap gap-6 sm:col-span-2 lg:col-span-3">
                            <Checkbox label="Customer" checked={form.is_customer} onChange={(e) => set('is_customer', e.target.checked)} />
                            <Checkbox label="Supplier" checked={form.is_supplier} onChange={(e) => set('is_supplier', e.target.checked)} />
                            {errors.is_customer && <p className="text-xs text-red-600">{errors.is_customer}</p>}
                        </div>
                        {field('contact_person', 'Contact person')}
                        {field('email', 'Email', { type: 'email' })}
                        {field('phone', 'Phone', { type: 'tel' })}
                        {field('website', 'Website', { placeholder: 'https://' })}
                        {field('gstin', 'GSTIN (optional)', { maxLength: 15 })}
                        {field('pan', 'PAN (optional)', { maxLength: 10 })}
                        {field('currency', 'Currency', { maxLength: 3 })}
                        {field('payment_terms', 'Payment terms (optional)', { placeholder: 'e.g. 30 days' })}
                        <TextareaInput label="Remarks" value={form.remarks} onChange={(e) => set('remarks', e.target.value)} wrapperClassName="sm:col-span-2 lg:col-span-3" />
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Addresses" actions={
                        <div className="flex gap-2">
                            <Button size="sm" variant="secondary" icon={<Plus className="h-4 w-4" />} onClick={() => setAddresses((a) => [...a, blankAddress('billing')])}>Billing</Button>
                            <Button size="sm" variant="secondary" icon={<Plus className="h-4 w-4" />} onClick={() => setAddresses((a) => [...a, blankAddress('shipping')])}>Shipping</Button>
                        </div>
                    } />
                    <div className="divide-y divide-slate-100">
                        {errors.addresses && <Alert tone="danger" className="m-4">{errors.addresses}</Alert>}
                        {addresses.map((a, i) => {
                            const upd = (patch: Partial<Address>) => setAddresses((list) => list.map((x, j) => (j === i ? { ...x, ...patch } : x)));
                            return (
                                <div key={i} className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <SelectInput label="Type" value={a.address_type} onChange={(e) => upd({ address_type: e.target.value as Address['address_type'] })}>
                                        <option value="billing">Billing</option>
                                        <option value="shipping">Shipping</option>
                                    </SelectInput>
                                    <TextInput label="Address line 1" value={a.address_line_1} onChange={(e) => upd({ address_line_1: e.target.value })} error={errors[`addresses.${i}.address_line_1`]} wrapperClassName="lg:col-span-2" />
                                    <div className="flex items-end justify-between gap-2">
                                        <Checkbox label="Default" checked={a.is_default} onChange={(e) => upd({ is_default: e.target.checked })} />
                                        <Button variant="ghost" size="sm" aria-label={`Remove address ${i + 1}`} icon={<Trash2 className="h-4 w-4 text-red-500" />} onClick={() => setAddresses((list) => list.filter((_, j) => j !== i))} />
                                    </div>
                                    <TextInput label="Address line 2" value={a.address_line_2 ?? ''} onChange={(e) => upd({ address_line_2: e.target.value })} wrapperClassName="lg:col-span-2" />
                                    <TextInput label="City" value={a.city ?? ''} onChange={(e) => upd({ city: e.target.value })} />
                                    <TextInput label="State" value={a.state ?? ''} onChange={(e) => upd({ state: e.target.value })} />
                                    <TextInput label="Postal code" value={a.postal_code ?? ''} onChange={(e) => upd({ postal_code: e.target.value })} error={errors[`addresses.${i}.postal_code`]} />
                                    <TextInput label="Country" value={a.country ?? ''} onChange={(e) => upd({ country: e.target.value })} />
                                </div>
                            );
                        })}
                    </div>
                </Card>

                <Card>
                    <CardHeader title="Contact persons" actions={<Button size="sm" variant="secondary" icon={<Plus className="h-4 w-4" />} onClick={() => setContacts((c) => [...c, { name: '', designation: '', email: '', phone: '', is_primary: c.length === 0 }])}>Add contact</Button>} />
                    <div className="divide-y divide-slate-100">
                        {contacts.length === 0 && <p className="p-4 text-sm text-slate-500">No additional contacts.</p>}
                        {contacts.map((c, i) => {
                            const upd = (patch: Partial<Contact>) => setContacts((list) => list.map((x, j) => (j === i ? { ...x, ...patch } : x)));
                            return (
                                <div key={i} className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_1fr_auto]">
                                    <TextInput label="Name" value={c.name} onChange={(e) => upd({ name: e.target.value })} error={errors[`contacts.${i}.name`]} />
                                    <TextInput label="Designation" value={c.designation ?? ''} onChange={(e) => upd({ designation: e.target.value })} />
                                    <TextInput label="Email" type="email" value={c.email ?? ''} onChange={(e) => upd({ email: e.target.value })} error={errors[`contacts.${i}.email`]} />
                                    <TextInput label="Phone" value={c.phone ?? ''} onChange={(e) => upd({ phone: e.target.value })} error={errors[`contacts.${i}.phone`]} />
                                    <div className="flex items-end gap-2">
                                        <Checkbox label="Primary" checked={c.is_primary} onChange={(e) => setContacts((list) => list.map((x, j) => ({ ...x, is_primary: j === i ? e.target.checked : false })))} />
                                        <Button variant="ghost" size="sm" aria-label={`Remove contact ${i + 1}`} icon={<Trash2 className="h-4 w-4 text-red-500" />} onClick={() => setContacts((list) => list.filter((_, j) => j !== i))} />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </Card>
            </fieldset>
            <div className="sticky bottom-0 mt-6 flex justify-end gap-2 border-t border-slate-200 bg-slate-50/95 py-3 backdrop-blur">
                <ButtonLink to={isEdit ? `/master-data/partners/${id}` : '/master-data/partners'} variant="secondary">Cancel</ButtonLink>
                <Button type="submit" loading={saving}>{isEdit ? 'Save changes' : 'Create party'}</Button>
            </div>
        </form>
    );
}

export function BusinessPartnerDetailsPage() {
    const { id } = useParams();
    const { can } = useAuth();
    const { data, loading, error, reload } = useApi<{ data: BusinessPartner }>(`/business-partners/${id}`);
    const status = useStatusAction<BusinessPartner>('business-partners', 'party', () => reload());
    if (loading) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Party not found.'}</Alert>;
    const p = data.data;

    return (
        <>
            <PageHeader title={p.name} breadcrumb={<Link to="/master-data/partners" className="hover:text-slate-700">Customers & Suppliers</Link>} description={<span className="font-mono">{p.code}</span>}
                actions={<>
                    {can(p.is_active ? 'business-partners.deactivate' : 'business-partners.update') && <Button variant={p.is_active ? 'danger' : 'secondary'} icon={<Power className="h-4 w-4" />} onClick={() => status.request(p)}>{p.is_active ? 'Deactivate' : 'Activate'}</Button>}
                    {can('business-partners.update') && <ButtonLink to={`/master-data/partners/${p.id}/edit`} icon={<Pencil className="h-4 w-4" />}>Edit</ButtonLink>}
                </>} />
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader title="Details" />
                    <CardBody><DescriptionList items={[
                        { label: 'Party ID', value: <span className="font-mono">#{p.id}</span> },
                        { label: 'Type', value: <PartyBadge type={p.party_type} /> },
                        { label: 'Status', value: <StatusBadge active={p.is_active} /> },
                        { label: 'Contact person', value: p.contact_person ?? '—' },
                        { label: 'Email', value: p.email ?? '—' },
                        { label: 'Phone', value: p.phone ?? '—' },
                        { label: 'Website', value: p.website ?? '—' },
                        { label: 'GSTIN / PAN', value: `${p.gstin ?? '—'} / ${p.pan ?? '—'}` },
                        { label: 'Currency', value: p.currency },
                        { label: 'Payment terms', value: p.payment_terms ?? '—' },
                        { label: 'Remarks', value: p.remarks ?? '—' },
                        { label: 'Last updated', value: formatDateTime(p.updated_at) },
                    ]} /></CardBody>
                </Card>
                <Card>
                    <CardHeader title="Contacts" />
                    <CardBody className="space-y-3">
                        {!p.contacts?.length && <p className="text-sm text-slate-500">No contacts.</p>}
                        {p.contacts?.map((c) => (
                            <div key={c.id} className="text-sm">
                                <p className="font-medium text-slate-900">{c.name}{c.is_primary && <Badge tone="info" className="ml-2">Primary</Badge>}</p>
                                <p className="text-xs text-slate-500">{[c.designation, c.email, c.phone].filter(Boolean).join(' · ')}</p>
                            </div>
                        ))}
                    </CardBody>
                </Card>
            </div>
            <Card className="mt-6">
                <CardHeader title="Addresses" />
                {!p.addresses?.length ? <EmptyState title="No addresses" /> : (
                    <div className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3">
                        {p.addresses.map((a) => (
                            <div key={a.id} className="rounded-md border border-slate-200 p-3 text-sm">
                                <p className="mb-1 font-medium capitalize text-slate-900">{a.address_type}{a.is_default && <Badge className="ml-2">Default</Badge>}</p>
                                <p className="text-slate-600">{[a.address_line_1, a.address_line_2, a.city, a.state, a.postal_code, a.country].filter(Boolean).join(', ')}</p>
                            </div>
                        ))}
                    </div>
                )}
            </Card>
            {status.dialog}
        </>
    );
}
