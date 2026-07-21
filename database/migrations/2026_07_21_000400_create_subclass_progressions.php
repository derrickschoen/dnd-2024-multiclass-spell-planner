<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subclass_progressions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subclass_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('class_level');
            $table->unsignedTinyInteger('cantrips_known')->default(0);
            $table->unsignedTinyInteger('prepared_count')->default(0);
            $table->unsignedTinyInteger('max_spell_level')->default(0);
            $table->json('slots')->nullable();
            $table->json('grant_rules')->nullable();
            $table->timestamps();
            $table->unique(['subclass_definition_id', 'class_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subclass_progressions');
    }
};
