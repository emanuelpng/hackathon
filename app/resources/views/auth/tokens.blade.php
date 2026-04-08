<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onfly — Atualizar Tokens</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen font-mono text-sm flex items-center justify-center">

<div class="w-full max-w-2xl px-6">
    <div class="mb-6">
        <a href="/dashboard" class="text-gray-500 hover:text-gray-300 text-xs">← voltar ao dashboard</a>
    </div>

    <div class="bg-gray-900 border border-gray-700 rounded-lg p-8">
        <h1 class="text-white font-bold text-xl mb-2">Atualizar Tokens Onfly</h1>
        <p class="text-gray-400 text-xs mb-6">
            Execute <code class="bg-gray-800 px-1 rounded text-yellow-300">node get_tokens.js</code> localmente,
            copie os tokens do output e cole abaixo.
        </p>

        @if ($errors->any())
        <div class="bg-red-950 border border-red-700 rounded p-3 mb-4 text-red-300 text-xs">
            @foreach ($errors->all() as $error) <div>{{ $error }}</div> @endforeach
        </div>
        @endif

        <form method="POST" action="/auth/tokens" class="space-y-5">
            @csrf

            <div>
                <label class="block text-gray-400 text-xs mb-1 uppercase tracking-wider">
                    Refresh Token <span class="text-red-400">*</span>
                </label>
                <textarea name="refresh_token" rows="3"
                    placeholder="def50200..."
                    class="w-full bg-gray-800 border border-gray-600 rounded px-3 py-2 text-green-300 text-xs focus:outline-none focus:border-blue-500 resize-none"
                    required>{{ old('refresh_token') }}</textarea>
                <p class="text-gray-600 text-xs mt-1">Linha <code>refresh_token</code> do output do get_tokens.js</p>
            </div>

            <div>
                <label class="block text-gray-400 text-xs mb-1 uppercase tracking-wider">
                    Gateway Refresh Token <span class="text-gray-500">(opcional)</span>
                </label>
                <textarea name="gateway_refresh_token" rows="3"
                    placeholder="eyJ0eXAiOiJKV1Qi..."
                    class="w-full bg-gray-800 border border-gray-600 rounded px-3 py-2 text-green-300 text-xs focus:outline-none focus:border-blue-500 resize-none">{{ old('gateway_refresh_token') }}</textarea>
                <p class="text-gray-600 text-xs mt-1">Linha <code>gateway_refresh_token</code> do output do get_tokens.js</p>
            </div>

            <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 rounded transition">
                Salvar Tokens
            </button>
        </form>
    </div>

    <div class="mt-6 bg-gray-900 border border-gray-700 rounded-lg p-5">
        <div class="text-gray-400 text-xs uppercase tracking-wider mb-3 font-bold">Como obter os tokens:</div>
        <div class="space-y-2 text-xs text-gray-300">
            <div><span class="text-yellow-300">1.</span> No terminal local:</div>
            <div class="bg-gray-800 rounded p-3 text-green-300 font-mono">node /home/andreribeiro/projects/hackathon/onfly-oauth-skill/get_tokens.js</div>
            <div><span class="text-yellow-300">2.</span> Abra a URL no browser e faça login na Onfly.</div>
            <div><span class="text-yellow-300">3.</span> Copie os valores de <code class="text-yellow-200">refresh_token</code> e <code class="text-yellow-200">gateway_refresh_token</code> do output.</div>
            <div><span class="text-yellow-300">4.</span> Cole acima e clique em <strong>Salvar Tokens</strong>.</div>
        </div>
    </div>
</div>

</body>
</html>
