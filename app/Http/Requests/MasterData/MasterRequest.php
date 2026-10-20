<?php

namespace App\Http\Requests\MasterData;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Base request for master data. Routes use {id}; the record being edited
 * is resolved from modelClass().
 */
abstract class MasterRequest extends FormRequest
{
    public const CODE_REGEX = '/^[A-Z0-9][A-Z0-9_\-\/.]*$/';

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    private ?Model $resolved = null;

    private bool $resolvedOnce = false;

    public function current(): ?Model
    {
        if (! $this->resolvedOnce) {
            $this->resolvedOnce = true;
            $id = $this->route('id');
            $this->resolved = $id ? ($this->modelClass())::query()->find($id) : null;
        }

        return $this->resolved;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['code', 'sku_prefix'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = strtoupper(trim($this->input($field)));
            }
        }
        foreach (['name'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = preg_replace('/\s+/', ' ', trim($this->input($field)));
            }
        }
        $this->merge($merge);
    }

    protected function companyId(): ?int
    {
        return $this->current()?->company_id ?? Company::current()?->id;
    }

    protected function uniqueInCompany(string $table, string $column = 'code'): Unique
    {
        return Rule::unique($table, $column)->where('company_id', $this->companyId())->ignore($this->current()?->getKey());
    }

    /** exists + is_active, except the value already stored on the record. */
    protected function activeUnlessUnchanged(string $table, string $attribute, ?string $column = null): Exists
    {
        $currentValue = $this->current()?->getAttribute($attribute);

        return Rule::exists($table, $column ?? 'id')->where(function ($query) use ($currentValue) {
            $query->where('is_active', true);
            if ($currentValue !== null) {
                $query->orWhere('id', $currentValue);
            }
        });
    }

    /** Decimal string rule: non-negative with bounded integer/fraction digits. */
    protected function decimalRule(int $integerDigits, int $fractionDigits, bool $allowNegative = false): string
    {
        $sign = $allowNegative ? '-?' : '';

        return 'regex:/^'.$sign.'\d{1,'.$integerDigits.'}(\.\d{1,'.$fractionDigits.'})?$/';
    }

    /** Status changes go through PATCH …/status, not create/update. */
    protected function statusRule(): array
    {
        return [$this->current() ? 'prohibited' : 'sometimes', 'boolean'];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This code is already in use.',
            'code.regex' => 'Use uppercase letters, numbers and - _ / . only.',
            'is_active.prohibited' => 'Use the status action to activate or deactivate.',
        ];
    }
}
