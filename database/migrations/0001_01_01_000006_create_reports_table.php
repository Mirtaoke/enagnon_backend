<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('total_in', 12, 2)->default(0);
            $table->decimal('total_out', 12, 2)->default(0);
            $table->decimal('cash_balance', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
