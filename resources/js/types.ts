export interface RoleSummary {
    id: number;
    name: string;
    is_active: boolean;
}

export interface User {
    id: number;
    employee_code: string | null;
    first_name: string;
    last_name: string;
    full_name: string;
    email: string;
    mobile: string | null;
    department_id: number | null;
    factory_id?: number | null;
    factory?: { id: number; code: string; name: string } | null;
    department?: { id: number; code: string; name: string; factory_id: number } | null;
    additional_departments?: { id: number; code: string; name: string; factory_id: number }[];
    is_active: boolean;
    last_login_at: string | null;
    deactivated_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    roles?: RoleSummary[];
}

export interface AuthUser extends User {
    roles: RoleSummary[];
    permissions: string[];
    company: { id: number; name: string; code: string; logo_url: string | null } | null;
}

export interface Role {
    id: number;
    name: string;
    description: string | null;
    guard_name: string;
    is_system: boolean;
    is_primary_admin: boolean;
    is_active: boolean;
    users_count?: number;
    permissions_count?: number;
    permissions?: string[];
    created_at: string | null;
    updated_at: string | null;
}

export interface Company {
    id: number;
    name: string;
    legal_name: string | null;
    code: string;
    logo_url: string | null;
    gstin: string | null;
    pan: string | null;
    cin: string | null;
    email: string | null;
    phone: string | null;
    website: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country: string;
    currency: string;
    timezone: string;
    financial_year_start_month: number;
    financial_year_end_month: number;
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
}

export interface ActivityLog {
    id: number;
    action: string;
    entity_type: string | null;
    entity_id: number | null;
    description: string | null;
    actor: { id: number; full_name: string; email: string } | null;
    ip_address: string | null;
    user_agent: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
}

export interface PermissionModule {
    key: string;
    label: string;
    group: string;
    reserved: boolean;
    permissions: { name: string; action: string; standard: boolean }[];
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface Paginated<T> {
    data: T[];
    meta: PaginationMeta;
}
