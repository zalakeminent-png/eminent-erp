<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ActivityLogger
{
    /** Metadata keys containing any of these fragments are redacted. */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password', 'token', 'secret', 'remember', 'session', 'cookie', 'csrf', 'xsrf',
        'authorization', 'api_key', 'apikey', 'otp',
    ];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $action,
        ?Model $entity = null,
        array $metadata = [],
        ?string $description = null,
        ?User $actor = null,
    ): ActivityLog {
        $actor ??= $this->request->user();

        return ActivityLog::create([
            'company_id' => $actor?->company_id ?? Company::current()?->id,
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'entity_type' => $entity ? Str::kebab(class_basename($entity)) : null,
            'entity_id' => $entity?->getKey(),
            'description' => $description ? Str::limit($description, 250) : null,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
            'metadata' => $metadata ? $this->sanitize($metadata) : null,
        ]);
    }

    /**
     * Returns a before/after diff of changed attributes, safe for logging.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            $old = $before[$key] ?? null;
            if ($old != $value) {
                $changes[$key] = ['from' => $old, 'to' => $value];
            }
        }

        return $this->sanitize($changes);
    }

    /**
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    public function sanitize(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $clean[$key] = '[REDACTED]';

                continue;
            }
            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
