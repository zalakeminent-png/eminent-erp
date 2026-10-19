<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanySettingsTest extends TestCase
{
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Eminent Castings',
            'legal_name' => 'Eminent Castings Private Limited',
            'code' => 'emc',
            'gstin' => '27AAPFU0939F1ZV',
            'pan' => 'AAPFU0939F',
            'cin' => 'U27100MH2015PTC123456',
            'email' => 'info@eminent.test',
            'phone' => '+91 22 4000 1234',
            'website' => 'https://eminent.test',
            'address_line_1' => 'Plot 12, MIDC',
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'postal_code' => '411019',
            'country' => 'India',
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'financial_year_start_month' => 4,
            'financial_year_end_month' => 3,
        ], $overrides);
    }

    public function test_admin_can_update_company_settings(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->putJson($this->api('company'), $this->validPayload())
            ->assertOk()
            ->assertJsonPath('data.name', 'Eminent Castings')
            ->assertJsonPath('data.code', 'EMC');

        $this->assertSame('27AAPFU0939F1ZV', Company::current()->gstin);
        $this->assertDatabaseHas('activity_logs', ['action' => 'company.updated', 'actor_id' => $admin->id]);
    }

    public function test_company_settings_validation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->putJson($this->api('company'), $this->validPayload([
            'name' => '',
            'gstin' => '27AAPFU0939F1Z',     // too short
            'pan' => 'ABCDE12345',           // wrong shape
            'cin' => 'X123',
            'postal_code' => '01234',        // not a valid Indian PIN
            'website' => 'javascript:alert(1)',
            'timezone' => 'Mars/Olympus',
            'financial_year_start_month' => 13,
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'name', 'gstin', 'pan', 'cin', 'postal_code', 'website', 'timezone', 'financial_year_start_month',
        ]);

        // Financial year must be 12 months and GSTIN must embed the PAN.
        $this->actingAs($admin)->putJson($this->api('company'), $this->validPayload([
            'financial_year_end_month' => 6,
            'pan' => 'BBBBB1234B',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['financial_year_end_month', 'gstin']);

        // The only company cannot be deactivated.
        $this->actingAs($admin)->putJson($this->api('company'), $this->validPayload(['is_active' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');
    }

    public function test_logo_upload_is_validated_and_stored(): void
    {
        Storage::fake('public');
        $admin = $this->createAdmin();

        $this->actingAs($admin)->put($this->api('company'), $this->validPayload([
            'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
        ]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('logo');

        $this->actingAs($admin)->put($this->api('company'), $this->validPayload([
            'logo' => UploadedFile::fake()->image('logo.png', 200, 80)->size(3000),
        ]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('logo');

        $this->actingAs($admin)->put($this->api('company'), $this->validPayload([
            'logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ]), ['Accept' => 'application/json'])->assertOk();

        $path = Company::current()->logo_path;
        $this->assertStringStartsWith('company-logos/', $path);
        $this->assertStringNotContainsString('logo.png', $path);
        Storage::disk('public')->assertExists($path);

        // Removing the logo deletes the file.
        $this->actingAs($admin)->putJson($this->api('company'), $this->validPayload(['remove_logo' => true]))->assertOk();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_company_settings_require_permission(): void
    {
        $viewer = $this->createUserWithRole('Accountant'); // has company.view only

        $this->actingAs($viewer)->getJson($this->api('company'))->assertOk();
        $this->actingAs($viewer)->putJson($this->api('company'), $this->validPayload())->assertForbidden();

        $this->actingAs($this->createUserWithRole('QC'))->getJson($this->api('company'))->assertForbidden();
    }
}
