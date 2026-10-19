<?php

namespace App\Console\Commands;

use App\Support\PermissionCatalog;
use Illuminate\Console\Command;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'erp:sync-permissions';

    protected $description = 'Create permissions registered in config/erp.php and grant them to the primary admin role';

    public function handle(PermissionCatalog $catalog): int
    {
        $created = $catalog->sync();

        $this->components->info("Permissions synchronised ({$created} new).");

        return self::SUCCESS;
    }
}
