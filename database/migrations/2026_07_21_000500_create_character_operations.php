<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_uuid')->unique();
            $table->unsignedBigInteger('expected_revision');
            $table->unsignedBigInteger('resulting_revision');
            $table->json('inverse_command');
            $table->timestamps();
            $table->index(['character_id', 'resulting_revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_operations');
    }
};
