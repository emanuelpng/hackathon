<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\OnflyService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(private readonly OnflyService $onfly) {}

    /**
     * Redirect the admin browser to Onfly's OAuth authorization page.
     */
    public function redirectToOnfly(Request $request)
    {
        $state = Str::random(32);
        session(['onfly_oauth_state' => $state]);

        $redirectUri  = route('auth.onfly.callback');
        $authorizeUrl = $this->onfly->oauthAuthorizeUrl($redirectUri, $state);

        return redirect($authorizeUrl);
    }

    /**
     * Onfly redirects back here with ?code=...&state=...
     * Exchange the code for tokens and store them.
     */
    public function handleCallback(Request $request)
    {
        $error = $request->query('error');
        if ($error) {
            $desc = $request->query('error_description', $error);
            return redirect('/dashboard')->with('auth_error', "Onfly negou acesso: {$desc}");
        }

        $state         = $request->query('state', '');
        $expectedState = session('onfly_oauth_state', '');

        if (!$expectedState || !hash_equals($expectedState, $state)) {
            return redirect('/dashboard')->with('auth_error', 'State inválido — possível CSRF. Tente novamente.');
        }

        $code = $request->query('code', '');
        if (empty($code)) {
            return redirect('/dashboard')->with('auth_error', 'Código OAuth não recebido.');
        }

        try {
            $redirectUri = route('auth.onfly.callback');
            $this->onfly->exchangeOAuthCode($code, $redirectUri);
            return redirect('/dashboard')->with('auth_success', 'Autenticado com sucesso na Onfly!');
        } catch (\Throwable $e) {
            return redirect('/dashboard')->with('auth_error', 'Falha na autenticação: ' . $e->getMessage());
        }
    }
}
