<?php

namespace App\Http\Requests\Structure;

use App\Services\Structure\StructureScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared helpers for factory-structure requests.
 */
abstract class StructureRequest extends FormRequest
{
    public const CODE_REGEX = '/^[A-Z0-9][A-Z0-9_\-\/.]*$/';

    /** The record being updated (null on create). */
    abstract protected function current(): ?Model;

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => preg_replace('/\s+/', ' ', trim($this->input('name')))]);
        }
    }

    protected function scope(): StructureScope
    {
        return app(StructureScope::class);
    }

    /**
     * exists rule that additionally requires is_active, unless the value is
     * the one already stored (existing links to since-deactivated records
     * stay valid; only NEW selections must be active).
     */
    protected function activeUnlessUnchanged(string $table, string $attribute): Exists
    {
        $currentValue = $this->current()?->getAttribute($attribute);

        return Rule::exists($table, 'id')->where(function ($query) use ($currentValue) {
            $query->where('is_active', true);
            if ($currentValue !== null) {
                $query->orWhere('id', $currentValue);
            }
        });
    }

    /** Factory must exist, be in the user's data scope and (if newly chosen) be active. */
    protected function factoryRule(): Exists
    {
        $ids = $this->scope()->factoryIds($this->user());
        $rule = $this->activeUnlessUnchanged('factories', 'factory_id');

        return $ids === null ? $rule : $rule->whereIn('id', $ids ?: [0]);
    }
}
