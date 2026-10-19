import { Building2, History, LayoutDashboard, Settings, ShieldCheck, Users, type LucideIcon } from 'lucide-react';

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
