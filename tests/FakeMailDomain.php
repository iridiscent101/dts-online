<?php

namespace Tests;

use App\Support\MailDomain;

/**
 * Stand-in for the DNS lookup in tests: every domain takes mail except the ones listed, so tests never make real
 * lookups and do not depend on the network.
 */
class FakeMailDomain extends MailDomain
{
    /** @var list<string> */
    public array $withoutMail = ['nomail.example', 'gmial.com'];

    /** @var list<string> domains looked up, to check the cache and the "unchanged address" shortcut */
    public array $lookups = [];

    protected function hasMailRecords(string $domain): bool
    {
        $this->lookups[] = $domain;

        return ! in_array($domain, $this->withoutMail, true);
    }
}
