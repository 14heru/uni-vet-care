<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\DoctorProfile;
use App\Services\DoctorAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BookingPageController extends Controller
{
    public function create(Request $request): View
    {
        $user = $request->user();
        $customer = $user->customerProfile()->first();

        Gate::forUser($user)->authorize('create', [Booking::class, $customer]);

        $pets = $customer->pets()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'species']);

        $doctors = DoctorProfile::query()
            ->with('user:id,name')
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->orderBy('id')
            ->get(['id', 'user_id', 'specialization']);

        return view('customer.bookings.create', compact('pets', 'doctors'));
    }

    public function availability(Request $request, DoctorAvailabilityService $availability): JsonResponse
    {
        $user = $request->user();
        $customer = $user->customerProfile()->first();

        Gate::forUser($user)->authorize('create', [Booking::class, $customer]);

        $input = $request->validate([
            'doctor_id' => ['required', 'integer', 'exists:doctor_profiles,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $doctor = DoctorProfile::query()
            ->whereKey($input['doctor_id'])
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->first();

        if ($doctor === null) {
            return response()->json([
                'message' => 'This doctor is not available for online booking.',
                'slots' => [],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $availability->getAvailability(
            (int) $doctor->getKey(),
            CarbonImmutable::createFromFormat('!Y-m-d', $input['date'], 'Asia/Jakarta'),
        );

        return response()->json([
            'date' => $result->date,
            'timezone' => $result->timezone,
            'status' => $result->status,
            'slots' => collect($result->slots)
                ->where('status', 'AVAILABLE')
                ->map(fn (array $slot): array => [
                    'start_at' => $slot['start_at'],
                    'end_at' => $slot['end_at'],
                    'doctor_schedule_id' => $slot['doctor_schedule_id'],
                ])
                ->values(),
        ]);
    }
}
