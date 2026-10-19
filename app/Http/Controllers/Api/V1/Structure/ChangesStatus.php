<?php

namespace App\Http\Controllers\Api\V1\Structure;

use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Shared PATCH .../status handling for structure records. Deactivation
 * requires "{module}.deactivate"; reactivation requires "{module}.update".
 */
trait ChangesStatus
{
    /**
     * @param  string  $entity  audit action prefix, e.g. "factory"
     * @param  callable(Model, bool): (string|null)|null  $guard  returns an error message to block the change
     */
    protected function changeStatus(Request $request, Model $model, string $module, string $entity, ?callable $guard = null): void
    {
        $activate = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        abort_unless($request->user()->can($activate ? "{$module}.update" : "{$module}.deactivate"), 403);

        if ($model->is_active === $activate) {
            return;
        }

        if ($guard && ($message = $guard($model, $activate))) {
            throw ValidationException::withMessages(['is_active' => $message]);
        }

        $model->forceFill(['is_active' => $activate])->save();

        app(ActivityLogger::class)->log(
            "{$entity}.".($activate ? 'activated' : 'deactivated'),
            $model,
            ['code' => $model->getAttribute('code')],
            ($activate ? 'Activated ' : 'Deactivated ')."{$entity} {$model->getAttribute('name')}",
        );
    }
}
