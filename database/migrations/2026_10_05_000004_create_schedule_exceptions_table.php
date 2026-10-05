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
        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')
                ->index()
                ->constrained('doctor_profiles')
                ->restrictOnDelete();
            $table->date('exception_date')->index();
            $table->string('type', 30);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();

            $table->unique(['doctor_id', 'exception_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_exceptions');
    }
};
