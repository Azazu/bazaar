<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueTokenRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Sanctum personal access tokens for API clients (mobile apps, scripts). */
class TokenController extends Controller
{
    /** Exchange credentials for a bearer token. */
    public function store(IssueTokenRequest $request): JsonResponse
    {
        $token = $request->authenticate()->createToken($request->input('device_name'));

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ], 201);
    }

    /** Revoke the token used for this request (log out this device only). */
    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
