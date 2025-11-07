<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(Request $request, $id, $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return redirect(config('app.frontend_url').'/login?verified=0');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect(config('app.frontend_url').'/login?verified=1');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        // Revoke all tokens to force re-login
        $user->tokens()->delete();

        // Logout if authenticated
        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect(config('app.frontend_url').'/login?verified=1');
    }
}
