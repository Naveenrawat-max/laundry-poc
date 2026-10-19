<?php

/** Makes delivery areas optional and scopes new contracts to one customer location without rewriting legacy schedules. */
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table): void {
            $table->unsignedBigInteger('area_id')->nullable()->change();
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->unsignedBigInteger('building_id')->nullable();
            $table->index(['building_id', 'status'], 'ix_contracts_building_status');
            $table->foreign('building_id')->references('id')->on('buildings');
        });

        // Multiple-location legacy contracts keep their existing line-based scope.
        DB::statement('UPDATE contracts c
            JOIN (
                SELECT l.contract_id, MIN(z.building_id) AS building_id
                FROM contract_lines l JOIN zones z ON z.id = l.zone_id
                GROUP BY l.contract_id HAVING COUNT(DISTINCT z.building_id) = 1
            ) legacy ON legacy.contract_id = c.id
            JOIN buildings b ON b.id = legacy.building_id AND b.customer_id = c.customer_id
            SET c.building_id = legacy.building_id, c.updated_at = c.updated_at
            WHERE c.building_id IS NULL');
    }

    public function down(): void
    {
        if (DB::table('buildings')->whereNull('area_id')->exists()) {
            throw new LogicException('Cannot roll back while customer locations have no delivery area.');
        }

        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropForeign(['building_id']);
            $table->dropIndex('ix_contracts_building_status');
            $table->dropColumn('building_id');
        });
        Schema::table('buildings', function (Blueprint $table): void {
            $table->unsignedBigInteger('area_id')->nullable(false)->change();
        });
    }
};
