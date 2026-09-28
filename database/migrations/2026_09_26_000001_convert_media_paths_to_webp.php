<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Point catalog media rows at the new WebP files.
     * Only converts .jpg/.jpeg (legacy heavy uploads). PNG logos/banners untouched.
     */
    public function up(): void
    {
        $tables = [
            'product_images' => 'path',
            'categories' => 'image',
            'banners' => 'image',
            'media_files' => 'path',
        ];

        foreach ($tables as $table => $column) {
            DB::table($table)
                ->whereNotNull($column)
                ->where($column, 'like', '%.jpg')
                ->update([
                    $column => DB::raw("REPLACE(`{$column}`, '.jpg', '.webp')"),
                ]);

            DB::table($table)
                ->whereNotNull($column)
                ->where($column, 'like', '%.jpeg')
                ->update([
                    $column => DB::raw("REPLACE(`{$column}`, '.jpeg', '.webp')"),
                ]);
        }
    }

    public function down(): void
    {
        // Irreversible media format migration.
    }
};
