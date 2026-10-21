import {
    ArrowLeftRight, BookOpen, Boxes, Building, Building2, ClipboardCheck, ClipboardList, Cog, Database, Factory, FileBarChart, FileSpreadsheet,
    FolderTree, GitBranch, Gauge, History, LayoutDashboard, Layers, ListTree, Lock, MapPin, Network, Package, PackageCheck, PackageMinus,
    PackageOpen, Ruler, Settings, ShieldCheck, SlidersHorizontal, Tags, Trash2, Undo2, Users, Warehouse, Workflow, Wrench,
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
        label: 'Inventory & Warehouse',
        icon: Package,
        children: [
            { label: 'Dashboard', to: '/inventory', icon: Gauge, permission: 'inventory.dashboard.view', end: true },
            { label: 'Stock Overview', to: '/inventory/stock', icon: Boxes, permission: 'inventory.stock.view' },
            { label: 'Opening Stock', to: '/inventory/opening-stock', icon: FileSpreadsheet, permission: 'inventory.opening-stock.view' },
            { label: 'Goods Receipts', to: '/inventory/goods-receipts', icon: PackageCheck, permission: 'inventory.grn.view' },
            { label: 'Stock Transfers', to: '/inventory/transfers', icon: ArrowLeftRight, permission: 'inventory.transfers.view' },
            { label: 'Material Issue', to: '/inventory/material-issues', icon: PackageMinus, permission: 'inventory.material-issues.view' },
            { label: 'Material Return', to: '/inventory/material-returns', icon: Undo2, permission: 'inventory.returns.view' },
            { label: 'WIP Inventory', to: '/inventory/wip', icon: Factory, permission: 'inventory.wip.view' },
            { label: 'Finished Goods', to: '/inventory/finished-goods', icon: PackageOpen, permission: 'inventory.finished-goods.view' },
            { label: 'QC Stock', to: '/inventory/qc-stock', icon: ShieldCheck, permission: 'inventory.qc-disposition.view' },
            { label: 'Scrap & Rejection', to: '/inventory/scrap', icon: Trash2, permission: 'inventory.scrap.view' },
            { label: 'Stock Reservation', to: '/inventory/reservations', icon: Lock, permission: 'inventory.reservations.view' },
            { label: 'Stock Adjustment', to: '/inventory/adjustments', icon: SlidersHorizontal, permission: 'inventory.adjustments.view' },
            { label: 'Stock Counting', to: '/inventory/stock-counts', icon: ClipboardList, permission: 'inventory.counts.view' },
            { label: 'Stock Ledger', to: '/inventory/ledger', icon: BookOpen, permission: 'inventory.ledger.view' },
            { label: 'Reports', to: '/inventory/reports', icon: FileBarChart, permission: 'inventory.reports.view' },
            { label: 'Inventory Settings', to: '/inventory/settings', icon: Settings, permission: 'inventory.settings.manage' },
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
