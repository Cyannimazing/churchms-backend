<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request)
    {
        $request->authenticate();

        $user = $request->user();
        
        // Check if user already has an active session
        if ($user->tokens()->count() > 0) {
            return response()->json([
                'message' => 'This account is already logged in on another device. Please log out from the other device first.'
            ], 403);
        }
        
        // Create new token
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load(['profile.systemRole', 'contact'])
        ]);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): Response
    {
        // Only delete the current access token (not all user tokens)
        // This ensures logging out from one device doesn't affect other devices
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
