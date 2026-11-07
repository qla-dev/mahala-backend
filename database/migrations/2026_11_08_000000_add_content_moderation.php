<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 32)->nullable();
        });

        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reported_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('content_type', 16);
            $table->unsignedBigInteger('content_id');
            $table->text('content_snapshot')->nullable();
            $table->text('image_uri')->nullable();
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->timestamp('due_at');
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
        });

        Schema::create('support_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email');
            $table->text('message');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
        Schema::dropIfExists('content_reports');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspension_reason', 'terms_accepted_at', 'terms_version']);
        });
    }
};
