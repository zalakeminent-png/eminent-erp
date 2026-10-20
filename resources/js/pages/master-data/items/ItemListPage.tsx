import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Download, Eye, Package, Pencil, Plus } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { errorMessage } from '@/lib/api';
import type { Paginated } from '@/types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '@/pages/structure/components/ListControls';
import { downloadFile } from '../components/shared';
import { ITEM_TYPES, type CategoryNode, type Item } from '../types';

function flatten(nodes: CategoryNode[], depth = 0, out: { node: CategoryNode; depth: number }[] = []) {
    nodes.forEach((n) => { out.push({ node: n, depth }); flatten(n.children, depth + 1, out); });
    return out;
}

export default function ItemListPage() {
    const { can } = useAuth();
    const toast = useToast();
    const list = useListParams({ sort: 'code' });
    const [search, setSearch] = useState(list.get('search'));
    const [exporting, setExporting] = useState(false);
    const debounced = useDebouncedValue(search);

    const query = {
        search: debounced, category_id: list.get('category_id'), item_type: list.get('item_type'), status: list.get('status'),
        sort: list.sort, direction: list.direction, page: list.page, per_page: 15,
    };
    const { data, loading, error } = useApi<Paginated<Item>>('/items', query);
    const tree = useApi<{ data: CategoryNode[] }>('/item-categories/tree');
    const categories = useMemo(() => flatten(tree.data?.data ?? []), [tree.data]);

    const exportItems = async (format: 'xlsx' | 'csv') => {
        setExporting(true);
        try {
            const { page: _p, per_page: _pp, ...filters } = query;
            await downloadFile('/items/export', { ...filters, format }, `items.${format}`);
        } catch (err) {
            toast.error(errorMessage(err, 'Export failed.'));
        } finally {
            setExporting(false);
        }
    };

    return (
        <>
            <PageHeader
                title="Items / Products"
                description="Item master: definitions and stock configuration. Stock balances are calculated by the Inventory module."
                actions={
                    <>
                        {can('items.export') && <Button variant="secondary" icon={<Download className="h-4 w-4" />} loading={exporting} onClick={() => exportItems('xlsx')}>Export</Button>}
                        {can('items.create') && <ButtonLink to="/master-data/items/new" icon={<Plus className="h-4 w-4" />}>Add item</ButtonLink>}
                    </>
                }
            />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search code, name, drawing or part no." />
                    <select aria-label="Filter by category" value={list.get('category_id')} onChange={(e) => list.update({ category_id: e.target.value })} className={filterInputClass}>
                        <option value="">All categories</option>
                        {categories.map(({ node, depth }) => <option key={node.id} value={node.id}>{'  '.repeat(depth)}{node.name}</option>)}
                    </select>
                    <select aria-label="Filter by item type" value={list.get('item_type')} onChange={(e) => list.update({ item_type: e.target.value })} className={filterInputClass}>
                        <option value="">All item types</option>
                        {ITEM_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                    </select>
                    <select aria-label="Filter by status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </FilterBar>
                {error ? (
                    <div className="p-4"><Alert tone="danger">{error}</Alert></div>
                ) : loading && !data ? (
                    <LoadingBlock />
                ) : data && data.data.length === 0 ? (
                    <EmptyState icon={<Package className="h-6 w-6" />} title={list.hasFilters ? 'No items match your filters' : 'No items yet'}
                        description={list.hasFilters ? undefined : 'Add items one by one or import them from Excel.'}
                        action={!list.hasFilters && can('master-data.import') ? <ButtonLink to="/master-data/import" variant="secondary">Import from Excel</ButtonLink> : undefined} />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <SortableTh label="Item code" field="code" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Item" field="name" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th className="hidden md:table-cell">Category</Th>
                                    <SortableTh label="Type" field="item_type" sort={list.sort} direction={list.direction} onSort={list.onSort} className="hidden lg:table-cell" />
                                    <Th className="hidden sm:table-cell">Base UOM</Th>
                                    <SortableTh label="Rev." field="revision" sort={list.sort} direction={list.direction} onSort={list.onSort} className="hidden lg:table-cell" />
                                    <Th>Status</Th>
                                    <Th className="text-right"><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((item) => (
                                    <tr key={item.id} className="hover:bg-slate-50">
                                        <Td className="whitespace-nowrap font-mono text-xs font-semibold">{item.code}</Td>
                                        <Td>
                                            <Link to={`/master-data/items/${item.id}`} className="flex items-center gap-2 font-medium text-slate-900 hover:text-brand-700">
                                                {item.image_url && <img src={item.image_url} alt="" className="h-8 w-8 shrink-0 rounded border border-slate-200 object-cover" />}
                                                <span>{item.name}{item.material_grade && <span className="block text-xs font-normal text-slate-500">{item.material_grade.code}</span>}</span>
                                            </Link>
                                        </Td>
                                        <Td className="hidden md:table-cell">{item.category?.name}</Td>
                                        <Td className="hidden lg:table-cell"><Badge>{item.item_type_label}</Badge></Td>
                                        <Td className="hidden font-mono text-xs sm:table-cell">{item.base_uom?.code}</Td>
                                        <Td className="hidden tabular-nums lg:table-cell">{item.revision}</Td>
                                        <Td><StatusBadge active={item.is_active} /></Td>
                                        <Td className="whitespace-nowrap text-right">
                                            <div className="flex justify-end gap-1">
                                                <ButtonLink to={`/master-data/items/${item.id}`} variant="ghost" size="sm" aria-label={`View ${item.code}`} icon={<Eye className="h-4 w-4" />} />
                                                {can('items.update') && <ButtonLink to={`/master-data/items/${item.id}/edit`} variant="ghost" size="sm" aria-label={`Edit ${item.code}`} icon={<Pencil className="h-4 w-4" />} />}
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={(p) => list.update({ page: String(p) })} />
                    </div>
                ) : null}
            </Card>
        </>
    );
}
