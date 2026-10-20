<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Requests\MasterData\BusinessPartnerRequest;
use App\Http\Resources\MasterData\BusinessPartnerResource;
use App\Models\MasterData\BusinessPartner;
use App\Services\MasterData\NumberSequence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customers and suppliers (independent role flags). No quotations, orders,
 * invoices or payments here.
 */
class BusinessPartnerController extends MasterCrudController
{
    protected array $searchable = ['code', 'name', 'gstin', 'email', 'contact_person'];

    protected array $with = ['contacts', 'addresses', 'creator'];

    protected function modelClass(): string
    {
        return BusinessPartner::class;
    }

    protected function resourceClass(): string
    {
        return BusinessPartnerResource::class;
    }

    protected function requestClass(): string
    {
        return BusinessPartnerRequest::class;
    }

    protected function entity(): string
    {
        return 'business-partner';
    }

    protected function label(): string
    {
        return 'business partner';
    }

    protected function updatePermission(): string
    {
        return 'business-partners.update';
    }

    protected function deactivatePermission(): string
    {
        return 'business-partners.deactivate';
    }

    protected function filterRules(): array
    {
        return ['party_type' => ['nullable', Rule::in(['customer', 'supplier', 'both'])]];
    }

    protected function applyFilters(Builder $query, array $filters, Request $request): void
    {
        match ($filters['party_type'] ?? null) {
            'customer' => $query->where('is_customer', true),
            'supplier' => $query->where('is_supplier', true),
            'both' => $query->where('is_customer', true)->where('is_supplier', true),
            default => null,
        };
    }

    protected function prepare(array $data, ?Model $model, Request $request): array
    {
        if (! $model && empty($data['code'])) {
            $data['code'] = app(NumberSequence::class)->formatted('business-partner', 'BP-');
        } elseif ($model && empty($data['code'])) {
            $data['code'] = $model->code;
        }
        $data['currency'] = $data['currency'] ?? 'INR';

        return $data;
    }

    protected function afterSave(Model $model, array $data, Request $request, bool $created): void
    {
        if (array_key_exists('contacts', $data)) {
            $model->contacts()->delete();
            $hasPrimary = false;
            foreach ($data['contacts'] as $contact) {
                $isPrimary = ! $hasPrimary && (bool) ($contact['is_primary'] ?? false);
                $hasPrimary = $hasPrimary || $isPrimary;
                $model->contacts()->create([...$contact, 'is_primary' => $isPrimary]);
            }
        }
        if (array_key_exists('addresses', $data)) {
            $model->addresses()->delete();
            foreach ($data['addresses'] as $address) {
                $model->addresses()->create([...$address, 'country' => $address['country'] ?? 'India', 'is_default' => (bool) ($address['is_default'] ?? false)]);
            }
        }
    }

    /** @return array<string, mixed> */
    protected function auditSnapshot(Model $model): array
    {
        return [...parent::auditSnapshot($model), 'party_type' => $model->partyType()];
    }
}
