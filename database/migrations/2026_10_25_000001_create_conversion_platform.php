<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false);
        });

        Schema::create('conversions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_key');
            $table->string('original_name');
            $table->string('source_format', 10);
            $table->string('target_format', 10);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('input_bytes');
            $table->char('checksum', 64);
            $table->string('input_path');
            $table->string('output_path')->nullable();
            $table->unsignedBigInteger('output_bytes')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->json('preview')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('system_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('conversions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
