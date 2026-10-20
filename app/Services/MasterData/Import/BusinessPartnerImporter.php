<?php

namespace App\Services\MasterData\Import;

use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Requests\MasterData\MasterRequest;
use App\Models\MasterData\BusinessPartner;
use App\Services\ActivityLogger;
use App\Services\MasterData\NumberSequence;
use Illuminate\Database\Eloquent\Model;

/**
 * Customer or supplier import. Updating an existing party adds the role
 * without removing the other (a supplier imported as customer becomes both).
 */
class BusinessPartnerImporter extends Importer
{
    public function __construct(private readonly string $role) {}

    public function entity(): string
    {
        return $this->role === 'customer' ? 'customers' : 'suppliers';
    }

    public function label(): string
    {
        return $this->role === 'customer' ? 'Customers' : 'Suppliers';
    }

    public function createPermission(): string
    {
        return 'business-partners.create';
    }

    public function updatePermission(): string
    {
        return 'business-partners.update';
    }

    public function columns(): array
    {
        $address = fn (string $type) => [
            "{$type}_address_line_1" => ['label' => ucfirst($type).' Address Line 1', 'example' => 'Plot 12, MIDC'],
            "{$type}_address_line_2" => ['label' => ucfirst($type).' Address Line 2', 'example' => ''],
            "{$type}_city" => ['label' => ucfirst($type).' City', 'example' => 'Pune'],
            "{$type}_state" => ['label' => ucfirst($type).' State', 'example' => 'Maharashtra'],
            "{$type}_postal_code" => ['label' => ucfirst($type).' Postal Code', 'example' => '411019'],
            "{$type}_country" => ['label' => ucfirst($type).' Country', 'example' => 'India'],
        ];

        return [
            'code' => ['label' => 'Party Code', 'aliases' => ['code', 'customer code', 'supplier code'], 'example' => ''],
            'name' => ['label' => 'Party Name', 'required' => true, 'aliases' => ['name', 'customer', 'supplier', 'company'], 'example' => 'Acme Infra Pvt Ltd'],
            'contact_person' => ['label' => 'Contact Person', 'aliases' => ['contact'], 'example' => 'R. Sharma'],
            'email' => ['label' => 'Email', 'example' => 'purchase@acme.test'],
            'phone' => ['label' => 'Phone', 'aliases' => ['mobile'], 'example' => '+91 98200 12345'],
            'website' => ['label' => 'Website', 'example' => ''],
            'gstin' => ['label' => 'GSTIN', 'aliases' => ['gst', 'gst no'], 'example' => ''],
            'pan' => ['label' => 'PAN', 'example' => ''],
            'currency' => ['label' => 'Currency', 'example' => 'INR'],
            'payment_terms' => ['label' => 'Payment Terms', 'example' => '30 days'],
            'remarks' => ['label' => 'Remarks', 'example' => ''],
            ...$address('billing'),
            ...$address('shipping'),
            'is_active' => ['label' => 'Active', 'aliases' => ['status'], 'example' => 'Yes'],
        ];
    }

    public function findExisting(array $row): ?Model
    {
        $key = $this->keyOf($row);

        return $key ? BusinessPartner::query()->where('company_id', $this->companyId())->where('code', $key)->first() : null;
    }

    public function validate(array $row, ?Model $existing, array $keysInFile): array
    {
        $upper = fn ($v) => $this->blank($v) ? strtoupper(trim($v)) : null;
        $data = [
            'code' => $upper($row['code'] ?? null),
            'name' => $this->blank($row['name'] ?? null),
            'contact_person' => $this->blank($row['contact_person'] ?? null),
            'email' => $this->blank($row['email'] ?? null),
            'phone' => $this->blank($row['phone'] ?? null),
            'website' => $this->blank($row['website'] ?? null),
            'gstin' => $upper($row['gstin'] ?? null),
            'pan' => $upper($row['pan'] ?? null),
            'currency' => $upper($row['currency'] ?? null) ?? 'INR',
            'payment_terms' => $this->blank($row['payment_terms'] ?? null),
            'remarks' => $this->blank($row['remarks'] ?? null),
            'is_active' => $this->bool($row['is_active'] ?? '', true),
        ];
        foreach (['billing', 'shipping'] as $type) {
            foreach (['address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country'] as $field) {
                $data["{$type}_{$field}"] = $this->blank($row["{$type}_{$field}"] ?? null);
            }
        }

        $errors = $this->runValidator($data, [
            'code' => ['nullable', 'max:30', 'regex:'.MasterRequest::CODE_REGEX],
            'name' => ['required', 'string', 'max:200'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'regex:/^\+?[0-9][0-9\s\-]{6,20}$/'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'gstin' => ['nullable', 'size:15', 'regex:'.UpdateCompanyRequest::GSTIN_REGEX],
            'pan' => ['nullable', 'size:10', 'regex:'.UpdateCompanyRequest::PAN_REGEX],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'payment_terms' => ['nullable', 'string', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'billing_address_line_1' => ['nullable', 'string', 'max:255'],
            'shipping_address_line_1' => ['nullable', 'string', 'max:255'],
            'billing_postal_code' => ['nullable', 'max:12'],
            'shipping_postal_code' => ['nullable', 'max:12'],
            'is_active' => ['required', 'boolean'],
        ], ['is_active.required' => 'Active must be Yes or No.', 'gstin.regex' => 'The GSTIN format is invalid.', 'pan.regex' => 'The PAN format is invalid.']);

        if ($data['gstin'] && $data['pan'] && ! $errors && substr($data['gstin'], 2, 10) !== $data['pan']) {
            $errors[] = 'The GSTIN does not match the PAN.';
        }
        // Same GSTIN on a different party is almost always a duplicate.
        if ($data['gstin']) {
            $other = BusinessPartner::query()->where('company_id', $this->companyId())->where('gstin', $data['gstin'])
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->getKey()))->value('code');
            if ($other) {
                $errors[] = "GSTIN {$data['gstin']} is already used by party {$other}.";
            }
        }

        return ['errors' => $errors, 'data' => $data];
    }

    public function persist(array $data, ?Model $existing): Model
    {
        $partner = $existing ?? new BusinessPartner;
        $fields = ['name', 'contact_person', 'email', 'phone', 'website', 'gstin', 'pan', 'currency', 'payment_terms', 'remarks'];
        $before = $existing ? $existing->only([...$fields, 'is_customer', 'is_supplier', 'is_active']) : [];

        $partner->fill(collect($data)->only($fields)->all());
        $partner->code = $data['code'] ?: ($existing?->code ?? app(NumberSequence::class)->formatted('business-partner', 'BP-'));
        $partner->{$this->role === 'customer' ? 'is_customer' : 'is_supplier'} = true;
        $partner->is_active = $data['is_active'];
        $partner->save();

        foreach (['billing', 'shipping'] as $type) {
            if (! $data["{$type}_address_line_1"]) {
                continue;
            }
            $partner->addresses()->where('address_type', $type)->where('is_default', true)->delete();
            $partner->addresses()->create([
                'address_type' => $type,
                'address_line_1' => $data["{$type}_address_line_1"],
                'address_line_2' => $data["{$type}_address_line_2"],
                'city' => $data["{$type}_city"],
                'state' => $data["{$type}_state"],
                'postal_code' => $data["{$type}_postal_code"],
                'country' => $data["{$type}_country"] ?? 'India',
                'is_default' => true,
            ]);
        }
        if (! $existing && $data['contact_person']) {
            $partner->contacts()->create(['name' => $data['contact_person'], 'email' => $data['email'], 'phone' => $data['phone'], 'is_primary' => true]);
        }

        $logger = app(ActivityLogger::class);
        $existing
            ? $logger->log('business-partner.updated', $partner, ['changes' => $logger->diff($before, $partner->only(array_keys($before))), 'source' => 'import'], "Updated party {$partner->code} (import)")
            : $logger->log('business-partner.created', $partner, ['code' => $partner->code, 'party_type' => $partner->partyType(), 'source' => 'import'], "Created party {$partner->code} (import)");

        return $partner;
    }
}
