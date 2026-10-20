<?php

namespace App\Http\Resources\MasterData;

use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;

/** Compact references used inside master data resources. */
final class Refs
{
    /** @return array{id: int, code: string, name: string}|null */
    public static function coded(?Model $model): ?array
    {
        return $model ? ['id' => $model->id, 'code' => $model->code, 'name' => $model->name] : null;
    }

    /** @return array{id: int, code: string, name: string, decimal_precision: int}|null */
    public static function uom(?Model $uom): ?array
    {
        return $uom ? ['id' => $uom->id, 'code' => $uom->code, 'name' => $uom->name, 'decimal_precision' => $uom->decimal_precision] : null;
    }

    /** @return array{id: int, full_name: string}|null */
    public static function user(?Model $user): ?array
    {
        return $user ? ['id' => $user->id, 'full_name' => $user->full_name] : null;
    }

    public static function decimal(mixed $value): ?string
    {
        return $value === null ? null : Decimal::normalize((string) $value);
    }

    /** @return array<string, mixed> */
    public static function audit(Model $model): array
    {
        return [
            'created_by' => $model->relationLoaded('creator') ? self::user($model->creator) : null,
            'created_at' => $model->created_at?->toIso8601String(),
            'updated_at' => $model->updated_at?->toIso8601String(),
        ];
    }
}
