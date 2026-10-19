<?php

namespace App\Http\Resources\Structure;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal user reference for structure screens (no roles or permissions).
 *
 * @mixin User
 */
class UserSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'employee_code' => $this->employee_code,
            'is_active' => $this->is_active,
        ];
    }
}
