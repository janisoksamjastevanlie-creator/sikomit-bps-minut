<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('document_signatures')) {
            Schema::create('document_signatures', function (Blueprint $table) {
                $table->id();
                $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
                $table->foreignId('document_version_id')->constrained('document_versions')->restrictOnDelete();
                $table->foreignId('signer_user_id')->constrained('users')->restrictOnDelete();
                $table->string('signer_capacity', 100);
                $table->string('signature_method', 40);
                $table->string('provider_name', 120)->nullable();
                $table->string('provider_reference', 200)->nullable();
                $table->string('signed_artifact_path', 500)->nullable();
                $table->char('artifact_sha256', 64)->nullable();
                $table->string('validation_status', 40)->default('MENUNGGU_VERIFIKASI')->index();
                $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('validated_at')->nullable();
                $table->text('validation_note')->nullable();
                $table->timestamp('signed_at')->useCurrent();
                $table->timestamps();
            });
        }
        Schema::table('document_signatures', function (Blueprint $table) {
            $table->index(['document_id', 'document_version_id', 'signer_capacity', 'validation_status'], 'doc_sig_scope_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signatures');
    }
};
