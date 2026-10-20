<?php

namespace App\Models\MasterData;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialGrade extends Model
{
    use BelongsToCompany, TracksAuthors;

    protected $fillable = ['family_id', 'code', 'name', 'standard', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(MaterialFamily::class, 'family_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'material_grade_id');
    }
}
