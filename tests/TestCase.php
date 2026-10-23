<?php

namespace Tests;

use App\Support\MailDomain;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected FakeMailDomain $mailDomains;

    protected function setUp(): void
    {
        parent::setUp();

        // Email domains are checked against DNS in the app; tests use a fake so they never go online.
        $this->mailDomains = new FakeMailDomain;
        $this->app->instance(MailDomain::class, $this->mailDomains);
    }
}
