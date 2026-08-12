<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
            $table->string('role')->default('admin')->after('email');
            $table->timestamp('last_login_at')->nullable();
        });
        Schema::table('shops', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('id');
            $table->string('address')->nullable();
            $table->string('manager_name')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->string('phone')->nullable();
            $table->time('arrival_time')->nullable();
            $table->time('departure_time')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['phone', 'arrival_time', 'departure_time', 'is_active']));
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn(['code', 'address', 'manager_name', 'phone', 'is_active']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['username', 'role', 'last_login_at']));
    }
};
