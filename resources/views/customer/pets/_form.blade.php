@if ($errors->any())
    <div class="booking-alert booking-alert-error" role="alert">
        <p>Please review the following:</p>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form class="pet-form" method="POST" action="{{ $action }}">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="booking-form-grid">
        <div class="booking-field">
            <label for="name">Pet name</label>
            <input id="name" name="name" type="text" maxlength="100" value="{{ old('name', $pet->name) }}" required>
            @error('name') <span class="pet-error">{{ $message }}</span> @enderror
        </div>
        <div class="booking-field">
            <label for="species">Species</label>
            <input id="species" name="species" type="text" maxlength="50" value="{{ old('species', $pet->species) }}" required>
            @error('species') <span class="pet-error">{{ $message }}</span> @enderror
        </div>
        <div class="booking-field">
            <label for="breed">Breed <span>(optional)</span></label>
            <input id="breed" name="breed" type="text" maxlength="100" value="{{ old('breed', $pet->breed) }}">
            @error('breed') <span class="pet-error">{{ $message }}</span> @enderror
        </div>
        <div class="booking-field">
            <label for="sex">Sex <span>(optional)</span></label>
            <input id="sex" name="sex" type="text" maxlength="20" value="{{ old('sex', $pet->sex) }}">
            @error('sex') <span class="pet-error">{{ $message }}</span> @enderror
        </div>
        <div class="booking-field">
            <label for="birth_date">Birth date <span>(optional)</span></label>
            <input id="birth_date" name="birth_date" type="date" max="{{ now()->toDateString() }}" value="{{ old('birth_date', $pet->birth_date) }}">
            @error('birth_date') <span class="pet-error">{{ $message }}</span> @enderror
        </div>
        <div class="booking-field">
            <label for="weight">Weight in kg <span>(optional)</span></label>
            <input id="weight" name="weight" type="number" min="0" max="9999.99" step="0.01" value="{{ old('weight', $pet->weight) }}">
            @error('weight') <span class="pet-error">{{ $message }}</span> @enderror
        </div>
        <div class="booking-field">
            <label for="color">Color <span>(optional)</span></label>
            <input id="color" name="color" type="text" maxlength="100" value="{{ old('color', $pet->color) }}">
            @error('color') <span class="pet-error">{{ $message }}</span> @enderror
        </div>
        <div class="booking-field">
            <label for="status">Booking availability</label>
            <select id="status" name="status" required>
                <option value="active" @selected(old('status', $pet->status) === 'active')>Active</option>
                <option value="inactive" @selected(old('status', $pet->status) === 'inactive')>Inactive</option>
            </select>
            @error('status') <span class="pet-error">{{ $message }}</span> @enderror
        </div>
        <div class="booking-field">
            <label for="notes">Notes <span>(optional)</span></label>
            <textarea id="notes" name="notes" rows="4">{{ old('notes', $pet->notes) }}</textarea>
            @error('notes') <span class="pet-error">{{ $message }}</span> @enderror
        </div>
    </div>

    <div class="pet-form-actions">
        <button class="button customer-submit" type="submit">{{ $submitLabel }}</button>
        <a class="booking-history-link" href="{{ route('customer.pets.index') }}">Cancel</a>
    </div>
</form>
