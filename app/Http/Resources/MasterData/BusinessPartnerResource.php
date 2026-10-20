<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\BusinessPartner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BusinessPartner */
class BusinessPartnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'party_type' => $this->partyType(),
            'is_customer' => $this->is_customer,
            'is_supplier' => $this->is_supplier,
            'contact_person' => $this->contact_person,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'gstin' => $this->gstin,
            'pan' => $this->pan,
            'currency' => $this->currency,
            'payment_terms' => $this->payment_terms,
            'remarks' => $this->remarks,
            'is_active' => $this->is_active,
            'contacts' => $this->whenLoaded('contacts', fn () => $this->contacts->map(fn ($c) => $c->only(['id', 'name', 'designation', 'email', 'phone', 'is_primary']))->values()),
            'addresses' => $this->whenLoaded('addresses', fn () => $this->addresses->map(fn ($a) => $a->only([
                'id', 'address_type', 'label', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'is_default',
            ]))->values()),
            ...Refs::audit($this->resource),
        ];
    }
}
