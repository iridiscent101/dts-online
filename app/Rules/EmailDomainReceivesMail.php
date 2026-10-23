<?php

namespace App\Rules;

use App\Support\MailDomain;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * The address's domain must be able to receive email (App\Support\MailDomain), so a typo such as "gmial.com" or a
 * made-up domain is caught before emails bounce. An address that is already saved and unchanged is not looked up again.
 */
class EmailDomainReceivesMail implements ValidationRule
{
    public function __construct(private ?string $unchanged = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = Str::lower(trim((string) $value));
        if ($email === '' || ($this->unchanged !== null && $email === Str::lower(trim($this->unchanged)))) {
            return;
        }

        if (! app(MailDomain::class)->acceptsMail($email)) {
            $fail('This email address cannot receive email. Check the part after @ (for example gmail.com), or leave it blank.');
        }
    }
}
