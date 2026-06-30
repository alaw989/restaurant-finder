<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * spec-076: the Pulse dashboard gate must be an explicit email allow-list in
 * production, not "any authenticated user" (registration is open). The test
 * env is non-local, so the production branch of the gate is exercised directly.
 */
class PulseGateTest extends TestCase
{
    public function test_guest_is_denied(): void
    {
        $this->assertFalse(Gate::allows('viewPulse'));
    }

    public function test_non_allowlisted_user_is_denied(): void
    {
        Config::set('pulse.admin_emails', 'owner@example.com');

        $user = User::factory()->make(['email' => 'random@example.com']);
        $this->assertFalse(Gate::forUser($user)->allows('viewPulse'));
    }

    public function test_allowlisted_user_is_allowed(): void
    {
        Config::set('pulse.admin_emails', 'owner@example.com, admin@example.com');

        $user = User::factory()->make(['email' => 'admin@example.com']);
        $this->assertTrue(Gate::forUser($user)->allows('viewPulse'));
    }

    public function test_empty_allowlist_denies_everyone(): void
    {
        Config::set('pulse.admin_emails', '');

        $user = User::factory()->make(['email' => 'anyone@example.com']);
        $this->assertFalse(Gate::forUser($user)->allows('viewPulse'));
    }

    public function test_allowlist_match_is_exact_not_substring(): void
    {
        // "attacker@example.com" must not match an allowlist of "owner@example.com".
        Config::set('pulse.admin_emails', 'owner@example.com');

        $user = User::factory()->make(['email' => 'notowner@example.com']);
        $this->assertFalse(Gate::forUser($user)->allows('viewPulse'));
    }
}
