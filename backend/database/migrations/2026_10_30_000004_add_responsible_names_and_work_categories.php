<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('responsible_name', 180)->nullable();
        });

        Schema::table('assignment_members', function (Blueprint $table) {
            $table->string('contract_category', 40)->default('PENDATAAN_LAPANGAN');
        });

        Schema::table('assignment_work_items', function (Blueprint $table) {
            $table->string('work_category', 30)->default('PENDATAAN_LAPANGAN');
        });
    }

    public function down(): void
    {
        Schema::table('assignment_work_items', fn (Blueprint $table) => $table->dropColumn('work_category'));
        Schema::table('assignment_members', fn (Blueprint $table) => $table->dropColumn('contract_category'));
        Schema::table('activities', fn (Blueprint $table) => $table->dropColumn('responsible_name'));
    }
};
