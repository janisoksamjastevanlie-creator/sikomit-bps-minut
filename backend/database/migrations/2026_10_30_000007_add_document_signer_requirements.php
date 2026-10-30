<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_template_versions', function (Blueprint $table) {
            $table->json('required_signatures')->nullable();
        });

        Schema::table('document_actions', function (Blueprint $table) {
            $table->string('signer_capacity', 100)->nullable();
            $table->string('signed_artifact_path', 500)->nullable();
            $table->string('signature_validation_status', 40)->default('NOT_APPLICABLE');
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_actions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validated_by');
            $table->dropColumn(['signer_capacity', 'signed_artifact_path', 'signature_validation_status', 'validated_at']);
        });
        Schema::table('document_template_versions', function (Blueprint $table) {
            $table->dropColumn('required_signatures');
        });
    }
};
