import {
    Boxes, Building, Building2, ClipboardCheck, ClipboardList, Cog, Database, Factory, FileSpreadsheet, FolderTree, GitBranch, Gauge, History,
    LayoutDashboard, Layers, ListTree, MapPin, Network, Ruler, Settings, ShieldCheck, Tags, Users, Warehouse, Workflow, Wrench,
    type LucideIcon,
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
        label: 'Master Data',
        icon: Database,
        children: [
            { label: 'Dashboard', to: '/master-data', icon: Gauge, permission: 'master-data.view', end: true },
            { label: 'Item Categories', to: '/master-data/categories', icon: FolderTree, permission: 'item-categories.view' },
            { label: 'Items / Products', to: '/master-data/items', icon: Boxes, permission: 'items.view' },
            { label: 'Units of Measurement', to: '/master-data/uoms', icon: Ruler, permission: 'uoms.view' },
            { label: 'Material Grades', to: '/master-data/material-grades', icon: Layers, permission: 'material-grades.view' },
            { label: 'Manufacturing Processes', to: '/master-data/processes', icon: Cog, permission: 'processes.view' },
            { label: 'Machines & Workstations', to: '/master-data/machines', icon: Wrench, permission: 'machines.view' },
            { label: 'Bill of Materials', to: '/master-data/boms', icon: GitBranch, permission: 'boms.view' },
            { label: 'Product Routings', to: '/master-data/routings', icon: Workflow, permission: 'routings.view' },
            { label: 'QC Specifications', to: '/master-data/qc-specifications', icon: ClipboardCheck, permission: 'qc-specifications.view' },
            { label: 'QC Parameters', to: '/master-data/qc-parameters', icon: ClipboardList, permission: 'qc-specifications.view' },
            { label: 'Customers & Suppliers', to: '/master-data/partners', icon: Users, permission: 'business-partners.view' },
            { label: 'Excel Import', to: '/master-data/import', icon: FileSpreadsheet, permission: 'master-data.import' },
        ],
    },
    {
        label: 'Factory Setup',
        icon: ListTree,
        children: [
            { label: 'Structure Overview', to: '/factory-setup/structure', icon: Network, permission: 'factory-structure.view' },
            { label: 'Factories', to: '/factory-setup/factories', icon: Factory, permission: 'factories.view' },
            { label: 'Departments', to: '/factory-setup/departments', icon: Building, permission: 'departments.view' },
            { label: 'Warehouses', to: '/factory-setup/warehouses', icon: Warehouse, permission: 'warehouses.view' },
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
