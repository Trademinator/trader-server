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
            $table->unsignedBigInteger('microtimestamp');
            $table->string('exchange', 32);
            $table->string('symbol', 32);
            $table->string('period', 8);
            $table->enum(
                'action',
                array_map(static fn (TradeAction $action): string => $action->value, TradeAction::cases())
            );
            $table->decimal('value', 16, 8);
            $table->boolean('learned')->default(false);
            $table->timestamps();
            $table->unique(['exchange', 'symbol', 'period', 'microtimestamp']);
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
