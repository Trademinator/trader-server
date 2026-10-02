<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portable_archive_transfers', function (Blueprint $table): void {
            $table->uuid('portable_archive_transfer_id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('direction', 8);
            $table->string('status', 20);
            $table->boolean('validate_only')->default(true);
            $table->uuid('source_export_id')->nullable();
            $table->uuid('cursor')->nullable();
            $table->uuid('end_cursor')->nullable();
            $table->unsignedInteger('expected_parts')->default(0);
            $table->unsignedInteger('completed_parts')->default(0);
            $table->unsignedInteger('verified_parts')->default(0);
            $table->unsignedBigInteger('total_rows')->default(0);
            $table->unsignedBigInteger('inserted_rows')->default(0);
            $table->unsignedBigInteger('identical_rows')->default(0);
            $table->string('manifest_path', 512)->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'direction', 'status'], 'portable_transfer_owner_state');
            $table->index('expires_at');
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
        });

        Schema::create('portable_archive_parts', function (Blueprint $table): void {
            $table->uuid('portable_archive_part_id')->primary();
            $table->uuid('portable_archive_transfer_id');
            $table->unsignedInteger('sequence');
            $table->string('file_name', 64);
            $table->string('status', 16);
            $table->string('path', 512)->nullable();
            $table->char('sha256', 64);
            $table->unsignedBigInteger('compressed_size');
            $table->unsignedBigInteger('uncompressed_size');
            $table->unsignedBigInteger('row_count');
            $table->string('first_key', 255)->nullable();
            $table->string('last_key', 255)->nullable();
            $table->unsignedBigInteger('inserted_rows')->default(0);
            $table->unsignedBigInteger('identical_rows')->default(0);
            $table->string('error', 1000)->nullable();
            $table->timestamps();
            $table->unique(['portable_archive_transfer_id', 'sequence'], 'portable_part_sequence');
            $table->unique(['portable_archive_transfer_id', 'file_name'], 'portable_part_filename');
            $table->foreign('portable_archive_transfer_id')->references('portable_archive_transfer_id')
                ->on('portable_archive_transfers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portable_archive_parts');
        Schema::dropIfExists('portable_archive_transfers');
    }
};
