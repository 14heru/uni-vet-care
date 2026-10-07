<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\BookingUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function show(Request $request, Booking $booking): View
    {
        Gate::forUser($request->user())->authorize('view', $booking);

        $booking->load(['doctor.user:id,name', 'pet:id,name,species']);

        return view('customer.bookings.show', compact('booking'));
    }

    public function store(
        StoreBookingRequest $request,
        BookingService $bookingService,
    ): JsonResponse|RedirectResponse {
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
            if (! $request->expectsJson() && $request->user()?->role?->name === 'customer') {
                return back()
                    ->withInput()
                    ->withErrors(['appointment_start' => 'That appointment time is no longer available. Please choose another slot.']);
            }

            return response()->json([
                'message' => 'The selected appointment slot is no longer available.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $request->expectsJson() && $request->user()?->role?->name === 'customer') {
            return redirect()->route('customer.bookings.show', $booking);
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
