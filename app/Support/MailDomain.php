<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Whether an email domain can receive mail, from its DNS records: a mail server (MX), or, as the mail standard allows,
 * an address record (A/AAAA) when it has no MX. A "null MX" (target ".") means the domain takes no mail.
 *
 * It cannot tell whether one mailbox exists, only whether the domain could take mail. When DNS itself cannot be reached
 * (the server is offline), every address is accepted rather than turning away real ones. Answers are cached for a day.
 * Tests swap this class for a fake (Tests\TestCase), so they never make real lookups.
 */
class MailDomain
{
    // A domain that always has mail servers; if even this fails, DNS is down, not the client's domain.
    private const KNOWN_GOOD = 'gmail.com';

    public function acceptsMail(string $email): bool
    {
        $domain = Str::lower(trim(Str::afterLast($email, '@')));
        if ($domain === '' || ! str_contains($domain, '.')) {
            return false;
        }

        return Cache::remember('mail-domain:'.$domain, now()->addDay(), function () use ($domain): bool {
            if ($this->hasMailRecords($domain)) {
                return true;
            }

            // Not found: trust the "no" only if DNS is answering at all.
            return ! $this->hasMailRecords(self::KNOWN_GOOD);
        });
    }

    protected function hasMailRecords(string $domain): bool
    {
        $mx = @dns_get_record($domain.'.', DNS_MX);
        if (is_array($mx) && $mx !== []) {
            // A single "null MX" record (RFC 7505) says the domain accepts no mail.
            return ! (count($mx) === 1 && in_array(trim((string) ($mx[0]['target'] ?? '')), ['', '.'], true));
        }

        return @checkdnsrr($domain.'.', 'A') || @checkdnsrr($domain.'.', 'AAAA');
    }
}
