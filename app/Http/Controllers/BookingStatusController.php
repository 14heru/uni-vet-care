<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidBookingStatusTransitionException;
use App\Http\Requests\TransitionBookingStatusRequest;
use App\Models\Booking;
use App\Services\BookingStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BookingStatusController extends Controller
{
    public function transition(
        TransitionBookingStatusRequest $request,
        Booking $booking,
        BookingStatusService $bookingStatusService,
    ): JsonResponse {
        $input = $request->validated();

        Gate::forUser($request->user())->authorize('transitionStatus', [$booking, $input['status']]);

        try {
            $updatedBooking = $bookingStatusService->transition(
                $booking,
                $input['status'],
                $input['reason'] ?? null,
            );
        } catch (InvalidBookingStatusTransitionException) {
            return response()->json([
                'message' => 'The requested booking status transition is not allowed.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => [
                'booking_code' => $updatedBooking->booking_code,
                'status' => $updatedBooking->status,
                'appointment_start' => $updatedBooking->appointment_start,
                'appointment_end' => $updatedBooking->appointment_end,
            ],
        ], JsonResponse::HTTP_OK);
    }
}
