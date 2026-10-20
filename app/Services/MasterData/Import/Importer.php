<?php

namespace App\Services\MasterData\Import;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

/**
 * One importable entity. Rows are validated with the same rules as the API
 * and persisted through the same services, so imports cannot bypass
 * business rules.
 */
abstract class Importer
{
    abstract public function entity(): string;

    abstract public function label(): string;

    /** Permission needed to create records. */
    abstract public function createPermission(): string;

    /** Permission needed to update existing records. */
    abstract public function updatePermission(): string;

    /**
     * @return array<string, array{label: string, required?: bool, aliases?: list<string>, example?: string}>
     */
    abstract public function columns(): array;

    /** Finds the existing record a row refers to (by its key column). */
    abstract public function findExisting(array $row): ?Model;

    /**
     * Validates a mapped row.
     *
     * @param  array<string, string>  $row  column key => raw cell text
     * @param  array<string, true>  $keysInFile  keys of earlier valid rows
     * @return array{errors: list<string>, data: array<string, mixed>}
     */
    abstract public function validate(array $row, ?Model $existing, array $keysInFile): array;

    /** Persists a validated row (inside the caller's transaction). */
    abstract public function persist(array $data, ?Model $existing): Model;

    public function keyColumn(): ?string
    {
        return 'code';
    }

    /** Key used for duplicate detection; null rows (e.g. blank auto codes) are never duplicates. */
    public function keyOf(array $row): ?string
    {
        $column = $this->keyColumn();
        $value = $column ? strtoupper(trim($row[$column] ?? '')) : '';

        return $value === '' ? null : $value;
    }

    protected function companyId(): ?int
    {
        return Company::current()?->id;
    }

    /** @return list<string> flattened "Field: message" errors */
    protected function runValidator(array $data, array $rules, array $messages = [], ?callable $after = null): array
    {
        $validator = Validator::make($data, $rules, $messages, $this->attributeNames());
        if ($after) {
            $validator->after(fn ($v) => $after($v, $data));
        }

        return $validator->fails() ? array_values($validator->errors()->all()) : [];
    }

    /** @return array<string, string> */
    protected function attributeNames(): array
    {
        return collect($this->columns())->mapWithKeys(fn ($c, $key) => [$key => $c['label']])->all();
    }

    protected function blank(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' || $value === null ? null : (string) $value;
    }

    /** yes/no/true/false/1/0/y/n/active/inactive → bool; null when blank or unrecognised. */
    protected function bool(mixed $value, ?bool $default = null): ?bool
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return $default;
        }

        return match ($value) {
            '1', 'yes', 'y', 'true', 'active' => true,
            '0', 'no', 'n', 'false', 'inactive' => false,
            default => null,
        };
    }
}
