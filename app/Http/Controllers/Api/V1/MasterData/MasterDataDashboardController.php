<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\MasterData\Bom;
use App\Models\MasterData\BusinessPartner;
use App\Models\MasterData\Item;
use App\Models\MasterData\ItemCategory;
use App\Models\MasterData\Machine;
use App\Models\MasterData\ManufacturingProcess;
use App\Models\MasterData\MaterialGrade;
use App\Models\MasterData\ProductRouting;
use App\Models\MasterData\QcSpecification;
use App\Models\MasterData\UnitOfMeasure;
use App\Services\Structure\StructureScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Real record counts only; each section is included when the user may view it. */
class MasterDataDashboardController extends Controller
{
    public function __invoke(Request $request, StructureScope $scope): JsonResponse
    {
        $user = $request->user();
        $companyId = Company::current()?->id;
        $count = fn ($query) => ['active' => (clone $query)->where('is_active', true)->count(), 'inactive' => (clone $query)->where('is_active', false)->count()];
        $byStatus = fn (string $class) => $class::query()->whereHas('item', fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $sections = array_filter([
            'items' => $user->can('items.view') ? [
                ...$count(Item::query()->where('company_id', $companyId)),
                'by_type' => Item::query()->where('company_id', $companyId)->where('is_active', true)
                    ->selectRaw('item_type, COUNT(*) as total')->groupBy('item_type')->pluck('total', 'item_type'),
            ] : null,
            'item_categories' => $user->can('item-categories.view') ? $count(ItemCategory::query()->where('company_id', $companyId)) : null,
            'uoms' => $user->can('uoms.view') ? $count(UnitOfMeasure::query()) : null,
            'material_grades' => $user->can('material-grades.view') ? $count(MaterialGrade::query()->where('company_id', $companyId)) : null,
            'processes' => $user->can('processes.view') ? $count(ManufacturingProcess::query()->where('company_id', $companyId)) : null,
            'machines' => $user->can('machines.view') ? $count($scope->applyByFactory(Machine::query(), $user)) : null,
            'boms' => $user->can('boms.view') ? $byStatus(Bom::class) : null,
            'routings' => $user->can('routings.view') ? $byStatus(ProductRouting::class) : null,
            'qc_specifications' => $user->can('qc-specifications.view') ? $byStatus(QcSpecification::class) : null,
            'business_partners' => $user->can('business-partners.view') ? [
                'customers' => BusinessPartner::query()->where('company_id', $companyId)->where('is_active', true)->where('is_customer', true)->count(),
                'suppliers' => BusinessPartner::query()->where('company_id', $companyId)->where('is_active', true)->where('is_supplier', true)->count(),
                'inactive' => BusinessPartner::query()->where('company_id', $companyId)->where('is_active', false)->count(),
            ] : null,
        ], fn ($v) => $v !== null);

        $recent = $user->can('activity-logs.view')
            ? ActivityLog::query()->with('actor')
                ->where(fn ($q) => $q->where('action', 'like', 'item%')->orWhere('action', 'like', 'bom.%')->orWhere('action', 'like', 'routing.%')
                    ->orWhere('action', 'like', 'qc-%')->orWhere('action', 'like', 'business-partner.%')->orWhere('action', 'like', 'import.%')
                    ->orWhere('action', 'like', 'uom%')->orWhere('action', 'like', 'material-%')->orWhere('action', 'like', 'process.%')->orWhere('action', 'like', 'machine.%'))
                ->latest('created_at')->latest('id')->limit(10)->get()
                ->map(fn ($l) => ['id' => $l->id, 'action' => $l->action, 'description' => $l->description, 'actor' => $l->actor?->full_name, 'created_at' => $l->created_at?->toIso8601String()])
            : null;

        return response()->json(['data' => ['sections' => $sections, 'recent_activity' => $recent]]);
    }
}
