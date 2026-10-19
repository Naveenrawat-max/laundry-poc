<?php
/** Stores each mat's accepted delivery schedule and one shared simulator business date. */
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            // Unknown historical deliveries stay unknown; contract start is not delivery evidence.
            $table->date('delivered_on')->nullable();
            $table->date('swap_due_date')->nullable();
            $table->unsignedBigInteger('delivered_contract_id')->nullable();
            $table->foreign('delivered_contract_id')->references('id')->on('contracts')->nullOnDelete();
        });

        Schema::create('demo_clock', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->date('business_date')->nullable();
        });
        DB::table('demo_clock')->insert(['id' => 1, 'business_date' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_clock');
        Schema::table('items', function (Blueprint $table): void {
            $table->dropForeign(['delivered_contract_id']);
            $table->dropColumn(['delivered_on', 'swap_due_date', 'delivered_contract_id']);
        });
    }
};
