<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    public function module(): string
    {
        return explode('.', $this->name, 2)[0];
    }

    public function action(): string
    {
        return explode('.', $this->name, 2)[1] ?? '';
    }
}
