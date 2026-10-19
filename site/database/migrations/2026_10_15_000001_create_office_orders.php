<?php

/** Adds reusable SKU choices and exact-mat office orders without changing legacy task or mat identities. */
declare(strict_types=1);

use Database\Seeders\SkuCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku_catalog', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('item_type_id');
            $table->string('code', 64)->unique();
            $table->string('label', 160);
            $table->boolean('is_active')->default(true);
            $table->foreign('item_type_id')->references('id')->on('item_types');
            $table->index(['item_type_id', 'is_active']);
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->unsignedBigInteger('catalog_sku_id')->nullable();
            $table->foreign('catalog_sku_id')->references('id')->on('sku_catalog');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 40)->unique();
            $table->enum('type', ['delivery', 'pickup']);
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('assigned_to');
            $table->unsignedBigInteger('created_by');
            $table->date('due_date');
            $table->enum('status', ['pending_qc', 'ready', 'in_progress', 'awaiting_receipt', 'received', 'done', 'cancelled']);
            $table->enum('destination', ['customer', 'washing', 'inventory']);
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('washing_user_id')->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->string('signature_path', 255)->nullable();
            $table->string('signed_name', 120)->nullable();
            $table->enum('customer_response', ['accepted', 'refused'])->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('building_id')->references('id')->on('buildings');
            $table->foreign('assigned_to')->references('id')->on('users');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('washing_user_id')->references('id')->on('users');
            $table->index(['assigned_to', 'status', 'due_date']);
            $table->index(['washing_user_id', 'status']);
            $table->index(['customer_id', 'type', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('item_id');
            // Release on replacement, completion or cancellation; the unique key prevents double booking.
            $table->unsignedBigInteger('reserved_item_id')->nullable()->unique();
            $table->unsignedBigInteger('zone_id');
            $table->enum('qc_status', ['pending', 'passed', 'failed'])->default('pending');
            $table->unsignedBigInteger('qc_by')->nullable();
            $table->dateTime('qc_at')->nullable();
            $table->string('qc_note', 500)->nullable();
            $table->dateTime('scanned_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->dateTime('replaced_at')->nullable();
            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('item_id')->references('id')->on('items');
            $table->foreign('reserved_item_id')->references('id')->on('items');
            $table->foreign('zone_id')->references('id')->on('zones');
            $table->foreign('qc_by')->references('id')->on('users');
            $table->unique(['order_id', 'item_id']);
            $table->index(['order_id', 'replaced_at']);
            $table->index(['item_id', 'order_id']);
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT ck_order_items_reservation CHECK (reserved_item_id IS NULL OR reserved_item_id = item_id)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT ck_orders_destination CHECK ((type = 'delivery' AND destination = 'customer' AND washing_user_id IS NULL) OR (type = 'pickup' AND ((destination = 'washing' AND washing_user_id IS NOT NULL) OR (destination = 'inventory' AND washing_user_id IS NULL))))");

        // Existing installations need catalog choices immediately; fresh installs seed item types afterward.
        (new SkuCatalogSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::table('items', function (Blueprint $table): void {
            $table->dropForeign(['catalog_sku_id']);
            $table->dropColumn('catalog_sku_id');
        });
        Schema::dropIfExists('sku_catalog');
    }
};
