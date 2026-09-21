<?php

use App\Enums\TradeAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge', function (Blueprint $table) {
            $table->uuid('knowledge_id')->primary();
            $table->unsignedBigInteger('microtimestamp')->nullable(false);
            $table->string('exchange', length: 32)->nullable(false);
            $table->string('symbol', length: 32)->nullable(false);
            $table->string('period', length: 8)->nullable(false);
            $table->enum('action', TradeAction::cases())->nullable(false);
            $table->decimal('value', total: 16, places: 8)->nullable(false);
            $table->boolean('learned')->default(false);
            $table->timestamps();
            $table->unique(['exchange','symbol','period','microtimestamp']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge');
    }
};
