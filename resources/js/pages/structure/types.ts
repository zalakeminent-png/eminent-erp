export interface UserSummary {
    id: number;
    full_name: string;
    email: string;
    employee_code: string | null;
    is_active: boolean;
}

interface Ref {
    id: number;
    code: string;
    name: string;
}

interface Audited {
    created_by?: UserSummary | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface Factory extends Audited {
    id: number;
    company_id: number;
    company?: { id: number; name: string };
    code: string;
    name: string;
    type: string;
    type_label: string;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country: string | null;
    contact_person: string | null;
    contact_number: string | null;
    email: string | null;
    is_active: boolean;
    departments_count?: number;
    warehouses_count?: number;
    users_count?: number;
}

export interface StructureType {
    id: number;
    category: 'department' | 'warehouse';
    name: string;
    description: string | null;
    sort_order: number;
    is_active: boolean;
    usage_count?: number;
}

export interface Department extends Audited {
    id: number;
    factory_id: number;
    factory?: Ref & { is_active: boolean };
    type_id: number | null;
    type?: { id: number; name: string } | null;
    code: string;
    name: string;
    description: string | null;
    manager?: UserSummary | null;
    supervisor?: UserSummary | null;
    is_active: boolean;
    members_count?: number;
    warehouses_count?: number;
}

export interface Warehouse extends Audited {
    id: number;
    factory_id: number;
    factory?: Ref & { is_active: boolean };
    department_id: number | null;
    department?: Ref | null;
    type_id: number | null;
    type?: { id: number; name: string } | null;
    code: string;
    name: string;
    description: string | null;
    manager_id: number | null;
    manager?: UserSummary | null;
    is_active: boolean;
    locations_count?: number;
}

export interface Location extends Audited {
    id: number;
    warehouse_id: number;
    warehouse?: Ref & { factory_id: number; is_active: boolean };
    parent_id: number | null;
    parent?: Ref | null;
    code: string;
    name: string;
    type: string;
    type_label: string;
    description: string | null;
    is_virtual: boolean;
    is_pickable: boolean;
    is_receivable: boolean;
    is_active: boolean;
    children_count?: number;
}

export interface LocationNode {
    id: number;
    code: string;
    name: string;
    type: string;
    type_label: string;
    is_virtual: boolean;
    is_pickable: boolean;
    is_receivable: boolean;
    is_active: boolean;
    children: LocationNode[];
}

export const FACTORY_TYPES = [
    { value: 'manufacturing', label: 'Manufacturing' },
    { value: 'warehouse', label: 'Warehouse' },
    { value: 'other', label: 'Other' },
];

export const LOCATION_TYPES = [
    { value: 'zone', label: 'Zone' },
    { value: 'room', label: 'Room' },
    { value: 'rack', label: 'Rack' },
    { value: 'shelf', label: 'Shelf' },
    { value: 'bin', label: 'Bin' },
    { value: 'floor_area', label: 'Floor Area' },
    { value: 'other', label: 'Other' },
];
