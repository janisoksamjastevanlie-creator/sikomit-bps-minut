<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('document_templates')) {
            Schema::create('document_templates', function (Blueprint $table) {
                $table->id();
                $table->string('document_type', 10);
                $table->string('worker_category', 40);
                $table->string('document_mode', 20)->default('TERPISAH');
                $table->string('name', 180);
                $table->boolean('is_active')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        Schema::table('document_templates', function (Blueprint $table) {
            $table->unique(['document_type', 'worker_category', 'document_mode', 'name'], 'doc_tpl_scope_name_uniq');
        });

        Schema::create('document_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_template_id')->constrained('document_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('original_filename', 255);
            $table->string('storage_path', 500);
            $table->char('sha256', 64);
            $table->json('placeholder_fields')->nullable();
            $table->json('validation_findings')->nullable();
            $table->string('status', 30)->default('DRAFT')->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['document_template_id', 'version']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 10)->index();
            $table->string('worker_category', 40)->index();
            $table->string('document_mode', 20)->default('TERPISAH');
            $table->string('document_number', 120)->nullable();
            $table->foreignId('activity_id')->nullable()->constrained('activities')->nullOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('assignments')->nullOnDelete();
            $table->foreignId('assignment_member_id')->nullable()->constrained('assignment_members')->nullOnDelete();
            $table->foreignId('parent_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('template_version_id')->nullable()->constrained('document_template_versions')->nullOnDelete();
            $table->string('status', 40)->default('DRAFT')->index();
            $table->unsignedInteger('current_version')->default(1);
            $table->json('content_snapshot')->nullable();
            $table->boolean('is_demo')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->unique(['document_type', 'document_number']);
            $table->index(['assignment_id', 'document_type', 'status']);
        });

        Schema::create('document_assignments', function (Blueprint $table) {
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('assignment_member_id')->constrained('assignment_members')->restrictOnDelete();
            $table->primary(['document_id', 'assignment_member_id']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('template_version_id')->nullable()->constrained('document_template_versions')->nullOnDelete();
            $table->json('content_snapshot')->nullable();
            $table->string('docx_path', 500)->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['document_id', 'version']);
        });

        Schema::create('document_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('outcome', 40)->nullable();
            $table->text('note')->nullable();
            $table->string('signature_method', 40)->nullable();
            $table->string('signature_provider', 120)->nullable();
            $table->string('signature_reference', 200)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->json('validation_metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
        });

        Schema::create('work_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_member_id')->constrained('assignment_members')->restrictOnDelete();
            $table->foreignId('spk_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('status', 40)->default('DRAFT')->index();
            $table->text('summary')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['assignment_member_id', 'status']);
        });

        Schema::create('work_report_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_report_id')->constrained('work_reports')->cascadeOnDelete();
            $table->foreignId('assignment_work_item_id')->nullable()->constrained('assignment_work_items')->nullOnDelete();
            $table->string('description', 500);
            $table->decimal('actual_volume', 15, 2)->default(0);
            $table->string('unit', 40)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('work_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_report_id')->constrained('work_reports')->cascadeOnDelete();
            $table->string('original_filename', 255);
            $table->string('storage_path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('work_review_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_report_id')->constrained('work_reports')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('outcome', 40);
            $table->text('note')->nullable();
            $table->timestamp('reviewed_at')->useCurrent()->index();
        });

        Schema::create('archive_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained('document_versions')->restrictOnDelete();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at');
            $table->timestamp('retention_until')->nullable();
            $table->char('sha256', 64);
            $table->timestamps();
            $table->unique(['document_id', 'document_version_id']);
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 10);
            $table->unsignedSmallInteger('year');
            $table->string('unit_code', 40);
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['document_type', 'year', 'unit_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('archive_entries');
        Schema::dropIfExists('work_review_actions');
        Schema::dropIfExists('work_evidence');
        Schema::dropIfExists('work_report_items');
        Schema::dropIfExists('work_reports');
        Schema::dropIfExists('document_actions');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('document_assignments');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_template_versions');
        Schema::dropIfExists('document_templates');
    }
};
