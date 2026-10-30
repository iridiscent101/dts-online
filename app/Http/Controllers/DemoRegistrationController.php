<?php

namespace App\Http\Controllers;

use App\Auth\AccountActivity;
use App\Models\Office;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Orchid\Platform\Models\Role;

/**
 * Demo sign-up (/demo-register): participants create their own staff account for one office, then sign in on the
 * normal sign-in page (their email is filled in). Only
 * available while config('dts.demo_registration.enabled') is on, and only for sign-in emails at the demo domain
 * (test.com). The role is Personnel, or Records Unit for Records Unit staff (checked against the office); never
 * administrator, supervisor or kiosk. An optional Gmail recovery address receives the usual verification link.
 */
class DemoRegistrationController extends Controller
{
    /** The only roles the demo page hands out. */
    public const ROLES = [
        'personnel' => 'Personnel',
        'records-unit' => 'Records Unit',
    ];

    public function show(): View
    {
        abort_unless(self::enabled(), 404);

        return view('platform::auth.demo-register', [
            'offices' => self::officeNames(),
            'domain' => self::domain(),
            'roles' => self::ROLES,
            'recordsOffices' => Office::RECORDS_UNIT_NAMES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(self::enabled(), 404);
        $domain = self::domain();

        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'recovery_email' => Str::lower(trim((string) $request->input('recovery_email'))) ?: null,
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                'ends_with:@'.$domain,
                Rule::unique(User::class, 'email'),
            ],
            'office' => ['required', 'string', Rule::in(self::officeNames()->all())],
            // Records Unit staff take the Records Unit role; everyone else is Personnel.
            'role' => [
                'required', Rule::in(array_keys(self::ROLES)),
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    $isRecords = in_array($request->input('office'), Office::RECORDS_UNIT_NAMES, true);
                    if ($isRecords && $value !== 'records-unit') {
                        $fail('Records Unit staff choose the Records Unit role.');
                    }
                    if (! $isRecords && $value !== 'personnel') {
                        $fail('Choose Personnel. The Records Unit role is only for staff of the Records Unit.');
                    }
                },
            ],
            'recovery_email' => ['nullable', 'string', 'email:rfc,filter', 'max:255', 'ends_with:@gmail.com', 'different:email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'email.ends_with' => 'Use an email that ends in @'.$domain.' for the demo.',
            'email.unique' => 'That email already has an account. Sign in instead, or choose another name before @'.$domain.'.',
            'office.in' => 'Choose your office from the list.',
            'recovery_email.ends_with' => 'Use a Gmail address (ending in @gmail.com) for password resets, or leave it blank.',
        ], [
            'name' => 'full name',
            'email' => 'sign-in email',
            'recovery_email' => 'Gmail for password resets',
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $user = new User;
            $user->forceFill([
                'name' => trim($validated['name']),
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'email_verified_at' => now(),
                'must_change_password' => false,
            ]);
            $user->setNotificationEmail($validated['recovery_email'] ?? null);
            $user->save();

            $user->roles()->sync([Role::query()->where('slug', $validated['role'])->firstOrFail()->id]);
            $user->offices()->sync([Office::query()->where('name', $validated['office'])->firstOrFail()->id]);

            AccountActivity::record(AccountActivity::DEMO_REGISTERED, $user, $user, ['office' => $validated['office']]);

            return $user;
        });

        if (filled($user->notification_email)) {
            RecoveryEmailController::sendVerification($user);
        }

        // Not signed in here: they sign in on the normal page with the email filled in, so they practise it once.
        return redirect()->route('platform.login')
            ->withInput(['email' => $user->email])
            ->with('status', 'Your demo account is ready. Sign in with your email and the password you chose.');
    }

    public static function enabled(): bool
    {
        return (bool) config('dts.demo_registration.enabled');
    }

    private static function domain(): string
    {
        return Str::lower(ltrim((string) config('dts.demo_registration.email_domain', 'test.com'), '@'));
    }

    /** Active Division offices staff can belong to, in the usual order. */
    private static function officeNames()
    {
        return Office::query()
            ->where('is_sdo', true)
            ->where('is_active', true)
            ->orderBy('sdo_sort_order')
            ->orderBy('name')
            ->pluck('name');
    }
}
