import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ChevronDown, KeyRound, LogOut, Menu, UserCircle } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { initials } from '@/lib/format';

export function Header({ onMenu }: { onMenu: () => void }) {
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const close = (e: MouseEvent) => {
            if (!menuRef.current?.contains(e.target as Node)) setOpen(false);
        };
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    if (!user) return null;
    const roleNames = user.roles.map((r) => r.name).join(', ') || 'No role';

    const onLogout = async () => {
        setOpen(false);
        await logout();
        navigate('/login', { replace: true });
    };

    return (
        <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/95 px-4 backdrop-blur sm:px-6">
            <button type="button" onClick={onMenu} className="rounded-md p-2 text-slate-500 hover:bg-slate-100 lg:hidden" aria-label="Open navigation">
                <Menu className="h-5 w-5" />
            </button>
            <div className="flex min-w-0 items-center gap-2 lg:hidden">
                {user.company?.logo_url && <img src={user.company.logo_url} alt="" className="h-7 w-7 rounded object-contain" />}
                <span className="truncate text-sm font-semibold text-slate-900">{user.company?.name}</span>
            </div>

            <div className="ml-auto" ref={menuRef}>
                <div className="relative">
                    <button
                        type="button"
                        onClick={() => setOpen((v) => !v)}
                        className="flex items-center gap-3 rounded-md px-2 py-1.5 hover:bg-slate-100"
                        aria-haspopup="menu"
                        aria-expanded={open}
                    >
                        <span className="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">{initials(user.full_name)}</span>
                        <span className="hidden text-left sm:block">
                            <span className="block max-w-[12rem] truncate text-sm font-medium text-slate-900">{user.full_name}</span>
                            <span className="block max-w-[12rem] truncate text-xs text-slate-500">{roleNames}</span>
                        </span>
                        <ChevronDown className="h-4 w-4 text-slate-400" aria-hidden="true" />
                    </button>
                    {open && (
                        <div role="menu" className="absolute right-0 mt-2 w-60 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
                            <div className="border-b border-slate-100 px-4 py-3">
                                <p className="truncate text-sm font-medium text-slate-900">{user.full_name}</p>
                                <p className="truncate text-xs text-slate-500">{user.email}</p>
                                <p className="mt-1 truncate text-xs text-brand-700">{roleNames}</p>
                            </div>
                            <div className="py-1">
                                <Link role="menuitem" to="/profile" onClick={() => setOpen(false)} className="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                    <UserCircle className="h-4 w-4 text-slate-400" aria-hidden="true" /> My Profile
                                </Link>
                                <Link role="menuitem" to="/profile/password" onClick={() => setOpen(false)} className="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                    <KeyRound className="h-4 w-4 text-slate-400" aria-hidden="true" /> Change Password
                                </Link>
                                <button role="menuitem" type="button" onClick={onLogout} className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50">
                                    <LogOut className="h-4 w-4" aria-hidden="true" /> Logout
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}
