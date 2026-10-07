<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    /**
     * Display a listing of appointments for the authenticated doctor.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        if ($user === null || $user->status !== 'active') {
            abort(Response::HTTP_FORBIDDEN);
        }

        $doctorProfile = $user->doctorProfile;

        if ($doctorProfile === null || $doctorProfile->status !== 'active') {
            abort(Response::HTTP_FORBIDDEN);
        }

        $bookings = $doctorProfile->bookings()
            ->with([
                'customer.user:id,name',
                'pet:id,name,species',
            ])
            ->orderBy('appointment_start', 'asc')
            ->get();

        return view('doctor.bookings.index', compact('bookings'));
    }
}
