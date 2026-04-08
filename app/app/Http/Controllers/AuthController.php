<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\OnflyService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly OnflyService $onfly) {}

    /**
     * Show the token input form.
     */
    public function showTokenForm()
    {
        return view('auth.tokens');
    }

    /**
     * Accept tokens pasted manually and persist them to DB.
     */
    public function storeTokens(Request $request)
    {
        $request->validate([
            'refresh_token'          => 'required|string',
            'gateway_refresh_token'  => 'nullable|string',
        ]);

        $this->onfly->storeTokensManually(
            $request->input('refresh_token'),
            $request->input('gateway_refresh_token'),
        );

        return redirect('/dashboard')->with('auth_success', 'Tokens salvos com sucesso!');
    }
}
