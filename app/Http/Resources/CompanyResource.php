<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'code' => $this->code,
            'logo_url' => $this->logoUrl(),
            'gstin' => $this->gstin,
            'pan' => $this->pan,
            'cin' => $this->cin,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'financial_year_start_month' => $this->financial_year_start_month,
            'financial_year_end_month' => $this->financial_year_end_month,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
