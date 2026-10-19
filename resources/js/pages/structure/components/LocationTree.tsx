import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronDown, ChevronRight, CloudOff, Plus } from 'lucide-react';
import { Badge } from '@/components/ui/Feedback';
import { cx } from '@/lib/format';
import type { LocationNode } from '../types';

function collectIds(nodes: LocationNode[], into: Set<number> = new Set()): Set<number> {
    nodes.forEach((n) => {
        if (n.children.length) {
            into.add(n.id);
            collectIds(n.children, into);
        }
    });
    return into;
}

/**
 * Expand/collapse tree of storage locations. Shows configuration flags
 * only — no stock quantities (those arrive with the Inventory module).
 */
export function LocationTree({
    nodes,
    canAddChild,
    addChildHref,
    defaultExpanded = true,
}: {
    nodes: LocationNode[];
    canAddChild?: boolean;
    addChildHref?: (node: LocationNode) => string;
    defaultExpanded?: boolean;
}) {
    const allIds = useMemo(() => collectIds(nodes), [nodes]);
    const [expanded, setExpanded] = useState<Set<number>>(() => (defaultExpanded ? allIds : new Set()));

    const toggle = (id: number) =>
        setExpanded((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });

    const render = (list: LocationNode[], level: number) => (
        <ul role={level === 0 ? 'tree' : 'group'} className={level > 0 ? 'ml-4 border-l border-slate-200 pl-2' : undefined}>
            {list.map((node) => {
                const hasChildren = node.children.length > 0;
                const isOpen = expanded.has(node.id);
                return (
                    <li key={node.id} role="treeitem" aria-expanded={hasChildren ? isOpen : undefined}>
                        <div className="group flex min-h-10 items-center gap-1.5 rounded-md px-1 py-1 hover:bg-slate-50">
                            {hasChildren ? (
                                <button type="button" onClick={() => toggle(node.id)} className="rounded p-1 text-slate-400 hover:bg-slate-200" aria-label={isOpen ? `Collapse ${node.code}` : `Expand ${node.code}`}>
                                    {isOpen ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                </button>
                            ) : (
                                <span className="w-6" aria-hidden="true" />
                            )}
                            <Link to={`/factory-setup/locations/${node.id}`} className={cx('min-w-0 flex-1 truncate text-sm', node.is_active ? 'text-slate-800' : 'text-slate-400 line-through')}>
                                <span className="font-mono text-xs font-semibold">{node.code}</span>
                                <span className="ml-2">{node.name}</span>
                            </Link>
                            <span className="hidden shrink-0 items-center gap-1 sm:flex">
                                <Badge>{node.type_label}</Badge>
                                {node.is_virtual && <Badge tone="info"><CloudOff className="mr-1 h-3 w-3" />Virtual</Badge>}
                                {!node.is_active && <Badge tone="neutral">Inactive</Badge>}
                            </span>
                            {canAddChild && addChildHref && node.is_active && (
                                <Link to={addChildHref(node)} className="shrink-0 rounded p-1 text-slate-400 hover:bg-brand-50 hover:text-brand-700" aria-label={`Add child location under ${node.code}`} title="Add child location">
                                    <Plus className="h-4 w-4" />
                                </Link>
                            )}
                        </div>
                        {hasChildren && isOpen && render(node.children, level + 1)}
                    </li>
                );
            })}
        </ul>
    );

    return (
        <div>
            {allIds.size > 0 && (
                <div className="mb-2 flex gap-3 text-xs">
                    <button type="button" className="font-medium text-brand-600 hover:text-brand-700" onClick={() => setExpanded(new Set(allIds))}>Expand all</button>
                    <button type="button" className="font-medium text-brand-600 hover:text-brand-700" onClick={() => setExpanded(new Set())}>Collapse all</button>
                </div>
            )}
            {render(nodes, 0)}
        </div>
    );
}
