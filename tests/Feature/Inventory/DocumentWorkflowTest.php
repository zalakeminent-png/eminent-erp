<?php

namespace Tests\Feature\Inventory;

use App\Models\Inventory\InventoryDocument;
use App\Models\Inventory\InventoryTransaction;
use App\Models\MasterData\Item;
use App\Models\MasterData\ItemUomConversion;
use App\Models\User;
use App\Services\Inventory\Documents\DocumentService;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DocumentWorkflowTest extends InventoryTestCase
{
    private function docs(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function line(Item $item, string $qty, array $extra = []): array
    {
        return ['item_id' => $item->id, 'quantity' => $qty, 'uom_id' => $item->base_uom_id, ...$extra];
    }

    private function flow(InventoryDocument $doc, array $actions, ?User $user = null): InventoryDocument
    {
        foreach ($actions as $action => $payload) {
            if (is_int($action)) {
                [$action, $payload] = [$payload, []];
            }
            $doc = $this->docs()->act($doc, $action, $payload, $user ?? $this->admin);
        }

        return $doc;
    }

    private function grn(Item $item, string $qty, ?string $cost = null): InventoryDocument
    {
        return $this->docs()->create(InventoryDocument::GRN, [
            'warehouse_id' => $this->store->id,
            'supplier_id' => $this->supplier()->id,
            'supplier_invoice_number' => 'INV-1',
            'lines' => [$this->line($item, $qty, ['destination_location_id' => $this->rack->id, 'unit_cost' => $cost])],
        ], $this->admin);
    }

    public function test_grn_posts_once_and_qc_items_go_to_qc_pending(): void
    {
        $plain = $this->item('RM-GRN');
        $doc = $this->flow($this->grn($plain, '25', '12.5'), ['submit', 'post']);
        $this->assertSame('posted', $doc->status);
        $this->assertSame('25', $this->onHand($plain, $this->rack));

        // A repeated post (double click / retry) is idempotent: same document, no second posting.
        $again = $this->docs()->act($doc, 'post', [], $this->admin);
        $this->assertSame('posted', $again->status);
        // ...while an action that is invalid for the current state is refused.
        try {
            $this->docs()->act($doc, 'submit', [], $this->admin);
            $this->fail('Submit of a posted GRN accepted.');
        } catch (ConflictHttpException) {
        }
        $this->assertSame('25', $this->onHand($plain, $this->rack));
        $this->assertSame(1, InventoryTransaction::where('source_document_id', $doc->id)->count());

        $qcItem = $this->item('RM-QC', 'KG', ['requires_qc' => true]);
        $this->flow($this->grn($qcItem, '10'), ['submit', 'post']);
        $this->assertSame('0', $this->onHand($qcItem, $this->rack));
        $this->assertSame('10', $this->onHand($qcItem, $this->rack, 'qc_pending'));
        $this->assertSame(3, $doc->events()->count()); // created, submit, post
    }

    public function test_grn_converts_purchase_uom_to_base_uom(): void
    {
        $item = $this->item('RM-CONV', 'KG');
        ItemUomConversion::create(['item_id' => $item->id, 'uom_id' => $this->uom('PCS')->id, 'factor' => '2.5', 'is_active' => true]);

        $doc = $this->docs()->create(InventoryDocument::GRN, [
            'warehouse_id' => $this->store->id, 'supplier_id' => $this->supplier()->id,
            'lines' => [['item_id' => $item->id, 'quantity' => '4', 'uom_id' => $this->uom('PCS')->id, 'destination_location_id' => $this->rack->id, 'unit_cost' => '50']],
        ], $this->admin);
        $line = $doc->lines->sole();
        $this->assertSame('10', Decimal::normalize((string) $line->base_quantity));

        $this->flow($doc, ['submit', 'post']);
        $this->assertSame('10', $this->onHand($item, $this->rack));
        // 4 PCS × 50 = 200 → 20 per KG
        $this->assertSame('20', Decimal::normalize((string) InventoryTransaction::latest('id')->first()->entries->sole()->unit_cost));
    }

    public function test_transfer_issue_and_partial_receipt(): void
    {
        $item = $this->item('RM-TRF');
        $this->receive($item, $this->rack, '50');

        $doc = $this->docs()->create(InventoryDocument::TRANSFER, [
            'source_location_id' => $this->rack->id, 'destination_location_id' => $this->rack2->id,
            'lines' => [$this->line($item, '20')],
        ], $this->admin);
        $doc = $this->flow($doc, ['submit', 'approve', 'issue']);
        $this->assertSame('in_transit', $doc->status);
        $this->assertSame('30', $this->onHand($item, $this->rack));
        $this->assertSame('20', $this->onHand($item, $this->rack2, 'in_transit'));
        $this->assertSame('0', $this->onHand($item, $this->rack2));

        $lineId = $doc->lines->sole()->id;
        $receipt = ['idempotency_key' => 'rcv-0000001', 'receipts' => [['line_id' => $lineId, 'base_quantity' => '15']]];
        $doc = $this->flow($doc, ['receive' => $receipt]);
        $doc = $this->flow($doc, ['receive' => $receipt]); // retried request: no double receipt
        $this->assertSame('partially_received', $doc->status);
        $this->assertSame('15', $this->onHand($item, $this->rack2));
        $this->assertSame('5', $this->onHand($item, $this->rack2, 'in_transit'));

        // Cannot receive more than outstanding.
        try {
            $this->flow($doc, ['receive' => ['idempotency_key' => 'rcv-0000002', 'receipts' => [['line_id' => $lineId, 'base_quantity' => '6']]]]);
            $this->fail('Over-receipt accepted.');
        } catch (ValidationException) {
        }

        // Closing short requires an explanation; the shortage stays in transit.
        try {
            $this->flow($doc, ['close']);
            $this->fail('Close without remarks accepted.');
        } catch (ValidationException) {
        }
        $doc = $this->flow($doc, ['close' => ['remarks' => '5 kg damaged in transit, under investigation']]);
        $this->assertSame('closed', $doc->status);
        $this->assertSame('5', $this->onHand($item, $this->rack2, 'in_transit'));
        $this->assertBalancesMatchLedger();
    }

    public function test_transfer_without_handover_posts_in_one_step(): void
    {
        $item = $this->item('RM-QUICK');
        $this->receive($item, $this->rack, '5');
        $doc = $this->docs()->create(InventoryDocument::TRANSFER, [
            'source_location_id' => $this->rack->id, 'destination_location_id' => $this->rack2->id, 'requires_handover' => false,
            'lines' => [$this->line($item, '5')],
        ], $this->admin);
        $doc = $this->flow($doc, ['submit', 'approve', 'transfer']);

        $this->assertSame('received', $doc->status);
        $this->assertSame('5', $this->onHand($item, $this->rack2));
        $this->assertSame('5', Decimal::normalize((string) $doc->lines->sole()->received_base_quantity));
    }

    public function test_material_issue_requires_production_destination_and_return_is_limited(): void
    {
        $item = $this->item('RM-ISS');
        $this->receive($item, $this->rack, '40');

        try {
            $this->docs()->create(InventoryDocument::MATERIAL_ISSUE, [
                'source_location_id' => $this->rack->id, 'destination_location_id' => $this->rack2->id, 'lines' => [$this->line($item, '5')],
            ], $this->admin);
            $this->fail('Issue to a store location accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('destination_location_id', $e->errors());
        }

        $issue = $this->docs()->create(InventoryDocument::MATERIAL_ISSUE, [
            'source_location_id' => $this->rack->id, 'destination_location_id' => $this->wip->id, 'reference_number' => 'JOB-17',
            'lines' => [$this->line($item, '10')],
        ], $this->admin);
        $issue = $this->flow($issue, ['submit', 'approve', 'post']);
        $this->assertSame('30', $this->onHand($item, $this->rack));
        $this->assertSame('10', $this->onHand($item, $this->wip));

        // Returning more than issued is rejected.
        try {
            $this->docs()->create(InventoryDocument::MATERIAL_RETURN, [
                'original_document_id' => $issue->id, 'lines' => [$this->line($item, '11', ['condition' => 'good'])],
            ], $this->admin);
            $this->fail('Over-return accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.0.quantity', $e->errors());
        }

        $return = $this->docs()->create(InventoryDocument::MATERIAL_RETURN, [
            'original_document_id' => $issue->id,
            'lines' => [$this->line($item, '3', ['condition' => 'good']), $this->line($item, '1', ['condition' => 'damaged'])],
        ], $this->admin);
        $this->flow($return, ['submit', 'approve', 'post']);
        $this->assertSame('6', $this->onHand($item, $this->wip));
        $this->assertSame('33', $this->onHand($item, $this->rack));
        $this->assertSame('1', $this->onHand($item, $this->rack, 'qc_hold')); // damaged never back to available

        // Only 6 can still be returned.
        $this->expectException(ValidationException::class);
        $this->docs()->create(InventoryDocument::MATERIAL_RETURN, [
            'original_document_id' => $issue->id, 'lines' => [$this->line($item, '7', ['condition' => 'good'])],
        ], $this->admin);
    }

    public function test_adjustment_requires_reason_and_cannot_be_self_approved(): void
    {
        $item = $this->item('RM-ADJ');
        $this->receive($item, $this->rack, '10');
        $keeper = $this->staff(['inventory.adjustments.create', 'inventory.adjustments.approve', 'inventory.adjustments.post']);
        $manager = $this->staff(['inventory.adjustments.approve', 'inventory.adjustments.post']);

        try {
            $this->docs()->create(InventoryDocument::ADJUSTMENT, ['warehouse_id' => $this->store->id,
                'lines' => [$this->line($item, '-2', ['source_location_id' => $this->rack->id, 'from_status' => 'available'])]], $keeper);
            $this->fail('Adjustment without reason accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason_code_id', $e->errors());
        }

        $doc = $this->docs()->create(InventoryDocument::ADJUSTMENT, [
            'warehouse_id' => $this->store->id, 'reason_code_id' => $this->reason('COUNT-VAR')->id,
            'lines' => [$this->line($item, '-2', ['source_location_id' => $this->rack->id, 'from_status' => 'available'])],
        ], $keeper);
        $this->assertSame('10', Decimal::normalize((string) $doc->lines->sole()->system_base_quantity));
        $doc = $this->flow($doc, ['submit'], $keeper);

        try {
            $this->flow($doc, ['approve'], $keeper);
            $this->fail('Self-approval accepted.');
        } catch (AuthorizationException) {
        }
        $this->assertSame('10', $this->onHand($item, $this->rack));

        $this->flow($doc, ['approve', 'post'], $manager);
        $this->assertSame('8', $this->onHand($item, $this->rack));
    }

    public function test_scrap_and_qc_disposition_move_stock_between_statuses(): void
    {
        $item = $this->item('RM-SCRAP');
        $this->receive($item, $this->qcArea, '10', null, 'qc_pending');

        $qc = $this->docs()->create(InventoryDocument::QC_DISPOSITION, [
            'decision' => 'release', 'remarks' => 'Inspection report IR-9 OK',
            'lines' => [$this->line($item, '7', ['source_location_id' => $this->qcArea->id, 'destination_location_id' => $this->rack->id, 'from_status' => 'qc_pending'])],
        ], $this->admin);
        $this->flow($qc, ['post']);
        $this->assertSame('7', $this->onHand($item, $this->rack));
        $this->assertSame('3', $this->onHand($item, $this->qcArea, 'qc_pending'));

        // Scrap requires a reason code.
        try {
            $this->docs()->create(InventoryDocument::SCRAP, [
                'lines' => [$this->line($item, '3', ['source_location_id' => $this->qcArea->id, 'from_status' => 'qc_pending', 'to_status' => 'scrap'])],
            ], $this->admin);
            $this->fail('Scrap without reason accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason_code_id', $e->errors());
        }

        $scrap = $this->docs()->create(InventoryDocument::SCRAP, [
            'reason_code_id' => $this->reason('MFG-DEFECT')->id,
            'lines' => [$this->line($item, '3', ['source_location_id' => $this->qcArea->id, 'from_status' => 'qc_pending', 'to_status' => 'scrap'])],
        ], $this->admin);
        $scrap = $this->flow($scrap, ['submit', 'approve']);
        $this->assertSame('posted', $scrap->status);
        $this->assertSame('3', $this->onHand($item, $this->qcArea, 'scrap'));
        $this->assertSame('0', $this->onHand($item, $this->qcArea, 'qc_pending'));
        $this->assertBalancesMatchLedger();
    }

    public function test_actions_require_their_permission(): void
    {
        $item = $this->item('RM-PERM');
        $clerk = $this->staff(['inventory.grn.view', 'inventory.grn.create']);
        $doc = $this->docs()->create(InventoryDocument::GRN, [
            'warehouse_id' => $this->store->id, 'supplier_id' => $this->supplier()->id,
            'lines' => [$this->line($item, '1', ['destination_location_id' => $this->rack->id])],
        ], $clerk);
        $doc = $this->flow($doc, ['submit'], $clerk);

        $this->expectException(AuthorizationException::class);
        $this->flow($doc, ['post'], $clerk);
    }
}
