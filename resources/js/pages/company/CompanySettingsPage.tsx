import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Building2, ImageUp, Trash2 } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Alert, PageHeader } from '@/components/ui/Feedback';
import { SelectInput, TextInput, Toggle } from '@/components/ui/Form';
import { Button } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { MONTHS } from '@/lib/format';
import type { Company } from '@/types';

type FormState = Record<
    | 'name' | 'legal_name' | 'code' | 'gstin' | 'pan' | 'cin' | 'email' | 'phone' | 'website'
    | 'address_line_1' | 'address_line_2' | 'city' | 'state' | 'postal_code' | 'country' | 'currency' | 'timezone',
    string
> & { financial_year_start_month: number; financial_year_end_month: number; is_active: boolean };

const GSTIN = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/;
const PAN = /^[A-Z]{5}[0-9]{4}[A-Z]$/;
const CIN = /^[LU][0-9]{5}[A-Z]{2}[0-9]{4}[A-Z]{3}[0-9]{6}$/;
const MAX_LOGO_BYTES = 2 * 1024 * 1024;
const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

const CURRENCIES = ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD'];
const TIMEZONES = ['Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Europe/London', 'UTC', 'America/New_York'];
const INDIAN_STATES = [
    'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh',
    'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha',
    'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
    'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir',
    'Ladakh', 'Lakshadweep', 'Puducherry',
];

function toForm(c: Company): FormState {
    return {
        name: c.name ?? '', legal_name: c.legal_name ?? '', code: c.code ?? '', gstin: c.gstin ?? '', pan: c.pan ?? '', cin: c.cin ?? '',
        email: c.email ?? '', phone: c.phone ?? '', website: c.website ?? '', address_line_1: c.address_line_1 ?? '',
        address_line_2: c.address_line_2 ?? '', city: c.city ?? '', state: c.state ?? '', postal_code: c.postal_code ?? '',
        country: c.country ?? 'India', currency: c.currency ?? 'INR', timezone: c.timezone ?? 'Asia/Kolkata',
        financial_year_start_month: c.financial_year_start_month ?? 4, financial_year_end_month: c.financial_year_end_month ?? 3,
        is_active: c.is_active,
    };
}

/** Quick client-side checks for instant feedback; the API re-validates everything. */
function validate(form: FormState): Record<string, string> {
    const errors: Record<string, string> = {};
    if (!form.name.trim()) errors.name = 'Company name is required.';
    if (!form.legal_name.trim()) errors.legal_name = 'Legal company name is required.';
    if (!form.code.trim()) errors.code = 'Company code is required.';
    if (form.gstin && !GSTIN.test(form.gstin)) errors.gstin = 'Invalid GSTIN format (e.g. 27AAPFU0939F1ZV).';
    if (form.pan && !PAN.test(form.pan)) errors.pan = 'Invalid PAN format (e.g. AAPFU0939F).';
    if (form.cin && !CIN.test(form.cin)) errors.cin = 'Invalid CIN format.';
    if (form.gstin && form.pan && GSTIN.test(form.gstin) && PAN.test(form.pan) && form.gstin.slice(2, 12) !== form.pan) {
        errors.gstin = 'GSTIN characters 3–12 must match the PAN.';
    }
    if (form.country.toLowerCase() === 'india' && form.postal_code && !/^[1-9][0-9]{5}$/.test(form.postal_code)) {
        errors.postal_code = 'Enter a valid 6-digit PIN code.';
    }
    return errors;
}

function Section({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
    return (
        <Card>
            <CardHeader title={title} description={description} />
            <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2">{children}</CardBody>
        </Card>
    );
}

export default function CompanySettingsPage() {
    const { can, refresh } = useAuth();
    const toast = useToast();
    const editable = can('company.update');
    const { data, loading, error } = useApi<{ data: Company }>('/company');

    const [form, setForm] = useState<FormState | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [logoFile, setLogoFile] = useState<File | null>(null);
    const [logoPreview, setLogoPreview] = useState<string | null>(null);
    const [removeLogo, setRemoveLogo] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (data?.data) {
            setForm(toForm(data.data));
            setLogoPreview(data.data.logo_url);
        }
    }, [data]);

    useEffect(() => () => {
        if (logoPreview?.startsWith('blob:')) URL.revokeObjectURL(logoPreview);
    }, [logoPreview]);

    if (loading) return <LoadingBlock />;
    if (error || !form) return <Alert tone="danger">{error ?? 'Company settings are unavailable.'}</Alert>;

    const set = <K extends keyof FormState>(key: K, value: FormState[K]) => setForm((f) => (f ? { ...f, [key]: value } : f));
    const upper = (key: 'gstin' | 'pan' | 'cin' | 'code') => (e: React.ChangeEvent<HTMLInputElement>) => set(key, e.target.value.toUpperCase().trim());

    const onStartMonth = (month: number) => {
        // Financial year spans 12 months; derive the end month.
        setForm((f) => (f ? { ...f, financial_year_start_month: month, financial_year_end_month: ((month + 10) % 12) + 1 } : f));
    };

    const onLogo = (file: File | undefined) => {
        if (!file) return;
        if (!LOGO_TYPES.includes(file.type)) {
            setErrors((e) => ({ ...e, logo: 'Upload a PNG, JPG or WebP image.' }));
            return;
        }
        if (file.size > MAX_LOGO_BYTES) {
            setErrors((e) => ({ ...e, logo: 'The logo must be 2 MB or smaller.' }));
            return;
        }
        setErrors(({ logo: _ignored, ...rest }) => rest);
        setLogoFile(file);
        setRemoveLogo(false);
        setLogoPreview(URL.createObjectURL(file));
    };

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        const clientErrors = validate(form);
        setErrors(clientErrors);
        if (Object.keys(clientErrors).length) {
            toast.error('Please correct the highlighted fields.');
            return;
        }

        const body = new FormData();
        body.append('_method', 'PUT'); // multipart uploads must be POSTed
        Object.entries(form).forEach(([key, value]) => {
            if (key === 'is_active') body.append(key, value ? '1' : '0');
            else body.append(key, String(value ?? ''));
        });
        if (logoFile) body.append('logo', logoFile);
        if (removeLogo) body.append('remove_logo', '1');

        setSaving(true);
        try {
            const { data: saved } = await api.post<{ data: Company }>('/company', body);
            setForm(toForm(saved.data));
            setLogoFile(null);
            setRemoveLogo(false);
            setLogoPreview(saved.data.logo_url);
            toast.success('Company settings saved.');
            void refresh(); // header shows company name/logo
        } catch (err) {
            setErrors(fieldErrors(err));
            toast.error(errorMessage(err, 'Unable to save company settings.'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <form onSubmit={onSubmit} noValidate>
            <PageHeader
                title="Company Settings"
                description="Legal and regional details used across the ERP."
                actions={editable ? <Button type="submit" loading={saving}>Save changes</Button> : undefined}
            />
            {!editable && <Alert tone="info" className="mb-6">You have read-only access to company settings.</Alert>}

            <fieldset disabled={!editable || saving} className="space-y-6">
                <Card>
                    <CardHeader title="Company identity" />
                    <CardBody className="grid grid-cols-1 gap-6 lg:grid-cols-[200px_1fr]">
                        <div>
                            <p className="mb-1.5 text-sm font-medium text-slate-700">Company logo</p>
                            <div className="flex h-32 w-full items-center justify-center overflow-hidden rounded-lg border border-dashed border-slate-300 bg-slate-50">
                                {logoPreview && !removeLogo ? (
                                    <img src={logoPreview} alt="Company logo preview" className="max-h-28 max-w-full object-contain" />
                                ) : (
                                    <Building2 className="h-10 w-10 text-slate-300" aria-hidden="true" />
                                )}
                            </div>
                            {editable && (
                                <div className="mt-2 flex gap-2">
                                    <input ref={fileInput} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={(e) => onLogo(e.target.files?.[0])} />
                                    <Button size="sm" variant="secondary" icon={<ImageUp className="h-4 w-4" />} onClick={() => fileInput.current?.click()}>
                                        Upload
                                    </Button>
                                    {logoPreview && !removeLogo && (
                                        <Button size="sm" variant="ghost" icon={<Trash2 className="h-4 w-4" />} onClick={() => { setRemoveLogo(true); setLogoFile(null); }} aria-label="Remove logo" />
                                    )}
                                </div>
                            )}
                            <p className={`mt-1.5 text-xs ${errors.logo ? 'text-red-600' : 'text-slate-500'}`}>{errors.logo ?? 'PNG, JPG or WebP, up to 2 MB.'}</p>
                        </div>
                        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <TextInput label="Company name" value={form.name} onChange={(e) => set('name', e.target.value)} error={errors.name} required />
                            <TextInput label="Legal company name" value={form.legal_name} onChange={(e) => set('legal_name', e.target.value)} error={errors.legal_name} required />
                            <TextInput label="Company code" value={form.code} onChange={upper('code')} error={errors.code} required maxLength={20} hint="Short unique code, e.g. MAIN." />
                            <div className="sm:pt-7">
                                <Toggle label="Active" description="The operating company must remain active." checked={form.is_active} onChange={(v) => set('is_active', v)} disabled={!editable} />
                                {errors.is_active && <p className="mt-1 text-xs text-red-600">{errors.is_active}</p>}
                            </div>
                        </div>
                    </CardBody>
                </Card>

                <Section title="Tax & registration" description="Indian statutory identifiers.">
                    <TextInput label="GSTIN" value={form.gstin} onChange={upper('gstin')} error={errors.gstin} maxLength={15} placeholder="27AAPFU0939F1ZV" />
                    <TextInput label="PAN" value={form.pan} onChange={upper('pan')} error={errors.pan} maxLength={10} placeholder="AAPFU0939F" />
                    <TextInput label="CIN (optional)" value={form.cin} onChange={upper('cin')} error={errors.cin} maxLength={21} wrapperClassName="sm:col-span-2" />
                </Section>

                <Section title="Contact">
                    <TextInput label="Email" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} error={errors.email} />
                    <TextInput label="Phone number" type="tel" value={form.phone} onChange={(e) => set('phone', e.target.value)} error={errors.phone} />
                    <TextInput label="Website" type="url" value={form.website} onChange={(e) => set('website', e.target.value)} error={errors.website} placeholder="https://" wrapperClassName="sm:col-span-2" />
                </Section>

                <Section title="Registered address">
                    <TextInput label="Address line 1" value={form.address_line_1} onChange={(e) => set('address_line_1', e.target.value)} error={errors.address_line_1} wrapperClassName="sm:col-span-2" />
                    <TextInput label="Address line 2" value={form.address_line_2} onChange={(e) => set('address_line_2', e.target.value)} error={errors.address_line_2} wrapperClassName="sm:col-span-2" />
                    <TextInput label="City" value={form.city} onChange={(e) => set('city', e.target.value)} error={errors.city} />
                    {form.country.toLowerCase() === 'india' ? (
                        <SelectInput label="State" value={form.state} onChange={(e) => set('state', e.target.value)} error={errors.state}>
                            <option value="">Select state</option>
                            {INDIAN_STATES.map((s) => <option key={s} value={s}>{s}</option>)}
                        </SelectInput>
                    ) : (
                        <TextInput label="State / Region" value={form.state} onChange={(e) => set('state', e.target.value)} error={errors.state} />
                    )}
                    <TextInput label="Postal code" value={form.postal_code} onChange={(e) => set('postal_code', e.target.value)} error={errors.postal_code} inputMode="numeric" />
                    <TextInput label="Country" value={form.country} onChange={(e) => set('country', e.target.value)} error={errors.country} required />
                </Section>

                <Section title="Regional & financial">
                    <SelectInput label="Default currency" value={form.currency} onChange={(e) => set('currency', e.target.value)} error={errors.currency}>
                        {CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
                    </SelectInput>
                    <SelectInput label="Timezone" value={form.timezone} onChange={(e) => set('timezone', e.target.value)} error={errors.timezone}>
                        {(TIMEZONES.includes(form.timezone) ? TIMEZONES : [form.timezone, ...TIMEZONES]).map((tz) => <option key={tz} value={tz}>{tz}</option>)}
                    </SelectInput>
                    <SelectInput label="Financial year start month" value={form.financial_year_start_month} onChange={(e) => onStartMonth(Number(e.target.value))} error={errors.financial_year_start_month}>
                        {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
                    </SelectInput>
                    <SelectInput label="Financial year end month" value={form.financial_year_end_month} onChange={(e) => set('financial_year_end_month', Number(e.target.value))} error={errors.financial_year_end_month} hint="Set automatically from the start month.">
                        {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
                    </SelectInput>
                </Section>
            </fieldset>

            {editable && (
                <div className="mt-6 flex justify-end">
                    <Button type="submit" loading={saving}>Save changes</Button>
                </div>
            )}
        </form>
    );
}
