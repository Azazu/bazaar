<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(401);

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
        }

        if ($user->markEmailAsVerified()) {
            // User verifies through the MustVerifyEmail *trait*; the interface is deliberately not
            // implemented (that would make the `verified` middleware mandatory — open decision).
            event(new Verified($user)); // @phpstan-ignore argument.type
        }

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
