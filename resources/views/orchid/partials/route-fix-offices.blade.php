@once
    {{-- Offices for the "Send to another office" and "Correct route" dialogs (dts.js), listed once per page. --}}
    <datalist id="additional-route-offices">
        @foreach (\App\Models\Document::officeOptions() as $officeOption)
            <option value="{{ $officeOption }}"></option>
        @endforeach
    </datalist>
@endonce
