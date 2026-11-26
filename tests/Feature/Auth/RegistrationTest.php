<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users register signed in and land on the verification notice', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('verification.notice'));

    // Still unverified: the dashboard stays gated by the verified middleware.
    $this->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('newly registered user is sent a verification notification', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    Notification::assertSentTo(User::firstOrFail(), VerifyEmail::class);
});

test('registration still signs in when the verification mail transport fails', function () {
    Mail::extend('throwing', fn () => new class implements TransportInterface
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException('simulated SMTP failure');
        }

        public function __toString(): string
        {
            return 'throwing';
        }
    });
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.transport' => 'throwing',
    ]);
    Log::spy();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('verification.notice'));
    Log::shouldHaveReceived('warning')
        ->with('auth.verification_mail_failed', Mockery::type('array'));

    // No verified-state bypass: the new account remains unverified.
    expect(User::firstOrFail()->hasVerifiedEmail())->toBeFalse();
    $this->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});
