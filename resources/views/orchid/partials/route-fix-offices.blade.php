@once
    {{-- Offices for the "Add office route" and "Correct route" dialogs (dts.js), listed once per page. --}}
    <datalist id="additional-route-offices">
        @foreach (\App\Models\Document::officeOptions() as $officeOption)
            <option value="{{ $officeOption }}"></option>
        @endforeach
    </datalist>
@endonce
