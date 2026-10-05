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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_code')->unique();
            $table->foreignId('customer_id')
                ->index()
                ->constrained('customer_profiles')
                ->restrictOnDelete();
            $table->foreignId('pet_id')
                ->index()
                ->constrained('pets')
                ->restrictOnDelete();
            $table->foreignId('doctor_id')
                ->index()
                ->constrained('doctor_profiles')
                ->restrictOnDelete();
            $table->foreignId('doctor_schedule_id')
                ->index()
                ->constrained('doctor_schedules')
                ->restrictOnDelete();
            $table->dateTime('appointment_start');
            $table->dateTime('appointment_end');
            $table->string('status', 30)->default('PENDING')->index();
            $table->string('slot_key')->nullable()->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
