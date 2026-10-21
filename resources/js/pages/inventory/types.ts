import type { CodedRef, UomRef } from '../master-data/types';

export type StockStatus = 'available' | 'qc_pending' | 'qc_hold' | 'rework' | 'rejected' | 'scrap' | 'in_transit';

export interface LocationRef {
    id: number;
    code: string;
    name: string;
    warehouse_id: number;
    warehouse_code: string | null;
    purpose: string;
}

export interface UserRef {
    id: number;
    full_name: string;
}

export type DocumentType = 'grn' | 'transfer' | 'material_issue' | 'material_return' | 'adjustment' | 'scrap' | 'qc_disposition';

export interface DocumentLine {
    id: number;
    line_no: number;
    item: CodedRef | null;
    quantity: string;
    uom: UomRef | null;
    conversion_factor: string;
    base_quantity: string;
    base_uom: UomRef | null;
    source_location: LocationRef | null;
    destination_location: LocationRef | null;
    from_status: StockStatus | null;
    to_status: StockStatus | null;
    unit_cost: string | null;
    system_base_quantity: string | null;
    new_base_quantity: string | null;
    received_base_quantity: string;
    outstanding_base_quantity: string;
    condition: 'good' | 'damaged' | null;
    remarks: string | null;
}

export interface DocumentEvent {
    id: number;
    action: string;
    from_status: string | null;
    to_status: string | null;
    transaction_number: string | null;
    remarks: string | null;
    user: UserRef | null;
    created_at: string;
}

export interface InventoryDocument {
    id: number;
    document_type: DocumentType;
    document_number: string;
    document_date: string;
    status: string;
    factory?: CodedRef | null;
    warehouse?: CodedRef | null;
    source_location?: LocationRef | null;
    destination_location?: LocationRef | null;
    department?: CodedRef | null;
    supplier?: CodedRef | null;
    supplier_invoice_number: string | null;
    reference_number: string | null;
    original_document?: { id: number; document_number: string } | null;
    reason_code?: CodedRef | null;
    requires_handover: boolean;
    decision: string | null;
    remarks: string | null;
    line_count?: number;
    requested_by?: UserRef | null;
    approved_by?: UserRef | null;
    issued_by?: UserRef | null;
    received_by?: UserRef | null;
    posted_by?: UserRef | null;
    submitted_at: string | null;
    approved_at: string | null;
    issued_at: string | null;
    received_at: string | null;
    posted_at: string | null;
    closed_at: string | null;
    lines?: DocumentLine[];
    events?: DocumentEvent[];
    attachments?: { id: number; original_name: string; size: number; created_at: string }[];
    allowed_actions?: string[];
    created_at: string | null;
}

export interface BalanceRow {
    id: number;
    item: CodedRef & { item_type: string | null; category: CodedRef | null };
    warehouse: CodedRef | null;
    department: CodedRef | null;
    location: LocationRef | null;
    stock_status: StockStatus;
    quantity: string;
    uom: UomRef | null;
    value: string | null;
    last_movement_at: string | null;
    warehouse_reserved?: string;
    warehouse_available_to_promise?: string;
}

export interface LedgerRow {
    id: number;
    posted_at: string;
    transaction_id: number;
    transaction_number: string;
    transaction_type: string;
    reversal_of_id: number | null;
    item: CodedRef | null;
    warehouse: CodedRef | null;
    location: LocationRef | null;
    stock_status: StockStatus;
    quantity: string;
    quantity_in: string | null;
    quantity_out: string | null;
    uom: UomRef | null;
    running_balance: string;
    unit_cost: string | null;
    value_delta: string | null;
    counterparty: string | null;
    source_document_type: string | null;
    source_document_id: number | null;
    remarks: string | null;
    created_by: UserRef | null;
}

export interface Lookups {
    factories: (CodedRef & { is_active: boolean })[];
    departments: (CodedRef & { factory_id: number; is_active: boolean })[];
    warehouses: (CodedRef & { factory_id: number; department_id: number | null; stock_purpose: string | null; is_active: boolean })[];
    stock_statuses: { value: StockStatus; label: string }[];
    stock_purposes: { value: string; label: string }[];
}

export interface ReasonCode {
    id: number;
    code: string;
    name: string;
    category: string;
    is_active: boolean;
}

export interface ByUom {
    uom: string;
    quantity: string;
    skus: number;
    value: string;
    unvalued_balances: number;
}
