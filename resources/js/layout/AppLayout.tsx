import { useEffect, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { Header } from './Header';
import { Sidebar } from './Sidebar';

export function AppLayout() {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const { pathname } = useLocation();

    useEffect(() => setSidebarOpen(false), [pathname]);

    return (
        <div className="min-h-screen bg-slate-50">
            <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />
            <div className="lg:pl-64">
                <Header onMenu={() => setSidebarOpen(true)} />
                <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}

export function AuthLayout({ children, title, subtitle }: { children: React.ReactNode; title: string; subtitle?: string }) {
    return (
        <div className="flex min-h-screen bg-white">
            <div className="relative hidden w-[44%] flex-col justify-between overflow-hidden bg-brand-900 p-10 text-white lg:flex">
                <div
                    className="pointer-events-none absolute inset-0 opacity-[0.07]"
                    style={{
                        backgroundImage: 'linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px)',
                        backgroundSize: '32px 32px',
                    }}
                    aria-hidden="true"
                />
                <div className="relative flex items-center gap-2 text-sm font-semibold tracking-wide">
                    <span className="h-2 w-2 rounded-full bg-signal-400" /> FACTORY ERP
                </div>
                <div className="relative">
                    <h2 className="text-3xl font-semibold leading-tight">Plan, produce and ship with one system of record.</h2>
                    <p className="mt-3 max-w-md text-sm text-brand-100">Secure access for your factory teams — every action permissioned and audited.</p>
                </div>
                <p className="relative text-xs text-brand-200">Authorized personnel only. Activity is logged.</p>
            </div>
            <div className="flex flex-1 items-center justify-center px-4 py-12 sm:px-8">
                <div className="w-full max-w-sm">
                    <h1 className="text-2xl font-semibold tracking-tight text-slate-900">{title}</h1>
                    {subtitle && <p className="mt-1.5 text-sm text-slate-500">{subtitle}</p>}
                    <div className="mt-8">{children}</div>
                </div>
            </div>
        </div>
    );
}
