<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;

class StructureType extends Model
{
    use TracksAuthors;

    public const DEPARTMENT = 'department';

    public const WAREHOUSE = 'warehouse';

    public const CATEGORIES = [self::DEPARTMENT, self::WAREHOUSE];

    protected $fillable = ['category', 'name', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
