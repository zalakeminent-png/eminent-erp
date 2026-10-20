<?php

namespace App\Services\MasterData;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared approve / revise / activate-deactivate rules for versioned
 * engineering documents (BOM, routing, QC specification).
 *
 *  - Only drafts can be edited; approval freezes a version permanently.
 *  - At most one approved version per item may be effective on any date.
 *  - Revising copies a version (any status) into a new draft version.
 */
abstract class DocumentLifecycle
{
    public function __construct(protected readonly ActivityLogger $activity) {}

    /** Audit action prefix, e.g. "bom". */
    abstract protected function entity(): string;

    /** Name of the version column ("version" or "revision"). */
    protected function versionColumn(): string
    {
        return 'version';
    }

    /** Business validation before approval; return error messages. */
    abstract protected function approvalErrors(Model $document): array;

    /** Copy child lines from $source to $copy. */
    abstract protected function copyLines(Model $source, Model $copy): void;

    abstract protected function numberPrefix(): string;

    abstract protected function numberColumn(): string;

    public function ensureEditable(Model $document): void
    {
        if (! $document->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ucfirst($this->label()).' '.$this->numberOf($document).' is '.$document->status.' and can no longer be edited. Create a revision instead.',
            ]);
        }
    }

    public function nextVersion(int $itemId): int
    {
        $class = $this->modelClass();

        return (int) $class::query()->where('item_id', $itemId)->max($this->versionColumn()) + 1;
    }

    public function newNumber(): string
    {
        return app(NumberSequence::class)->formatted($this->entity().'-number', $this->numberPrefix());
    }

    public function approve(Model $document, User $actor): Model
    {
        return DB::transaction(function () use ($document, $actor) {
            $document = $document->newQuery()->lockForUpdate()->findOrFail($document->getKey());
            $this->ensureEditable($document);

            $errors = $this->approvalErrors($document);
            if ($overlap = $document->overlappingApproved()->first()) {
                $errors[] = "Approved {$this->label()} {$this->numberOf($overlap)} (v{$overlap->{$this->versionColumn()}}) is already effective for this period. Deactivate it or set effective dates that do not overlap.";
            }
            if ($errors) {
                throw ValidationException::withMessages(['approval' => $errors]);
            }

            $document->forceFill([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();

            $this->activity->log("{$this->entity()}.approved", $document, [
                'number' => $this->numberOf($document),
                $this->versionColumn() => $document->{$this->versionColumn()},
            ], 'Approved '.$this->label().' '.$this->numberOf($document));

            return $document;
        });
    }

    public function revise(Model $source): Model
    {
        return DB::transaction(function () use ($source) {
            $copy = $source->replicate(['status', 'approved_by', 'approved_at', 'created_by', 'updated_by', $this->numberColumn(), $this->versionColumn()]);
            $copy->{$this->numberColumn()} = $this->newNumber();
            $copy->{$this->versionColumn()} = $this->nextVersion($source->item_id);
            $copy->status = 'draft';
            $copy->revised_from_id = $source->getKey();
            $copy->effective_from = now()->toDateString() > $source->effective_from->toDateString()
                ? now()->toDateString()
                : $source->effective_from->toDateString();
            $copy->effective_to = null;
            $copy->save();

            $this->copyLines($source, $copy);

            $this->activity->log("{$this->entity()}.revised", $copy, [
                'from' => $this->numberOf($source),
                'from_'.$this->versionColumn() => $source->{$this->versionColumn()},
                'new_'.$this->versionColumn() => $copy->{$this->versionColumn()},
            ], 'Created revision of '.$this->label().' '.$this->numberOf($source));

            return $copy;
        });
    }

    /** Toggles an approved version active ↔ inactive. Drafts cannot be toggled. */
    public function setActive(Model $document, bool $active): Model
    {
        return DB::transaction(function () use ($document, $active) {
            $document = $document->newQuery()->lockForUpdate()->findOrFail($document->getKey());

            if (! $document->isFrozen()) {
                throw ValidationException::withMessages(['status' => 'Only approved versions can be activated or deactivated. Drafts are edited or deleted instead.']);
            }

            $target = $active ? 'approved' : 'inactive';
            if ($document->status === $target) {
                return $document;
            }

            if ($active && ($overlap = $document->overlappingApproved()->first())) {
                throw ValidationException::withMessages([
                    'status' => "Approved {$this->label()} {$this->numberOf($overlap)} is already effective for this period.",
                ]);
            }

            $document->forceFill(['status' => $target])->save();
            $this->activity->log("{$this->entity()}.".($active ? 'activated' : 'deactivated'), $document, [
                'number' => $this->numberOf($document),
            ], ($active ? 'Reactivated ' : 'Deactivated ').$this->label().' '.$this->numberOf($document));

            return $document;
        });
    }

    /** Drafts that were never approved may be deleted outright. */
    public function deleteDraft(Model $document): void
    {
        $this->ensureEditable($document);
        if ($document->isFrozen()) {
            throw ValidationException::withMessages(['status' => 'Approved versions are kept for history.']);
        }

        $snapshot = ['number' => $this->numberOf($document), 'item_id' => $document->item_id];
        DB::transaction(fn () => $document->delete());
        $this->activity->log("{$this->entity()}.deleted", null, $snapshot, 'Deleted draft '.$this->label().' '.$snapshot['number']);
    }

    protected function numberOf(Model $document): string
    {
        return (string) $document->{$this->numberColumn()};
    }

    protected function label(): string
    {
        return str_replace('-', ' ', $this->entity());
    }

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;
}
