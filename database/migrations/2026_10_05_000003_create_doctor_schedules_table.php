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
        Schema::create('doctor_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')
                ->index()
                ->constrained('doctor_profiles')
                ->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week')->index();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('slot_duration_minutes')->default(30);
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();

            $table->unique(
                ['doctor_id', 'day_of_week', 'start_time', 'end_time'],
                'doctor_schedules_unique_recurring'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_schedules');
    }
};
