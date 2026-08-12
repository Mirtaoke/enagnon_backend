<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->string('network', 20)->nullable()->after('phone');
        });
        DB::table('operations')->where('service', 'cash')->update(['service' => 'other']);
        DB::table('employees')->whereIn('role', ['Vendeur senior', 'Caissière', 'Assistant', 'Employé'])->update(['role' => 'Vendeur / agent']);
        DB::table('employees')->whereIn('user_id', DB::table('users')->where('role', 'manager')->select('id'))->update(['role' => 'Responsable']);
    }

    public function down(): void
    {
        DB::table('operations')->where('service', 'other')->update(['service' => 'cash']);
        Schema::table('operations', fn (Blueprint $table) => $table->dropColumn('network'));
    }
};
