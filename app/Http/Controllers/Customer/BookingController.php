<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\BookingUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;

class BookingController extends Controller
{
    public function store(StoreBookingRequest $request, BookingService $bookingService): JsonResponse
    {
        $input = $request->validated();

        try {
            $booking = $bookingService->create(
                $request->user(),
                (int) $input['pet_id'],
                (int) $input['doctor_id'],
                (int) $input['doctor_schedule_id'],
                $input['appointment_start'],
                $input['appointment_end'],
            );
        } catch (BookingUnavailableException) {
            return response()->json([
                'message' => 'The selected appointment slot is no longer available.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => [
                'booking_code' => $booking->booking_code,
                'status' => $booking->status,
                'appointment_start' => $booking->appointment_start,
                'appointment_end' => $booking->appointment_end,
            ],
        ], JsonResponse::HTTP_CREATED);
    }
}
