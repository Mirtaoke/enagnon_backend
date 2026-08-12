<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('daily_closures', function (Blueprint $table) {
            $table->string('status')->default('draft')->after('date');
            $table->foreignId('validated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('closed_at');
            $table->timestamp('closed_at')->nullable()->change();
        });
        DB::table('daily_closures')->whereNotNull('closed_at')->update(['status' => 'validated', 'submitted_at' => DB::raw('closed_at')]);
    }

    public function down(): void
    {
        Schema::table('daily_closures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validated_by');
            $table->dropColumn(['status', 'submitted_at']);
        });
    }
};
