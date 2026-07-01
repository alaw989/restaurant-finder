<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    // spec-089: User implements MustVerifyEmail, so the Registered event fires
    // Illuminate\Auth\Notifications\VerifyEmail (the on-ramp toward requiring a
    // verified email). The mailer is 'log'/'array', so this activates the
    // verification infrastructure rather than delivering to an inbox — real
    // verification (and any future favorites `verified`-gate) needs SMTP.
    public function test_registration_sends_email_verification_notification(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Verify Me',
            'email' => 'verifyme@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'verifyme@example.com')->first();
        $this->assertNotNull($user);
        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
