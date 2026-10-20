<?php

namespace App\Services\MasterData\Import;

use App\Http\Requests\MasterData\MasterRequest;
use App\Models\MasterData\MaterialFamily;
use App\Models\MasterData\MaterialGrade;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;

class MaterialGradeImporter extends Importer
{
    public function entity(): string
    {
        return 'material-grades';
    }

    public function label(): string
    {
        return 'Material Grades';
    }

    public function createPermission(): string
    {
        return 'material-grades.create';
    }

    public function updatePermission(): string
    {
        return 'material-grades.update';
    }

    public function columns(): array
    {
        return [
            'code' => ['label' => 'Grade Code', 'required' => true, 'aliases' => ['code', 'grade'], 'example' => 'CW617N'],
            'name' => ['label' => 'Grade Name', 'required' => true, 'aliases' => ['name'], 'example' => 'Brass CW617N'],
            'family_code' => ['label' => 'Material Family Code', 'required' => true, 'aliases' => ['family', 'material family'], 'example' => 'BRASS'],
            'standard' => ['label' => 'Standard / Specification', 'aliases' => ['standard', 'specification'], 'example' => 'EN 12164'],
            'description' => ['label' => 'Description', 'example' => ''],
            'is_active' => ['label' => 'Active', 'aliases' => ['status'], 'example' => 'Yes'],
        ];
    }

    public function findExisting(array $row): ?Model
    {
        $key = $this->keyOf($row);

        return $key ? MaterialGrade::query()->where('company_id', $this->companyId())->where('code', $key)->first() : null;
    }

    public function validate(array $row, ?Model $existing, array $keysInFile): array
    {
        $data = [
            'code' => strtoupper((string) $this->blank($row['code'] ?? null)),
            'name' => $this->blank($row['name'] ?? null),
            'family_code' => strtoupper((string) $this->blank($row['family_code'] ?? null)),
            'standard' => $this->blank($row['standard'] ?? null),
            'description' => $this->blank($row['description'] ?? null),
            'is_active' => $this->bool($row['is_active'] ?? '', true),
        ];

        $errors = $this->runValidator($data, [
            'code' => ['required', 'max:30', 'regex:'.MasterRequest::CODE_REGEX],
            'name' => ['required', 'string', 'max:100'],
            'family_code' => ['required'],
            'standard' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ], ['is_active.required' => 'Active must be Yes or No.']);

        if ($data['family_code']) {
            $family = MaterialFamily::query()->where('company_id', $this->companyId())->where('code', $data['family_code'])->first();
            if (! $family) {
                $errors[] = "Material family {$data['family_code']} does not exist.";
            } elseif (! $family->is_active && $existing?->family_id !== $family->id) {
                $errors[] = "Material family {$data['family_code']} is inactive.";
            } else {
                $data['family_id'] = $family->id;
            }
        }

        return ['errors' => $errors, 'data' => $data];
    }

    public function persist(array $data, ?Model $existing): Model
    {
        $grade = $existing ?? new MaterialGrade;
        $before = $existing ? $existing->only(['name', 'family_id', 'standard', 'description', 'is_active']) : [];
        $grade->fill([
            'code' => $data['code'], 'name' => $data['name'], 'family_id' => $data['family_id'],
            'standard' => $data['standard'], 'description' => $data['description'],
        ]);
        $grade->is_active = $data['is_active'];
        $grade->save();

        $logger = app(ActivityLogger::class);
        $existing
            ? $logger->log('material-grade.updated', $grade, ['changes' => $logger->diff($before, $grade->only(array_keys($before))), 'source' => 'import'], "Updated material grade {$grade->code} (import)")
            : $logger->log('material-grade.created', $grade, ['code' => $grade->code, 'source' => 'import'], "Created material grade {$grade->code} (import)");

        return $grade;
    }
}
