export interface CodedRef {
    id: number;
    code: string;
    name: string;
}

export interface UomRef extends CodedRef {
    decimal_precision: number;
}

export interface Uom extends UomRef {
    dimension: string;
    dimension_label: string;
    universally_convertible: boolean;
    is_system: boolean;
    is_active: boolean;
}

export interface UomConversion {
    id: number;
    from_uom_id: number;
    to_uom_id: number;
    from_uom: UomRef;
    to_uom: UomRef;
    factor: string;
    is_active: boolean;
}

export interface ItemCategory extends CodedRef {
    parent_id: number | null;
    parent?: CodedRef | null;
    description: string | null;
    sku_prefix: string | null;
    default_item_type: string | null;
    is_system: boolean;
    is_active: boolean;
    items_count?: number;
    children_count?: number;
    attributes?: { id: number; code: string; name: string; data_type: string; is_required: boolean }[];
}

export interface CategoryNode {
    id: number;
    code: string;
    name: string;
    sku_prefix: string | null;
    default_item_type: string | null;
    is_active: boolean;
    is_system: boolean;
    items_count: number;
    children: CategoryNode[];
}

export interface ItemAttribute extends CodedRef {
    data_type: 'text' | 'number' | 'select' | 'boolean';
    uom_id: number | null;
    options: string[];
    sort_order: number;
    is_active: boolean;
}

export interface ItemUomConversion {
    id: number;
    item_id: number;
    uom_id: number;
    uom: UomRef;
    factor: string;
    remarks: string | null;
    is_active: boolean;
}

export interface Item {
    id: number;
    code: string;
    name: string;
    description: string | null;
    category_id: number;
    category?: CodedRef;
    item_type: string;
    item_type_label: string;
    material_grade_id: number | null;
    material_grade?: CodedRef | null;
    base_uom_id: number;
    base_uom?: UomRef;
    purchase_uom_id: number | null;
    purchase_uom?: UomRef | null;
    production_uom_id: number | null;
    production_uom?: UomRef | null;
    technical_specification: string | null;
    material: string | null;
    shape: string | null;
    size: string | null;
    length: string | null;
    width: string | null;
    height: string | null;
    diameter: string | null;
    thickness: string | null;
    dimension_uom_id: number | null;
    dimension_uom?: UomRef | null;
    surface_finish: string | null;
    color: string | null;
    weight_per_piece: string | null;
    weight_uom_id: number | null;
    weight_uom?: UomRef | null;
    customer_part_number: string | null;
    internal_part_number: string | null;
    drawing_number: string | null;
    drawing_revision: string | null;
    additional_specifications: string | null;
    is_stock_item: boolean;
    track_inventory: boolean;
    min_stock_level: string | null;
    max_stock_level: string | null;
    reorder_level: string | null;
    default_warehouse_id: number | null;
    default_warehouse?: CodedRef | null;
    default_location_id: number | null;
    default_location?: CodedRef | null;
    allow_negative_stock: boolean;
    requires_qc: boolean;
    is_purchased: boolean;
    is_manufactured: boolean;
    is_sold: boolean;
    can_have_bom: boolean;
    revision: number;
    is_active: boolean;
    image_url: string | null;
    attribute_values?: { attribute_id: number; code: string; name: string; data_type: string; value: string }[];
    uom_conversions?: ItemUomConversion[];
    created_by?: { id: number; full_name: string } | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface Document {
    id: number;
    category: string;
    title: string | null;
    revision: string | null;
    original_name: string;
    mime_type: string | null;
    size: number;
    download_url: string;
    uploaded_by?: { id: number; full_name: string } | null;
    created_at: string;
}

export type DocStatus = 'draft' | 'approved' | 'inactive';

export interface VersionedDoc {
    id: number;
    number: string;
    version: number;
    item_id: number;
    item?: { id: number; code: string; name: string; base_uom: UomRef | null };
    description: string | null;
    effective_from: string;
    effective_to: string | null;
    status: DocStatus;
    is_editable: boolean;
    approved_by?: { id: number; full_name: string } | null;
    approved_at: string | null;
    revised_from?: { id: number; number: string; version: number } | null;
    created_by?: { id: number; full_name: string } | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface BomLine {
    id?: number;
    line_no?: number;
    component_item_id: number;
    component?: { id: number; code: string; name: string; item_type: string; is_active: boolean; base_uom: UomRef | null } | null;
    quantity: string;
    uom_id: number;
    uom?: UomRef | null;
    scrap_percent: string;
    remarks: string | null;
}

export interface Bom extends VersionedDoc {
    output_qty: string;
    output_uom_id: number;
    output_uom?: UomRef;
    lines_count?: number;
    lines?: BomLine[];
}

export interface RoutingOperation {
    id?: number;
    sequence: number;
    process_id: number;
    process?: CodedRef | null;
    department_id: number | null;
    department?: CodedRef | null;
    machine_id: number | null;
    machine?: CodedRef | null;
    setup_time: string;
    cycle_time: string;
    time_unit: string;
    process_loss_percent: string;
    requires_qc: boolean;
    remarks: string | null;
}

export interface Routing extends VersionedDoc {
    operations_count?: number;
    operations?: RoutingOperation[];
}

export interface QcParameter extends CodedRef {
    description: string | null;
    data_type: 'numeric' | 'pass_fail' | 'text' | 'selection';
    default_uom_id: number | null;
    default_uom?: UomRef | null;
    options: string[];
    is_active: boolean;
}

export interface QcLine {
    id?: number;
    parameter_id: number;
    parameter?: { id: number; code: string; name: string; data_type: QcParameter['data_type']; options: string[] } | null;
    process_id: number | null;
    process?: CodedRef | null;
    target_value: string | null;
    min_value: string | null;
    max_value: string | null;
    uom_id: number | null;
    uom?: UomRef | null;
    expected_text: string | null;
    allowed_options: string[];
    inspection_method: string | null;
    instructions: string | null;
    is_required: boolean;
}

export interface QcSpecification extends VersionedDoc {
    inspection_instructions: string | null;
    lines_count?: number;
    lines?: QcLine[];
}

export interface Process extends CodedRef {
    description: string | null;
    process_type: 'internal' | 'external';
    default_department_id: number | null;
    default_department?: CodedRef | null;
    requires_qc: boolean;
    is_active: boolean;
}

export interface Machine extends CodedRef {
    factory_id: number;
    factory?: CodedRef;
    department_id: number;
    department?: CodedRef;
    processes?: CodedRef[];
    is_active: boolean;
}

export interface BusinessPartner extends CodedRef {
    party_type: 'customer' | 'supplier' | 'both' | 'none';
    is_customer: boolean;
    is_supplier: boolean;
    contact_person: string | null;
    email: string | null;
    phone: string | null;
    website: string | null;
    gstin: string | null;
    pan: string | null;
    currency: string;
    payment_terms: string | null;
    remarks: string | null;
    is_active: boolean;
    contacts?: { id?: number; name: string; designation: string | null; email: string | null; phone: string | null; is_primary: boolean }[];
    addresses?: {
        id?: number;
        address_type: 'billing' | 'shipping';
        label: string | null;
        address_line_1: string;
        address_line_2: string | null;
        city: string | null;
        state: string | null;
        postal_code: string | null;
        country: string | null;
        is_default: boolean;
    }[];
    created_at: string | null;
    updated_at: string | null;
}

export const ITEM_TYPES = [
    { value: 'raw_material', label: 'Raw Material' },
    { value: 'semi_finished', label: 'Semi-Finished Goods' },
    { value: 'finished_good', label: 'Finished Goods' },
    { value: 'consumable', label: 'Consumables' },
    { value: 'packing_material', label: 'Packing Materials' },
    { value: 'tool', label: 'Tools & Accessories' },
    { value: 'scrap', label: 'Scrap Material' },
];

export const UOM_DIMENSIONS = [
    { value: 'count', label: 'Count' },
    { value: 'packaging', label: 'Packaging' },
    { value: 'mass', label: 'Mass / Weight' },
    { value: 'length', label: 'Length' },
    { value: 'area', label: 'Area' },
    { value: 'volume', label: 'Volume' },
    { value: 'time', label: 'Time' },
];

export const QC_DATA_TYPES = [
    { value: 'numeric', label: 'Numeric' },
    { value: 'pass_fail', label: 'Pass / Fail' },
    { value: 'text', label: 'Text' },
    { value: 'selection', label: 'Selection' },
];

export const TIME_UNITS = [
    { value: 'second', label: 'Seconds' },
    { value: 'minute', label: 'Minutes' },
    { value: 'hour', label: 'Hours' },
];

export const CAPACITY_PERIODS = [
    { value: 'hour', label: 'Hour' },
    { value: 'shift', label: 'Shift' },
    { value: 'day', label: 'Day' },
];
