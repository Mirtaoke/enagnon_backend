<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('daily_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->date('date');
            foreach (['cash', 'moov_credit', 'flooz', 'momo', 'mtn_credit', 'celtiis', 'virtual_credit_purchase', 'expected_total', 'actual_total', 'difference'] as $field) {
                $table->decimal($field, 14, 2)->default(0);
            }
            $table->json('expenses')->nullable();
            $table->json('debts')->nullable();
            $table->text('difference_reason')->nullable();
            $table->timestamp('closed_at');
            $table->timestamps();
            $table->unique(['shop_id', 'date']);
        });
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('daily_closures');
    }
};
