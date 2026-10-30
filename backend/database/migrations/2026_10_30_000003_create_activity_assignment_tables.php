<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 200);
            $table->unsignedSmallInteger('year');
            $table->string('period', 100)->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('activity_type', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('territory', 200)->nullable();
            $table->decimal('target_volume', 15, 2)->default(0);
            $table->string('unit', 40)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('administrative_requirements')->nullable();
            $table->unsignedSmallInteger('partner_need')->default(0);
            $table->string('status', 40)->default('DRAFT')->index();
            $table->boolean('is_demo')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['year', 'status']);
        });

        Schema::create('activity_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->string('description', 500);
            $table->decimal('target_volume', 15, 2)->default(0);
            $table->string('unit', 40)->nullable();
            $table->string('territory', 150)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['activity_id', 'sort_order']);
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->restrictOnDelete();
            $table->string('description', 1000);
            $table->string('territory', 200)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 40)->default('DITUGASKAN')->index();
            $table->foreignId('coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_demo')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['activity_id', 'starts_on', 'ends_on']);
        });

        Schema::create('assignment_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->restrictOnDelete();
            $table->string('status', 40)->default('DITUGASKAN')->index();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['assignment_id', 'partner_id']);
            $table->index(['partner_id', 'status']);
        });

        Schema::create('assignment_work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_member_id')->constrained('assignment_members')->cascadeOnDelete();
            $table->foreignId('activity_target_id')->nullable()->constrained('activity_targets')->nullOnDelete();
            $table->string('description', 500);
            $table->decimal('target_volume', 15, 2)->default(0);
            $table->string('unit', 40)->nullable();
            $table->string('territory', 150)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_work_items');
        Schema::dropIfExists('assignment_members');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('activity_targets');
        Schema::dropIfExists('activities');
    }
};
