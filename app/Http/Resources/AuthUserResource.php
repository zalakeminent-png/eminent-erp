<?php

namespace App\Http\Resources;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The signed-in user's own profile, including the effective permission
 * list the SPA uses to show/hide navigation. (The API still enforces every
 * permission server-side.)
 *
 * @mixin User
 */
class AuthUserResource extends UserResource
{
    public function toArray(Request $request): array
    {
        $company = $this->company ?? Company::current();

        return [
            ...parent::toArray($request),
            'roles' => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'is_active' => $role->is_active,
            ])->values(),
            'permissions' => $this->permissionNames(),
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'code' => $company->code,
                'logo_url' => $company->logoUrl(),
            ] : null,
        ];
    }
}
