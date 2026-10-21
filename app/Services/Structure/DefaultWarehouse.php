<?php

namespace App\Services\Structure;

use App\Models\Factory;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Warehouses are not managed separately: each factory stores its locations
 * in one internal storage area. This returns the factory's existing
 * warehouse (oldest, preferring active ones) or creates it once.
 */
class DefaultWarehouse
{
    public function for(Factory|int $factory): Warehouse
    {
        $factoryId = $factory instanceof Factory ? $factory->id : $factory;

        return $this->existing($factoryId) ?? DB::transaction(function () use ($factoryId) {
            // Serialise creation per factory.
            $factory = Factory::query()->lockForUpdate()->findOrFail($factoryId);

            return $this->existing($factoryId) ?? Warehouse::create([
                'factory_id' => $factory->id,
                'code' => 'MAIN',
                'name' => "{$factory->name} Storage",
                'is_active' => true,
            ]);
        });
    }

    private function existing(int $factoryId): ?Warehouse
    {
        return Warehouse::query()->where('factory_id', $factoryId)->orderByDesc('is_active')->orderBy('id')->first();
    }
}
