<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePetRequest;
use App\Models\CustomerProfile;
use App\Models\Pet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PetController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $this->customerProfile($request);
        $pets = $customer->pets()->orderBy('name')->orderBy('id')->get();

        return view('customer.pets.index', compact('pets'));
    }

    public function create(Request $request): View
    {
        $this->customerProfile($request);

        return view('customer.pets.create', ['pet' => new Pet(['status' => 'active'])]);
    }

    public function store(SavePetRequest $request): RedirectResponse
    {
        $customer = $this->customerProfile($request);
        $pet = $customer->pets()->create($request->validated());

        return redirect()->route('customer.pets.show', $pet)
            ->with('status', 'Pet added successfully.');
    }

    public function show(Request $request, Pet $pet): View
    {
        $pet = $this->ownedPet($request, $pet);

        return view('customer.pets.show', compact('pet'));
    }

    public function edit(Request $request, Pet $pet): View
    {
        $pet = $this->ownedPet($request, $pet);

        return view('customer.pets.edit', compact('pet'));
    }

    public function update(SavePetRequest $request, Pet $pet): RedirectResponse
    {
        $pet = $this->ownedPet($request, $pet);
        $pet->update($request->validated());

        return redirect()->route('customer.pets.show', $pet)
            ->with('status', 'Pet details updated successfully.');
    }

    private function customerProfile(Request $request): CustomerProfile
    {
        $customer = $request->user()?->customerProfile()->first();

        abort_if($customer === null, 403);

        return $customer;
    }

    private function ownedPet(Request $request, Pet $pet): Pet
    {
        return $this->customerProfile($request)
            ->pets()
            ->whereKey($pet->getKey())
            ->firstOrFail();
    }
}
