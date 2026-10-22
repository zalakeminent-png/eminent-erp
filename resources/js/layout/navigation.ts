import {
    ArrowDownToLine, ArrowUpFromLine, Boxes, Building, Building2, Factory, History, LayoutDashboard, ListTree, MapPin, Network, Package, PackageSearch,
    Settings, ShieldCheck, Tags, Truck, Users, type LucideIcon,
} from 'lucide-react';

export interface NavLinkItem {
    label: string;
    to: string;
    icon: LucideIcon;
    /** Visible when the user holds any of these permissions. */
    permission: string | string[];
    end?: boolean;
}

export interface NavGroup {
    label: string;
    icon: LucideIcon;
    children: NavLinkItem[];
}

export type NavItem = NavLinkItem | NavGroup;

/**
 * Sidebar structure. Future ERP modules register their entries here with
 * the permission that guards them.
 */
export const navigation: NavItem[] = [
    { label: 'Dashboard', to: '/', icon: LayoutDashboard, permission: 'dashboard.view', end: true },
    {
        label: 'Inventory',
        icon: Package,
        children: [
            { label: 'Stock Overview', to: '/inventory/stock', icon: PackageSearch, permission: 'stock-overview.view' },
            { label: 'Inward Material', to: '/inventory/inward', icon: ArrowDownToLine, permission: 'inward.view' },
            { label: 'Outward Material', to: '/inventory/outward', icon: ArrowUpFromLine, permission: 'outward.view' },
            { label: 'SKU Master', to: '/inventory/skus', icon: Boxes, permission: 'skus.view' },
            { label: 'Vendor Master', to: '/inventory/vendors', icon: Truck, permission: 'vendors.view' },
        ],
    },
    {
        label: 'Factory Setup',
        icon: ListTree,
        children: [
            { label: 'Structure Overview', to: '/factory-setup/structure', icon: Network, permission: 'factory-structure.view' },
            { label: 'Factories', to: '/factory-setup/factories', icon: Factory, permission: 'factories.view' },
            { label: 'Departments', to: '/factory-setup/departments', icon: Building, permission: 'departments.view' },
            { label: 'Storage Locations', to: '/factory-setup/locations', icon: MapPin, permission: 'locations.view' },
            { label: 'Types', to: '/factory-setup/types', icon: Tags, permission: 'structure-types.view' },
        ],
    },
    {
        label: 'System Settings',
        icon: Settings,
        children: [
            { label: 'Company Settings', to: '/settings/company', icon: Building2, permission: 'company.view' },
            { label: 'Users', to: '/settings/users', icon: Users, permission: 'users.view' },
            { label: 'Roles & Permissions', to: '/settings/roles', icon: ShieldCheck, permission: 'roles.view' },
            { label: 'Activity Logs', to: '/settings/activity-logs', icon: History, permission: 'activity-logs.view' },
        ],
    },
];

export function isGroup(item: NavItem): item is NavGroup {
    return 'children' in item;
}

/** Removes items the user may not see, and groups left empty. */
export function filterNavigation(items: NavItem[], can: (permission: string | string[]) => boolean): NavItem[] {
    return items.flatMap<NavItem>((item) => {
        if (isGroup(item)) {
            const children = item.children.filter((child) => can(child.permission));
            return children.length ? [{ ...item, children }] : [];
        }
        return can(item.permission) ? [item] : [];
    });
}
