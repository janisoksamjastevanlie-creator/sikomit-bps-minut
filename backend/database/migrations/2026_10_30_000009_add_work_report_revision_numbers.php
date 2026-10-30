<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_reports', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
        });
        Schema::table('work_report_items', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1)->index();
        });
        Schema::table('work_review_actions', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('work_review_actions', fn (Blueprint $table) => $table->dropColumn('revision'));
        Schema::table('work_report_items', fn (Blueprint $table) => $table->dropColumn('revision'));
        Schema::table('work_reports', fn (Blueprint $table) => $table->dropColumn('revision'));
    }
};
