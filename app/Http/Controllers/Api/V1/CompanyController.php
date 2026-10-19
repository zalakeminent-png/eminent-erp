<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CompanyController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function show(): CompanyResource
    {
        return new CompanyResource($this->company());
    }

    public function update(UpdateCompanyRequest $request): CompanyResource
    {
        $company = $this->company();
        $disk = Storage::disk(config('erp.company.logo_disk'));
        $data = collect($request->validated())->except(['logo', 'remove_logo'])->all();

        $before = $company->only(array_keys($data));
        $oldLogo = $company->logo_path;
        $newLogo = null;

        if ($request->hasFile('logo')) {
            // Stored under a random name; the original client filename is never used.
            $newLogo = $request->file('logo')->store('company-logos', config('erp.company.logo_disk'));
        }

        try {
            DB::transaction(function () use ($company, $data, $newLogo, $request) {
                $company->fill($data);
                if ($newLogo) {
                    $company->logo_path = $newLogo;
                } elseif ($request->boolean('remove_logo')) {
                    $company->logo_path = null;
                }
                $company->save();
            });
        } catch (\Throwable $e) {
            if ($newLogo) {
                $disk->delete($newLogo);
            }
            throw $e;
        }

        if ($oldLogo && $oldLogo !== $company->logo_path) {
            $disk->delete($oldLogo);
        }

        $metadata = ['changes' => $this->activity->diff($before, $company->only(array_keys($data)))];
        if ($oldLogo !== $company->logo_path) {
            $metadata['logo'] = $company->logo_path ? 'replaced' : 'removed';
        }
        $this->activity->log('company.updated', $company, $metadata, 'Updated company settings');

        return new CompanyResource($company);
    }

    private function company(): Company
    {
        return Company::current() ?? abort(404, 'Company has not been configured. Run the database seeder.');
    }
}
