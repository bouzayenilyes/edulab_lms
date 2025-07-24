<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('module_dependencies', function (Blueprint $table) {
           $table->id();
            $table->foreignId('module_id')->constrained()->onDelete('cascade');
            $table->string('dependency_name');
            $table->string('version_constraint')->nullable();
            $table->enum('type', ['required', 'dev', 'conflict', 'suggest'])->default('required');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('module_dependencies');
    }
};
