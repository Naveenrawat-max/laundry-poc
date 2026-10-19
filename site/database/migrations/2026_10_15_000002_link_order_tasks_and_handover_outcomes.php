<?php

/** Links office orders to contracts, visit tasks and real handovers, with per-mat customer outcomes. */
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('task_id')->nullable()->unique();
            $table->unsignedBigInteger('dispatch_handover_id')->nullable();
            $table->unsignedBigInteger('return_handover_id')->nullable();
            $table->foreign('contract_id')->references('id')->on('contracts');
            $table->foreign('task_id')->references('id')->on('tasks');
            $table->foreign('dispatch_handover_id')->references('id')->on('handovers');
            $table->foreign('return_handover_id')->references('id')->on('handovers');
        });

        DB::statement("ALTER TABLE orders MODIFY COLUMN customer_response ENUM('accepted','refused','partial') NULL");

        Schema::table('order_items', function (Blueprint $table): void {
            $table->enum('customer_response', ['accepted', 'refused'])->nullable();
            $table->dateTime('refusal_scanned_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('orders')->where('customer_response', 'partial')->exists()) {
            throw new LogicException('Cannot roll back while partial customer responses exist.');
        }

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['customer_response', 'refusal_scanned_at']);
        });

        DB::statement("ALTER TABLE orders MODIFY COLUMN customer_response ENUM('accepted','refused') NULL");

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['contract_id']);
            $table->dropForeign(['task_id']);
            $table->dropForeign(['dispatch_handover_id']);
            $table->dropForeign(['return_handover_id']);
            $table->dropUnique(['task_id']);
            $table->dropColumn(['contract_id', 'task_id', 'dispatch_handover_id', 'return_handover_id']);
        });
    }
};
