import { NavLink } from 'react-router-dom';
import { Factory, X } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { cx } from '@/lib/format';
import { filterNavigation, isGroup, navigation, type NavLinkItem } from './navigation';

function SidebarLink({ item, onNavigate, nested = false }: { item: NavLinkItem; onNavigate?: () => void; nested?: boolean }) {
    const Icon = item.icon;
    return (
        <NavLink
            to={item.to}
            end={item.end}
            onClick={onNavigate}
            className={({ isActive }) =>
                cx(
                    'group flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    nested && 'pl-4',
                    isActive ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                )
            }
        >
            {({ isActive }) => (
                <>
                    <Icon className={cx('h-4 w-4 shrink-0', isActive ? 'text-brand-600' : 'text-slate-400 group-hover:text-slate-600')} aria-hidden="true" />
                    {item.label}
                </>
            )}
        </NavLink>
    );
}

export function Sidebar({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { can, user } = useAuth();
    const items = filterNavigation(navigation, can);

    const content = (
        <div className="flex h-full flex-col">
            <div className="flex h-16 items-center justify-between gap-2 border-b border-slate-200 px-5">
                <div className="flex min-w-0 items-center gap-2.5">
                    {user?.company?.logo_url ? (
                        <img src={user.company.logo_url} alt="" className="h-8 w-8 rounded object-contain" />
                    ) : (
                        <span className="flex h-8 w-8 items-center justify-center rounded-md bg-brand-700 text-white">
                            <Factory className="h-4 w-4" aria-hidden="true" />
                        </span>
                    )}
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-slate-900">{user?.company?.name ?? 'Factory ERP'}</p>
                        <p className="text-[11px] uppercase tracking-wider text-slate-400">Manufacturing ERP</p>
                    </div>
                </div>
                <button type="button" onClick={onClose} className="rounded p-1 text-slate-400 hover:bg-slate-100 lg:hidden" aria-label="Close navigation">
                    <X className="h-5 w-5" />
                </button>
            </div>
            <nav className="flex-1 space-y-6 overflow-y-auto px-3 py-5" aria-label="Main navigation">
                {items.length === 0 && <p className="px-3 text-sm text-slate-500">No modules are available for your role.</p>}
                {items.map((item) =>
                    isGroup(item) ? (
                        <div key={item.label}>
                            <p className="mb-2 flex items-center gap-2 px-3 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                <item.icon className="h-3.5 w-3.5" aria-hidden="true" />
                                {item.label}
                            </p>
                            <div className="space-y-1">
                                {item.children.map((child) => (
                                    <SidebarLink key={child.to} item={child} onNavigate={onClose} nested />
                                ))}
                            </div>
                        </div>
                    ) : (
                        <div key={item.to} className="space-y-1">
                            <SidebarLink item={item} onNavigate={onClose} />
                        </div>
                    ),
                )}
            </nav>
            <div className="border-t border-slate-200 px-5 py-3 text-xs text-slate-400">Module 1 · System Settings</div>
        </div>
    );

    return (
        <>
            {/* Desktop */}
            <aside className="hidden w-64 shrink-0 border-r border-slate-200 bg-white lg:fixed lg:inset-y-0 lg:block">{content}</aside>
            {/* Mobile drawer */}
            <div className={cx('fixed inset-0 z-40 lg:hidden', open ? '' : 'pointer-events-none')} aria-hidden={!open}>
                <div className={cx('absolute inset-0 bg-slate-900/40 transition-opacity', open ? 'opacity-100' : 'opacity-0')} onClick={onClose} />
                <aside className={cx('absolute inset-y-0 left-0 w-72 max-w-[85%] bg-white shadow-xl transition-transform', open ? 'translate-x-0' : '-translate-x-full')}>
                    {content}
                </aside>
            </div>
        </>
    );
}
