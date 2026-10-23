<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KioskSessionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_kiosk_is_signed_out_when_its_password_changes(): void
    {
        $kiosk = User::factory()->create(['permissions' => ['platform.kiosk' => true]]);
        $this->actingAs($kiosk)->get(route('kiosk'))->assertOk();
        $this->getJson(route('kiosk.current'))->assertOk();

        // The password is changed (for example because it leaked); the old session stops working.
        $kiosk->forceFill(['password' => Hash::make('Another-Kiosk-Pass-2026!')])->save();

        $this->getJson(route('kiosk.current'))->assertUnauthorized();
        $this->get(route('kiosk'))->assertRedirect();
    }

    public function test_the_kiosk_keeps_working_while_its_password_is_unchanged(): void
    {
        $kiosk = User::factory()->create(['permissions' => ['platform.kiosk' => true]]);

        $this->actingAs($kiosk)->get(route('kiosk'))->assertOk();
        $this->getJson(route('kiosk.current'))->assertOk();
        $this->getJson(route('kiosk.current'))->assertOk();
    }
}
