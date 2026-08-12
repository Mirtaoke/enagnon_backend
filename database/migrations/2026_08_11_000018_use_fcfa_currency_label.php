<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('shops')->where('currency', 'XAF')->update(['currency' => 'FCFA']);
    }

    public function down(): void
    {
        DB::table('shops')->where('currency', 'FCFA')->update(['currency' => 'XAF']);
    }
};
