<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data-only: renumbers existing department/warehouse types from the old
     * 10, 20, 30… scheme to 1, 2, 3… per category, preserving the current
     * display order (sort_order, then name). Ties become distinct values.
     */
    public function up(): void
    {
        DB::transaction(function () {
            foreach (DB::table('structure_types')->distinct()->pluck('category') as $category) {
                $ids = DB::table('structure_types')
                    ->where('category', $category)
                    ->orderBy('sort_order')->orderBy('name')->orderBy('id')
                    ->pluck('id');

                foreach ($ids as $index => $id) {
                    DB::table('structure_types')->where('id', $id)->update(['sort_order' => $index + 1]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('structure_types')->update(['sort_order' => DB::raw('sort_order * 10')]);
    }
};
