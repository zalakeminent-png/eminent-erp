import type { DocumentType, StockStatus } from '../types';

export interface DocConfig {
    type: DocumentType;
    /** API path under /inventory, e.g. "grns". */
    endpoint: string;
    /** UI base route. */
    base: string;
    module: string;
    title: string;
    noun: string;
    description: string;
    createPermission: string[];
    statuses: string[];
    /** Related screen linked from the list header. */
    related?: { label: string; to: string; permission: string };
    /** Line editor features. */
    line: {
        destinationLocation?: 'required' | 'optional';
        sourceLocation?: boolean;
        fromStatuses?: StockStatus[];
        toStatuses?: StockStatus[];
        unitCost?: boolean;
        condition?: boolean;
        signedQuantity?: boolean;
        availability?: boolean;
    };
}

const workflowStatuses = ['draft', 'submitted', 'approved', 'posted', 'cancelled'];

export const DOCS: Record<DocumentType, DocConfig> = {
    grn: {
        type: 'grn', endpoint: 'grns', base: '/inventory/goods-receipts', module: 'inventory.grn', title: 'Goods Receipts', noun: 'GRN',
        description: 'Material received from suppliers. Items that require QC are received into QC Pending and are not available until released.',
        createPermission: ['inventory.grn.create', 'inventory.grn.update'], statuses: ['draft', 'submitted', 'posted', 'closed', 'cancelled'],
        line: { destinationLocation: 'required', unitCost: true },
    },
    transfer: {
        type: 'transfer', endpoint: 'transfers', base: '/inventory/transfers', module: 'inventory.transfers', title: 'Stock Transfers', noun: 'transfer',
        description: 'Moves between storage locations, departments and production areas. Issued stock stays In Transit until received; shortages remain visible.',
        createPermission: ['inventory.transfers.create'], statuses: ['draft', 'submitted', 'approved', 'in_transit', 'partially_received', 'received', 'closed', 'cancelled'],
        line: { fromStatuses: ['available', 'qc_pending', 'qc_hold', 'rework', 'rejected', 'scrap'], availability: true },
    },
    material_issue: {
        type: 'material_issue', endpoint: 'material-issues', base: '/inventory/material-issues', module: 'inventory.material-issues', title: 'Material Issues', noun: 'material issue',
        description: 'Issues raw materials and consumables from stores to production / WIP locations. This is a physical move, not consumption.',
        createPermission: ['inventory.material-issues.create'], statuses: workflowStatuses, line: { availability: true },
    },
    material_return: {
        type: 'material_return', endpoint: 'returns', base: '/inventory/material-returns', module: 'inventory.returns', title: 'Material Returns', noun: 'material return',
        description: 'Returns unused issued material to stores. Damaged material goes to QC Hold; material needing inspection goes to QC Pending.',
        createPermission: ['inventory.returns.create'], statuses: workflowStatuses, line: { condition: true },
    },
    adjustment: {
        type: 'adjustment', endpoint: 'adjustments', base: '/inventory/adjustments', module: 'inventory.adjustments', title: 'Stock Adjustments', noun: 'adjustment',
        description: 'Approved corrections posted as compensating ledger entries. The requester cannot approve their own adjustment.',
        createPermission: ['inventory.adjustments.create'], statuses: workflowStatuses,
        line: { sourceLocation: true, fromStatuses: ['available', 'qc_pending', 'qc_hold', 'rework', 'rejected', 'scrap', 'in_transit'], signedQuantity: true, unitCost: true, availability: true },
    },
    scrap: {
        type: 'scrap', endpoint: 'scrap', base: '/inventory/scrap', module: 'inventory.scrap', title: 'Scrap & Rejection', noun: 'scrap / rejection',
        description: 'Moves stock to scrap, rejected or rework, or authorises recovery, with a mandatory reason. Posted on approval.',
        createPermission: ['inventory.scrap.create'], statuses: ['draft', 'submitted', 'posted', 'cancelled'],
        related: { label: 'Scrap / rejected stock', to: '/inventory/scrap-stock', permission: 'inventory.scrap.view' },
        line: { sourceLocation: true, destinationLocation: 'optional', fromStatuses: ['available', 'qc_pending', 'qc_hold', 'rework', 'rejected'], toStatuses: ['scrap', 'rejected', 'rework', 'available', 'qc_pending'], availability: true },
    },
    qc_disposition: {
        type: 'qc_disposition', endpoint: 'qc-dispositions', base: '/inventory/qc-stock/dispositions', module: 'inventory.qc-disposition', title: 'QC Dispositions', noun: 'QC disposition',
        description: 'Release, hold or reject stock awaiting QC. Each decision is recorded with its reason and audit trail.',
        createPermission: ['inventory.qc-disposition.release', 'inventory.qc-disposition.hold', 'inventory.qc-disposition.reject'], statuses: ['draft', 'posted'],
        related: { label: 'QC stock', to: '/inventory/qc-stock', permission: 'inventory.qc-disposition.view' },
        line: { sourceLocation: true, destinationLocation: 'optional', fromStatuses: ['qc_pending', 'qc_hold'], availability: true },
    },
};

export const ACTION_LABELS: Record<string, string> = {
    submit: 'Submit', approve: 'Approve', post: 'Post to stock', issue: 'Issue (to in-transit)', transfer: 'Post transfer',
    receive: 'Receive', close: 'Close', cancel: 'Cancel',
};

export const ACTION_TEXT: Record<string, string> = {
    submit: 'Submit this document for approval / posting? Lines can no longer be edited.',
    approve: 'Approve this document?',
    post: 'Post this document to the stock ledger? Posted stock cannot be edited; corrections need an adjustment.',
    issue: 'Issue the stock? It leaves the source location and is shown as In Transit until received.',
    transfer: 'Post the transfer? Stock moves from source to destination immediately.',
    close: 'Close this document?',
    cancel: 'Cancel this document? Give a reason.',
};
