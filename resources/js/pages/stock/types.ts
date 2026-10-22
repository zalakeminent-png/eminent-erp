export type DocType = 'inward' | 'outward' | 'opening';
export type DocStatus = 'draft' | 'posted' | 'reversed' | 'cancelled';
export type StockStatus = 'ok' | 'low' | 'not_set';

export interface Ref {
    id: number;
    code: string;
    name: string;
}

export interface Sku {
    id: number;
    sku: string;
    product_name: string;
    category: string | null;
    material: string | null;
    size: string | null;
    unit: string | null;
    reorder_level: string | null;
    is_active: boolean;
    vendors_count?: number | null;
    mapped?: boolean | null;
}

export interface Vendor {
    id: number;
    code: string;
    name: string;
    contact_person: string | null;
    phone: string | null;
    email: string | null;
    city: string | null;
    address: string | null;
    notes: string | null;
    is_active: boolean;
    skus_count?: number | null;
    mapped?: boolean | null;
}

export interface Mapping {
    id: number;
    is_active: boolean;
    remarks: string | null;
    vendor: { id: number; code: string; name: string; is_active: boolean };
    sku: { id: number; sku: string; product_name: string; category: string | null; material: string | null; is_active: boolean };
    created_at: string | null;
}

export interface StockRow {
    id: number;
    sku: string;
    product_name: string;
    category: string | null;
    material: string | null;
    size: string | null;
    unit: string | null;
    is_active: boolean;
    opening_stock: string;
    total_inward: string;
    total_rejected: string;
    accepted_inward: string;
    total_outward: string;
    live_balance: string;
    reorder_level: string | null;
    status: StockStatus;
    status_label: string;
}

export interface PersonRef {
    id: number;
    full_name: string;
}

export interface StockDocument {
    id: number;
    type: DocType;
    number: string;
    document_date: string;
    status: DocStatus;
    factory: Ref | null;
    location: Ref | null;
    sku: Sku | null;
    vendor: Ref | null;
    department: Ref | null;
    quantity: string;
    received_qty: string;
    rejected_qty: string;
    accepted_qty: string;
    recipient: string | null;
    job_no: string | null;
    reference: string | null;
    remarks: string | null;
    vendor_mapped: boolean | null;
    vendor_check_reason: string | null;
    created_by: PersonRef | null;
    created_at: string | null;
    posted_by: PersonRef | null;
    posted_at: string | null;
    reversed_by: PersonRef | null;
    reversed_at: string | null;
    reversal_reason: string | null;
    cancelled_by: PersonRef | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    allowed_actions: ('edit' | 'post' | 'cancel' | 'reverse' | 'edit_posted' | 'delete')[];
    edit_count: number;
    edited_by: PersonRef | null;
    edited_at: string | null;
    is_deleted: boolean;
    deleted_by: PersonRef | null;
    deleted_at: string | null;
    delete_reason: string | null;
    can_override_vendor_check: boolean;
    history?: { id: number; is_reversal: boolean; qty_in: string; qty_rejected: string; qty_out: string; balance_after: string; posted_at: string; user: string | null }[];
}

export interface Lookups {
    factories: (Ref & { is_active: boolean })[];
    locations: (Ref & { factory_id: number; parent_id: number | null; is_active: boolean })[];
    departments: (Ref & { factory_id: number; is_active: boolean })[];
    categories: string[];
    materials: string[];
    units: string[];
}

export interface ImportResult {
    dry_run: boolean;
    summary: { total: number; ready: number; created: number; skipped: number; failed: number };
    rows: { row: number; status: 'ok' | 'skipped' | 'failed'; message: string; [key: string]: unknown }[];
    message: string;
}
