import { Route } from 'react-router-dom';
import { RequirePermission } from '@/auth/ProtectedRoute';
import InventoryDashboardPage from './InventoryDashboardPage';
import InventorySettingsPage from './InventorySettingsPage';
import ReservationsPage from './ReservationsPage';
import { LedgerPage, ReportsPage } from './LedgerAndReportPages';
import { ItemStockPage, STOCK_VIEWS, StockListPage } from './StockPages';
import { OpeningStockDetailsPage, OpeningStockListPage } from './OpeningStockPages';
import { StockCountDetailsPage, StockCountListPage } from './StockCountPages';
import { InventoryDocumentDetailsPage, InventoryDocumentEditorPage, InventoryDocumentListPage } from './documents/DocumentPages';
import { DOCS, type DocConfig } from './documents/config';

function documentRoutes(config: DocConfig) {
    const path = config.base.replace('/inventory/', '');
    return (
        <Route key={config.type} path={path}>
            <Route index element={<RequirePermission permission={`${config.module}.view`}><InventoryDocumentListPage config={config} /></RequirePermission>} />
            <Route path="new" element={<RequirePermission permission={config.createPermission}><InventoryDocumentEditorPage key="new" config={config} /></RequirePermission>} />
            <Route path=":id" element={<RequirePermission permission={`${config.module}.view`}><InventoryDocumentDetailsPage config={config} /></RequirePermission>} />
            <Route path=":id/edit" element={<RequirePermission permission={config.createPermission}><InventoryDocumentEditorPage key="edit" config={config} /></RequirePermission>} />
        </Route>
    );
}

/** Module 4 routes, mounted under /inventory inside the authenticated layout. */
export function inventoryRoutes() {
    return (
        <Route path="inventory">
            <Route index element={<RequirePermission permission="inventory.dashboard.view"><InventoryDashboardPage /></RequirePermission>} />
            <Route path="stock" element={<RequirePermission permission="inventory.stock.view"><StockListPage key="stock" view={STOCK_VIEWS.stock} /></RequirePermission>} />
            <Route path="stock/:itemId" element={<RequirePermission permission="inventory.stock.view"><ItemStockPage /></RequirePermission>} />
            <Route path="wip" element={<RequirePermission permission="inventory.wip.view"><StockListPage key="wip" view={STOCK_VIEWS.wip} /></RequirePermission>} />
            <Route path="finished-goods" element={<RequirePermission permission="inventory.finished-goods.view"><StockListPage key="fg" view={STOCK_VIEWS['finished-goods']} /></RequirePermission>} />
            <Route path="qc-stock" element={<RequirePermission permission="inventory.qc-disposition.view"><StockListPage key="qc" view={STOCK_VIEWS.qc} /></RequirePermission>} />
            <Route path="scrap-stock" element={<RequirePermission permission="inventory.scrap.view"><StockListPage key="scrap" view={STOCK_VIEWS.scrap} /></RequirePermission>} />
            <Route path="opening-stock" element={<RequirePermission permission="inventory.opening-stock.view"><OpeningStockListPage /></RequirePermission>} />
            <Route path="opening-stock/:id" element={<RequirePermission permission="inventory.opening-stock.view"><OpeningStockDetailsPage /></RequirePermission>} />
            {Object.values(DOCS).map(documentRoutes)}
            <Route path="reservations" element={<RequirePermission permission="inventory.reservations.view"><ReservationsPage /></RequirePermission>} />
            <Route path="stock-counts" element={<RequirePermission permission="inventory.counts.view"><StockCountListPage /></RequirePermission>} />
            <Route path="stock-counts/:id" element={<RequirePermission permission="inventory.counts.view"><StockCountDetailsPage /></RequirePermission>} />
            <Route path="ledger" element={<RequirePermission permission="inventory.ledger.view"><LedgerPage /></RequirePermission>} />
            <Route path="reports" element={<RequirePermission permission="inventory.reports.view"><ReportsPage /></RequirePermission>} />
            <Route path="settings" element={<RequirePermission permission="inventory.settings.manage"><InventorySettingsPage /></RequirePermission>} />
        </Route>
    );
}
