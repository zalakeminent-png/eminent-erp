<?php

namespace Tests\Feature\MasterData;

use App\Models\MasterData\UomConversion;
use App\Services\MasterData\UomConverter;
use App\Support\Decimal;

class UomTest extends MasterDataTestCase
{
    public function test_default_uoms_and_conversions_are_seeded(): void
    {
        $this->actingAs($this->admin)->getJson($this->api('uoms?all=1'))
            ->assertOk()->assertJsonCount(11, 'data')
            ->assertJsonFragment(['code' => 'SQFT', 'dimension' => 'area'])
            ->assertJsonFragment(['code' => 'BOX', 'universally_convertible' => false]);
    }

    public function test_uom_conversions_are_mathematically_valid(): void
    {
        $converter = app(UomConverter::class);

        $this->assertSame('1000', $converter->factor($this->uom('KG')->id, $this->uom('GM')->id));
        $this->assertSame('0.001', $converter->factor($this->uom('GM')->id, $this->uom('KG')->id));
        // Chained through MTR: 1 CM = 10 MM.
        $this->assertSame('10', $converter->factor($this->uom('CM')->id, $this->uom('MM')->id));
        $this->assertSame('10.7639104167', $converter->factor($this->uom('SQM')->id, $this->uom('SQFT')->id));
        // Round trip SQFT → SQM → SQFT is exact to the stored precision.
        $back = Decimal::mul($converter->factor($this->uom('SQFT')->id, $this->uom('SQM')->id), '10.7639104167');
        $this->assertTrue(Decimal::approximatelyEqual($back, '1'));

        $this->actingAs($this->admin)->getJson($this->api('uom-conversions/convert?quantity=2.5&from=KG&to=GM'))
            ->assertOk()->assertJsonPath('data.quantity', '2500');
        $this->actingAs($this->admin)->getJson($this->api('uom-conversions/convert?quantity=1250&from=MM&to=MTR'))
            ->assertOk()->assertJsonPath('data.quantity', '1.25');

        // 1 GM → KG = 0.001 fits KG's 3 decimals; 0.5 GM would need 4 → refused, not rounded.
        $this->actingAs($this->admin)->getJson($this->api('uom-conversions/convert?quantity=1&from=GM&to=KG'))->assertOk()->assertJsonPath('data.quantity', '0.001');
        $this->actingAs($this->admin)->getJson($this->api('uom-conversions/convert?quantity=0.5&from=GM&to=KG'))->assertUnprocessable();
    }

    public function test_incompatible_universal_conversions_are_rejected(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('uom-conversions'), ['from_uom_id' => $this->uom('PCS')->id, 'to_uom_id' => $this->uom('KG')->id, 'factor' => '0.5'])
            ->assertUnprocessable()->assertJsonValidationErrors(['factor' => 'different dimensions']);

        $this->actingAs($this->admin)->postJson($this->api('uom-conversions'), ['from_uom_id' => $this->uom('BOX')->id, 'to_uom_id' => $this->uom('PCS')->id, 'factor' => '100'])
            ->assertUnprocessable()->assertJsonValidationErrors('factor');

        $this->actingAs($this->admin)->postJson($this->api('uom-conversions'), ['from_uom_id' => $this->uom('BOX')->id, 'to_uom_id' => $this->uom('SET')->id, 'factor' => '2'])
            ->assertUnprocessable()->assertJsonValidationErrors(['factor' => 'item-specific']);

        $this->assertSame(4, UomConversion::count());
    }

    public function test_contradictory_and_invalid_conversions_are_rejected(): void
    {
        // CM → MM is implied (10); a different factor contradicts it.
        $this->actingAs($this->admin)->postJson($this->api('uom-conversions'), ['from_uom_id' => $this->uom('CM')->id, 'to_uom_id' => $this->uom('MM')->id, 'factor' => '12'])
            ->assertJsonValidationErrors(['factor' => 'contradicts']);
        // A consistent one is accepted.
        $this->actingAs($this->admin)->postJson($this->api('uom-conversions'), ['from_uom_id' => $this->uom('CM')->id, 'to_uom_id' => $this->uom('MM')->id, 'factor' => '10'])
            ->assertCreated();
        // Reverse duplicates, zero/negative factors and too many decimals are rejected.
        $this->actingAs($this->admin)->postJson($this->api('uom-conversions'), ['from_uom_id' => $this->uom('GM')->id, 'to_uom_id' => $this->uom('KG')->id, 'factor' => '0.001'])
            ->assertJsonValidationErrors(['factor' => 'reverse']);
        foreach (['0', '-5', '1.1234567890123'] as $bad) {
            $this->actingAs($this->admin)->postJson($this->api('uom-conversions'), ['from_uom_id' => $this->uom('LTR')->id, 'to_uom_id' => $this->uom('LTR')->id, 'factor' => $bad])
                ->assertJsonValidationErrors('factor');
        }
    }

    public function test_uom_changes_are_permissioned_and_audited(): void
    {
        $this->actingAs($this->createUserWithRole('Store Keeper'))->postJson($this->api('uoms'), ['code' => 'TON', 'name' => 'Tonne', 'dimension' => 'mass', 'decimal_precision' => 3])
            ->assertForbidden();

        $id = $this->actingAs($this->admin)->postJson($this->api('uoms'), ['code' => 'ton', 'name' => 'Tonne', 'dimension' => 'mass', 'decimal_precision' => 3])
            ->assertCreated()->assertJsonPath('data.code', 'TON')->json('data.id');
        $this->actingAs($this->admin)->postJson($this->api('uom-conversions'), ['from_uom_id' => $id, 'to_uom_id' => $this->uom('KG')->id, 'factor' => '1000'])->assertCreated();

        // A unit in use cannot change dimension.
        $this->actingAs($this->admin)->putJson($this->api("uoms/{$id}"), ['code' => 'TON', 'name' => 'Tonne', 'dimension' => 'length', 'decimal_precision' => 3])
            ->assertJsonValidationErrors('dimension');

        $this->assertDatabaseHas('activity_logs', ['action' => 'uom.created']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'uom-conversion.created']);
    }
}
