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
        Schema::create('pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')
                ->index()
                ->constrained('customer_profiles')
                ->restrictOnDelete();
            $table->string('name', 100);
            $table->string('species', 50)->index();
            $table->string('breed', 100)->nullable();
            $table->string('sex', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->decimal('weight', 6, 2)->nullable();
            $table->string('color', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pets');
    }
};
