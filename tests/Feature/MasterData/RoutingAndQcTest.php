<?php

namespace Tests\Feature\MasterData;

use App\Models\MasterData\QcParameter;
use App\Models\MasterData\QcSpecification;

class RoutingAndQcTest extends MasterDataTestCase
{
    public function test_routing_operations_follow_the_configured_sequence(): void
    {
        $item = $this->finishedGood();
        [, $department] = $this->factoryAndDepartment();
        $cnc = $this->machine($department, ['CNC']);

        $id = $this->actingAs($this->admin)->postJson($this->api('product-routings'), [
            'item_id' => $item->id,
            'effective_from' => now()->toDateString(),
            'operations' => [
                ['sequence' => 30, 'process_id' => $this->process('POLISH')->id, 'cycle_time' => '0.5'],
                ['sequence' => 10, 'process_id' => $this->process('CAST')->id, 'setup_time' => '30'],
                ['sequence' => 20, 'process_id' => $this->process('CNC')->id, 'department_id' => $department->id, 'machine_id' => $cnc->id, 'cycle_time' => '1.25', 'time_unit' => 'minute'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.operations.0.process.code', 'CAST')
            ->assertJsonPath('data.operations.1.process.code', 'CNC')
            ->assertJsonPath('data.operations.1.machine.id', $cnc->id)
            ->assertJsonPath('data.operations.2.process.code', 'POLISH')
            ->assertJsonPath('data.operations.0.requires_qc', true) // defaulted from the process
            ->json('data.id');

        // Reordering: positions without explicit numbers follow list order.
        $this->actingAs($this->admin)->putJson($this->api("product-routings/{$id}"), [
            'effective_from' => now()->toDateString(),
            'operations' => [
                ['process_id' => $this->process('CNC')->id],
                ['process_id' => $this->process('CAST')->id],
            ],
        ])->assertOk()->assertJsonPath('data.operations.0.sequence', 1)->assertJsonPath('data.operations.0.process.code', 'CNC');

        // Duplicate sequence numbers are rejected.
        $this->actingAs($this->admin)->putJson($this->api("product-routings/{$id}"), [
            'effective_from' => now()->toDateString(),
            'operations' => [['sequence' => 1, 'process_id' => $this->process('CNC')->id], ['sequence' => 1, 'process_id' => $this->process('CAST')->id]],
        ])->assertJsonValidationErrors('operations.1.sequence');
    }

    public function test_invalid_machine_process_assignments_are_rejected(): void
    {
        $item = $this->finishedGood();
        [, $department] = $this->factoryAndDepartment();
        [, $otherDepartment] = $this->factoryAndDepartment();
        $cnc = $this->machine($department, ['CNC']);
        $inactive = $this->machine($department, ['POLISH'], ['is_active' => false]);

        $post = fn (array $op) => $this->actingAs($this->admin)->postJson($this->api('product-routings'), [
            'item_id' => $item->id, 'effective_from' => now()->toDateString(), 'operations' => [$op],
        ]);

        $post(['process_id' => $this->process('POLISH')->id, 'machine_id' => $cnc->id])
            ->assertJsonValidationErrors(['operations.0.machine_id' => 'not configured']);
        $post(['process_id' => $this->process('POLISH')->id, 'machine_id' => $inactive->id])
            ->assertJsonValidationErrors(['operations.0.machine_id' => 'inactive']);
        $post(['process_id' => $this->process('CNC')->id, 'machine_id' => $cnc->id, 'department_id' => $otherDepartment->id])
            ->assertJsonValidationErrors(['operations.0.machine_id' => 'different department']);
        $post(['process_id' => $this->process('CNC')->id, 'process_loss_percent' => '120'])
            ->assertJsonValidationErrors('operations.0.process_loss_percent');

        // Routings only for manufactured items.
        $this->actingAs($this->admin)->postJson($this->api('product-routings'), [
            'item_id' => $this->makeItem('RM-1')->id, 'effective_from' => now()->toDateString(), 'operations' => [],
        ])->assertJsonValidationErrors('item_id');
    }

    public function test_approved_routing_is_immutable_and_revisable(): void
    {
        $item = $this->finishedGood();
        $id = $this->actingAs($this->admin)->postJson($this->api('product-routings'), [
            'item_id' => $item->id, 'effective_from' => now()->toDateString(), 'operations' => [['process_id' => $this->process('CAST')->id]],
        ])->json('data.id');

        $qc = $this->createUserWithRole('QC'); // routings.view only
        $this->actingAs($qc)->getJson($this->api("product-routings/{$id}"))->assertOk();
        $this->actingAs($qc)->postJson($this->api("product-routings/{$id}/approve"))->assertForbidden();

        $this->actingAs($this->admin)->postJson($this->api("product-routings/{$id}/approve"))->assertOk();
        $this->actingAs($this->admin)->putJson($this->api("product-routings/{$id}"), ['effective_from' => now()->toDateString(), 'operations' => []])
            ->assertJsonValidationErrors('status');
        $this->actingAs($this->admin)->postJson($this->api("product-routings/{$id}/revise"))->assertCreated()->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('activity_logs', ['action' => 'routing.approved']);
    }

    private function specPayload(int $itemId, array $lines): array
    {
        return ['item_id' => $itemId, 'effective_from' => now()->toDateString(), 'lines' => $lines];
    }

    public function test_qc_numeric_tolerances_validate_correctly(): void
    {
        $item = $this->finishedGood();
        $dia = QcParameter::where('code', 'DIA')->sole();
        $visual = QcParameter::where('code', 'VISUAL')->sole();
        $finish = QcParameter::where('code', 'SURF')->sole();
        $mm = $this->uom('MM')->id;

        $post = fn (array $lines) => $this->actingAs($this->admin)->postJson($this->api('qc-specifications'), $this->specPayload($item->id, $lines));

        $post([['parameter_id' => $dia->id, 'min_value' => '25.1', 'max_value' => '24.9', 'uom_id' => $mm]])
            ->assertJsonValidationErrors(['lines.0.min_value' => 'greater than the maximum']);
        $post([['parameter_id' => $dia->id, 'target_value' => '26', 'min_value' => '24.9', 'max_value' => '25.1', 'uom_id' => $mm]])
            ->assertJsonValidationErrors(['lines.0.target_value' => 'above the maximum']);
        // Decimal comparison must be numeric, not lexical ("9.5" < "10.2").
        $post([['parameter_id' => $dia->id, 'min_value' => '9.5', 'max_value' => '10.2', 'uom_id' => $mm]])->assertCreated();
        $post([['parameter_id' => $dia->id]])->assertJsonValidationErrors('lines.0.target_value');
        // Units must match the parameter's dimension.
        $post([['parameter_id' => $dia->id, 'target_value' => '25', 'uom_id' => $this->uom('KG')->id]])
            ->assertJsonValidationErrors(['lines.0.uom_id' => 'mass']);
        // Non-numeric parameters cannot carry numeric limits.
        $post([['parameter_id' => $visual->id, 'min_value' => '1']])->assertJsonValidationErrors('lines.0.min_value');
        $post([['parameter_id' => $finish->id, 'allowed_options' => ['Chrome']]])->assertJsonValidationErrors('lines.0.allowed_options');

        $post([
            ['parameter_id' => $dia->id, 'target_value' => '25', 'min_value' => '24.95', 'max_value' => '25.05', 'uom_id' => $mm, 'inspection_method' => 'Vernier'],
            ['parameter_id' => $visual->id, 'expected_text' => 'No burrs or scratches', 'process_id' => $this->process('POLISH')->id],
            ['parameter_id' => $finish->id, 'allowed_options' => ['Mirror', 'Satin']],
        ])->assertCreated()
            ->assertJsonPath('data.lines.0.min_value', '24.95')
            ->assertJsonPath('data.lines.1.process.code', 'POLISH')
            ->assertJsonPath('data.lines.2.allowed_options', ['Mirror', 'Satin']);
    }

    public function test_qc_revisions_preserve_previous_versions(): void
    {
        $item = $this->finishedGood();
        $dia = QcParameter::where('code', 'DIA')->sole();

        $r1 = $this->actingAs($this->admin)->postJson($this->api('qc-specifications'), $this->specPayload($item->id, [
            ['parameter_id' => $dia->id, 'min_value' => '24.9', 'max_value' => '25.1', 'uom_id' => $this->uom('MM')->id],
        ]))->json('data.id');
        $this->actingAs($this->admin)->postJson($this->api("qc-specifications/{$r1}/approve"))->assertOk();

        $r2 = $this->actingAs($this->admin)->postJson($this->api("qc-specifications/{$r1}/revise"))
            ->assertCreated()->assertJsonPath('data.version', 2)->json('data.id');
        $this->actingAs($this->admin)->putJson($this->api("qc-specifications/{$r2}"), [
            'effective_from' => now()->toDateString(),
            'lines' => [['parameter_id' => $dia->id, 'min_value' => '24.95', 'max_value' => '25.05', 'uom_id' => $this->uom('MM')->id]],
        ])->assertOk();

        $this->assertSame('24.900000', (string) QcSpecification::find($r1)->lines()->first()->min_value);
        $this->assertSame('approved', QcSpecification::find($r1)->status);
        $this->actingAs($this->admin)->putJson($this->api("qc-specifications/{$r1}"), ['effective_from' => now()->toDateString(), 'lines' => []])
            ->assertJsonValidationErrors('status');

        // QC users may draft but not approve unless granted.
        $qc = $this->createUserWithRole('QC');
        $this->actingAs($qc)->postJson($this->api("qc-specifications/{$r2}/approve"))->assertForbidden();
        $qc->roles->first()->givePermissionTo('qc-specifications.approve');
        $this->actingAs($qc->fresh())->patchJson($this->api("qc-specifications/{$r1}/status"), ['is_active' => false])->assertOk();
        $this->actingAs($qc->fresh())->postJson($this->api("qc-specifications/{$r2}/approve"))->assertOk();
    }
}
