<?php declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\SocialProvider;
use RuntimeException;

/**
 * Thrown when a provider callback cannot be turned into a sign-in. Carries
 * the message the user sees on the login page, so each case says what went
 * wrong and what to do next instead of a generic failure.
 */
final class SocialLoginFailed extends RuntimeException
{
    public static function missingAccountId(SocialProvider $provider): self
    {
        return new self(__(
            ':provider did not identify the account. Please try again.',
            ['provider' => $provider->label()],
        ));
    }

    public static function missingEmail(SocialProvider $provider): self
    {
        return new self(__(
            ':provider did not share an email address, so we cannot create your account. Sign up with your email instead.',
            ['provider' => $provider->label()],
        ));
    }

    public static function unverifiedEmail(SocialProvider $provider): self
    {
        return new self(__(
            'An account already uses this email address, and :provider has not confirmed the address belongs to you. Log in with your password instead.',
            ['provider' => $provider->label()],
        ));
    }

    public static function ambiguousEmail(SocialProvider $provider): self
    {
        return new self(__(
            'More than one account uses this email address. Log in with your password, or contact support.',
            ['provider' => $provider->label()],
        ));
    }

    public static function alreadyLinked(SocialProvider $provider): self
    {
        return new self(__(
            'This account is already connected to a different :provider account. Log in with that one, or use your password.',
            ['provider' => $provider->label()],
        ));
    }

    public static function cancelled(SocialProvider $provider): self
    {
        return new self(__(
            'The :provider sign-in did not finish. Please try again.',
            ['provider' => $provider->label()],
        ));
    }
}
