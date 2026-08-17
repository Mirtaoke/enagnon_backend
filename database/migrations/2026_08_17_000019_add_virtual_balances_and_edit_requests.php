<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            foreach (['moov_credit', 'flooz', 'momo', 'mtn_credit', 'celtiis'] as $service) {
                $table->decimal($service.'_initial_balance', 14, 2)->default(0);
                $table->decimal($service.'_virtual_balance', 14, 2)->default(0);
            }
        });
        Schema::table('operations', function (Blueprint $table) {
            $table->decimal('virtual_balance_after', 14, 2)->nullable()->after('amount');
        });
        Schema::create('operation_edit_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['operation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_edit_requests');
        Schema::table('operations', fn (Blueprint $table) => $table->dropColumn('virtual_balance_after'));
        Schema::table('shops', function (Blueprint $table) {
            foreach (['moov_credit', 'flooz', 'momo', 'mtn_credit', 'celtiis'] as $service) {
                $table->dropColumn([$service.'_initial_balance', $service.'_virtual_balance']);
            }
        });
    }
};
