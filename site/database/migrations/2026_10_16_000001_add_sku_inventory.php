<?php

/** Adds two-level mat categories, SKU variant stock and structured pickup return reasons. */
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('is_active')->default(true);
        });

        Schema::table('item_types', function (Blueprint $table): void {
            // Existing mat types remain valid until their category is assigned.
            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreign('category_id')->references('id')->on('categories');
            $table->dropUnique('uq_item_types_name');
            $table->unique(['category_id', 'name'], 'uq_item_types_category_name');
        });

        Schema::table('sku_catalog', function (Blueprint $table): void {
            $table->decimal('width_cm', 8, 2)->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->string('color', 80)->nullable();
            $table->unsignedInteger('untagged_quantity')->default(0);
            $table->unique(['item_type_id', 'width_cm', 'height_cm', 'color'], 'uq_sku_catalog_variant');
        });

        DB::statement('ALTER TABLE sku_catalog ADD CONSTRAINT ck_sku_catalog_dimensions CHECK ((width_cm IS NULL OR width_cm > 0) AND (height_cm IS NULL OR height_cm > 0))');

        Schema::table('orders', function (Blueprint $table): void {
            $table->enum('return_reason', ['used', 'unused_clean', 'damaged'])->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('item_types')->select('name')->groupBy('name')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new LogicException('Cannot roll back while mat types share a name across categories.');
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('return_reason');
        });

        $dropCheck = DB::connection()->isMaria() ? 'DROP CONSTRAINT' : 'DROP CHECK';
        DB::statement("ALTER TABLE sku_catalog {$dropCheck} ck_sku_catalog_dimensions");
        Schema::table('sku_catalog', function (Blueprint $table): void {
            $table->dropUnique('uq_sku_catalog_variant');
            $table->dropColumn(['width_cm', 'height_cm', 'color', 'untagged_quantity']);
        });

        Schema::table('item_types', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
            $table->dropUnique('uq_item_types_category_name');
            $table->dropColumn('category_id');
            $table->unique('name', 'uq_item_types_name');
        });
        Schema::dropIfExists('categories');
    }
};
