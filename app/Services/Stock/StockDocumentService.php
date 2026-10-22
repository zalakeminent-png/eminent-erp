<?php

namespace App\Services\Stock;

use App\Models\Department;
use App\Models\Factory;
use App\Models\Stock\Sku;
use App\Models\Stock\StockDocument;
use App\Models\Stock\Vendor;
use App\Models\Stock\VendorSku;
use App\Models\User;
use App\Models\WarehouseLocation;
use App\Services\ActivityLogger;
use App\Services\Structure\StructureScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Creates and edits draft stock documents (inward, outward, opening stock)
 * and cancels drafts. Posting and reversal live in StockPostingService.
 */
class StockDocumentService
{
    public const PREFIXES = [StockDocument::INWARD => 'INW-', StockDocument::OUTWARD => 'OUT-', StockDocument::OPENING => 'OPN-'];

    public const LABELS = [StockDocument::INWARD => 'Inward', StockDocument::OUTWARD => 'Outward', StockDocument::OPENING => 'Opening stock'];

    /** Up to 12 digits and 3 decimals. */
    public const QTY = '/^\d{1,12}(\.\d{1,3})?$/';

    public function __construct(
        private readonly DocumentNumber $numbers,
        private readonly StructureScope $scope,
        private readonly ActivityLogger $activity,
    ) {}

    public function create(string $type, array $input, User $user): StockDocument
    {
        $data = $this->validated($type, $input, $user);

        return DB::transaction(function () use ($type, $data, $user) {
            $document = new StockDocument($data);
            $document->type = $type;
            $document->status = StockDocument::DRAFT;
            $document->number = $this->numbers->next("stock-{$type}", self::PREFIXES[$type]);
            $document->vendor_mapped = $this->vendorMapped($document);
            $document->save();
            $this->activity->log("stock.{$type}.created", $document, ['number' => $document->number], self::LABELS[$type]." {$document->number} created", $user);

            return $document;
        });
    }

    public function update(StockDocument $document, array $input, User $user): StockDocument
    {
        $this->scope->ensureFactory($user, $document->factory_id);
        $data = $this->validated($document->type, $input, $user);

        return DB::transaction(function () use ($document, $data, $user) {
            $document = StockDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== StockDocument::DRAFT) {
                throw new ConflictHttpException('Only draft entries can be edited. Posted entries can only be reversed.');
            }
            $document->fill($data);
            $document->vendor_mapped = $this->vendorMapped($document);
            $document->save();
            $this->activity->log("stock.{$document->type}.updated", $document, ['number' => $document->number], self::LABELS[$document->type]." {$document->number} updated", $user);

            return $document;
        });
    }

    public function cancel(StockDocument $document, ?string $reason, User $user): StockDocument
    {
        $this->scope->ensureFactory($user, $document->factory_id);

        return DB::transaction(function () use ($document, $reason, $user) {
            $document = StockDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== StockDocument::DRAFT) {
                throw new ConflictHttpException('Only drafts can be cancelled. Use Reverse for a posted entry.');
            }
            $document->forceFill([
                'status' => StockDocument::CANCELLED, 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason,
            ])->save();
            $this->activity->log("stock.{$document->type}.cancelled", $document, ['number' => $document->number, 'reason' => $reason], self::LABELS[$document->type]." {$document->number} cancelled", $user);

            return $document;
        });
    }

    /** True when the vendor has an active mapping to the SKU (inward only). */
    public function vendorMapped(StockDocument $document): ?bool
    {
        if ($document->type !== StockDocument::INWARD || ! $document->vendor_id) {
            return null;
        }

        return VendorSku::query()->where('vendor_id', $document->vendor_id)->where('sku_id', $document->sku_id)->where('is_active', true)->exists();
    }

    /**
     * Validates the input and the references (active, in the user's factory
     * scope, location belongs to factory, department belongs to factory).
     *
     * @return array<string, mixed>
     */
    public function validated(string $type, array $input, User $user): array
    {
        $rules = [
            'document_date' => ['required', 'date', 'before_or_equal:today'],
            'factory_id' => ['required', 'integer'],
            'location_id' => ['required', 'integer'],
            'sku_id' => ['required', 'integer'],
            'job_no' => ['nullable', 'string', 'max:60'],
            'reference' => ['nullable', 'string', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
        $rules += match ($type) {
            StockDocument::INWARD => [
                'vendor_id' => ['required', 'integer'],
                'received_qty' => ['required', 'regex:'.self::QTY],
                'rejected_qty' => ['nullable', 'regex:'.self::QTY],
            ],
            StockDocument::OUTWARD => [
                'quantity' => ['required', 'regex:'.self::QTY],
                'vendor_id' => ['nullable', 'integer'],
                'department_id' => ['nullable', 'integer'],
                'recipient' => ['nullable', 'string', 'max:150'],
            ],
            default => ['quantity' => ['required', 'regex:'.self::QTY]],
        };
        $messages = [
            'received_qty.regex' => 'Enter a quantity (up to 3 decimals).',
            'rejected_qty.regex' => 'Enter a quantity (up to 3 decimals), or leave empty.',
            'quantity.regex' => 'Enter a quantity (up to 3 decimals).',
            'document_date.before_or_equal' => 'The date cannot be in the future.',
        ];
        $data = Validator::make($input, $rules, $messages)->validate();

        $errors = [];
        $factory = Factory::query()->find($data['factory_id']);
        if (! $factory || ! $this->scope->canAccessFactory($user, $factory->id)) {
            $errors['factory_id'] = 'Select a factory you have access to.';
        } elseif (! $factory->is_active) {
            $errors['factory_id'] = 'This factory is inactive.';
        }
        $location = WarehouseLocation::query()->with('warehouse')->find($data['location_id']);
        if (! $location || ($factory && $location->warehouse->factory_id !== $factory->id)) {
            $errors['location_id'] = 'Select a storage location of the selected factory.';
        } elseif (! $location->is_active || ! $location->warehouse->is_active) {
            $errors['location_id'] = "Storage location {$location->code} is inactive.";
        }
        $sku = Sku::query()->find($data['sku_id']);
        if (! $sku) {
            $errors['sku_id'] = 'Select a SKU.';
        } elseif (! $sku->is_active) {
            $errors['sku_id'] = "SKU {$sku->sku} is inactive and cannot be used for new entries.";
        }
        if (! empty($data['vendor_id'])) {
            $vendor = Vendor::query()->find($data['vendor_id']);
            if (! $vendor) {
                $errors['vendor_id'] = 'Select a vendor.';
            } elseif (! $vendor->is_active) {
                $errors['vendor_id'] = "Vendor {$vendor->name} is inactive.";
            }
        }
        if (! empty($data['department_id'])) {
            $department = Department::query()->find($data['department_id']);
            if (! $department || ($factory && $department->factory_id !== $factory->id)) {
                $errors['department_id'] = 'Select a department of the selected factory.';
            } elseif (! $department->is_active) {
                $errors['department_id'] = 'This department is inactive.';
            }
        }

        if ($type === StockDocument::INWARD) {
            $received = $data['received_qty'];
            $rejected = ($data['rejected_qty'] ?? '') === '' ? '0' : $data['rejected_qty'];
            if (bccomp($received, '0', 3) <= 0) {
                $errors['received_qty'] = 'Received quantity must be greater than zero.';
            } elseif (bccomp($rejected, $received, 3) > 0) {
                $errors['rejected_qty'] = 'Rejected quantity cannot be more than the received quantity.';
            }
            $data['rejected_qty'] = $rejected;
            $data['quantity'] = '0';
        } else {
            if (bccomp($data['quantity'], '0', 3) <= 0) {
                $errors['quantity'] = 'Quantity must be greater than zero.';
            }
            $data['received_qty'] = '0';
            $data['rejected_qty'] = '0';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data + ['vendor_id' => null, 'department_id' => null, 'recipient' => null];
    }
}
