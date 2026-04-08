<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onfly Agent — Observability</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .trace-thinking { border-left: 3px solid #6366f1; }
        .trace-tool_use { border-left: 3px solid #f59e0b; }
        .trace-tool_result { border-left: 3px solid #10b981; }
        pre { white-space: pre-wrap; word-break: break-all; }
    </style>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen font-mono text-sm">

{{-- Header --}}
<div class="bg-gray-900 border-b border-gray-700 px-6 py-4 flex items-center justify-between sticky top-0 z-10">
    <div class="flex items-center gap-3">
        <div class="w-2 h-2 rounded-full {{ $authStatus['healthy'] ? 'bg-green-400 animate-pulse' : 'bg-red-500' }}"></div>
        <span class="text-white font-bold text-base">Onfly Agent — Observability</span>
        @if($authStatus['healthy'])
            <span class="text-green-500 text-xs border border-green-800 rounded px-2 py-0.5">auth ok</span>
        @else
            <span class="text-red-400 text-xs border border-red-700 rounded px-2 py-0.5 font-bold">⚠ auth broken</span>
        @endif
        <a href="/dashboard" class="text-gray-500 text-xs hover:text-gray-300">↻ refresh</a>
    </div>
    <div class="flex items-center gap-4">
        <a href="/auth/tokens" class="text-blue-400 text-xs hover:text-blue-300 border border-blue-800 hover:border-blue-600 rounded px-2 py-0.5 transition">
            Atualizar tokens
        </a>
        <span class="text-gray-500 text-xs">{{ now()->format('d/m/Y H:i:s') }}</span>
    </div>
</div>

{{-- Flash messages --}}
@if(session('auth_success'))
<div class="bg-green-950 border-b-2 border-green-500 px-6 py-3">
    <span class="text-green-300 font-bold">✓ {{ session('auth_success') }}</span>
</div>
@endif
@if(session('auth_error'))
<div class="bg-red-950 border-b-2 border-red-500 px-6 py-3">
    <span class="text-red-300 font-bold">✗ {{ session('auth_error') }}</span>
</div>
@endif

{{-- Auth Warning Banner --}}
@if(!$authStatus['healthy'])
<div class="bg-red-950 border-b-2 border-red-500 px-6 py-4">
    <div class="flex items-start gap-4">
        <div class="text-red-400 text-2xl shrink-0">⚠</div>
        <div class="flex-1">
            <div class="text-red-300 font-bold text-base">Autenticação Onfly inválida — todas as avaliações estão em fallback</div>
            <div class="text-red-400 text-sm mt-1">{{ $authStatus['reason'] }}</div>
            @if($authStatus['broken_at'])
            <div class="text-red-600 text-xs mt-1">Falhou em: {{ $authStatus['broken_at'] }}</div>
            @endif
            <div class="mt-3">
                <a href="/auth/tokens"
                   class="inline-block bg-blue-600 hover:bg-blue-500 text-white font-bold px-5 py-2 rounded text-sm transition">
                    Atualizar tokens
                </a>
                <span class="text-gray-500 text-xs ml-3">Execute <code class="text-yellow-300">node get_tokens.js</code> localmente e cole os tokens no formulário.</span>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Stats --}}
<div class="grid grid-cols-7 gap-px bg-gray-700 border-b border-gray-700">
    @php
        $stats = [
            ['label' => 'Total',        'value' => $summary['total'],                              'color' => 'text-white'],
            ['label' => 'Approved',     'value' => $summary['approved'],                           'color' => 'text-green-400'],
            ['label' => 'Rejected',     'value' => $summary['rejected'],                           'color' => 'text-red-400'],
            ['label' => 'Review',       'value' => $summary['needs_review'],                       'color' => 'text-yellow-400'],
            ['label' => 'Savings',      'value' => 'R$ '.number_format($summary['total_savings'],2,',','.'), 'color' => 'text-emerald-400'],
            ['label' => 'API Calls',    'value' => $summary['api_calls'],                          'color' => 'text-blue-400'],
            ['label' => 'API Success',  'value' => $summary['api_success'],                        'color' => 'text-cyan-400'],
        ];
    @endphp
    @foreach($stats as $s)
    <div class="bg-gray-900 px-4 py-3 text-center">
        <div class="text-xs text-gray-500 uppercase tracking-wider">{{ $s['label'] }}</div>
        <div class="text-lg font-bold {{ $s['color'] }} mt-1">{{ $s['value'] }}</div>
    </div>
    @endforeach
</div>

<div class="grid grid-cols-2 gap-0 h-screen" style="height: calc(100vh - 112px)">

    {{-- LEFT: Evaluations --}}
    <div class="border-r border-gray-700 overflow-y-auto">
        <div class="px-4 py-2 bg-gray-800 border-b border-gray-700 text-xs text-gray-400 uppercase tracking-wider sticky top-0">
            Agent Evaluations
        </div>

        @forelse($evaluations as $ev)
        @php
            $badge = match($ev->decision) {
                'approved'     => 'bg-green-900 text-green-300 border border-green-700',
                'rejected'     => 'bg-red-900 text-red-300 border border-red-700',
                'needs_review' => 'bg-yellow-900 text-yellow-300 border border-yellow-700',
                default        => 'bg-gray-700 text-gray-300',
            };
            $icon = match($ev->decision) {
                'approved'     => '✓',
                'rejected'     => '✕',
                'needs_review' => '?',
                default        => '•',
            };
        @endphp

        <details class="border-b border-gray-800 group">
            <summary class="px-4 py-3 cursor-pointer hover:bg-gray-800 list-none flex items-start gap-3">
                <span class="text-xs px-2 py-0.5 rounded font-bold {{ $badge }} shrink-0 mt-0.5">{{ $icon }} {{ strtoupper($ev->decision) }}</span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-white font-bold truncate">{{ $ev->reservation_id }}</span>
                        <span class="text-gray-500 text-xs">{{ strtoupper($ev->reservation_type) }}</span>
                        @if($ev->reservation_price)
                            <span class="text-gray-400 text-xs">R$ {{ number_format($ev->reservation_price,2,',','.') }}</span>
                        @endif
                        @if($ev->savings_percentage)
                            <span class="text-emerald-400 text-xs font-bold">-{{ $ev->savings_percentage }}%</span>
                        @endif
                        @if($ev->api_fallback)
                            <span class="text-xs text-orange-400 border border-orange-700 rounded px-1">fallback</span>
                        @endif
                    </div>
                    <div class="text-gray-400 text-xs mt-1 truncate">{{ $ev->reason }}</div>
                    <div class="text-gray-600 text-xs mt-0.5">{{ $ev->created_at->format('d/m H:i:s') }}</div>
                </div>
            </summary>

            <div class="bg-gray-950 px-4 py-3 space-y-2">
                {{-- Reason --}}
                <div class="text-xs text-gray-400 bg-gray-900 rounded p-3 border border-gray-700">
                    <div class="text-gray-500 uppercase text-xs mb-1">Reason</div>
                    {{ $ev->reason }}
                </div>

                {{-- Alternative --}}
                @if($ev->alternative)
                <div class="text-xs bg-gray-900 rounded p-3 border border-green-800">
                    <div class="text-gray-500 uppercase text-xs mb-1">Alternative</div>
                    <span class="text-green-400 font-bold">{{ $ev->alternative['supplier'] ?? '' }}</span>
                    <span class="text-white mx-2">R$ {{ number_format($ev->alternative['price'] ?? 0, 2, ',', '.') }}</span>
                    <span class="text-gray-400">{{ $ev->alternative['description'] ?? '' }}</span>
                </div>
                @endif

                {{-- Trace --}}
                @if($ev->trace)
                <div class="text-xs">
                    <div class="text-gray-500 uppercase text-xs mb-2">Agent Trace ({{ count($ev->trace) }} steps)</div>
                    <div class="space-y-1.5">
                        @foreach($ev->trace as $step)
                        @php
                            $cls = 'trace-' . ($step['type'] ?? 'thinking');
                            $label = match($step['type'] ?? '') {
                                'thinking'    => ['🧠 Thinking',     'text-indigo-400'],
                                'tool_use'    => ['🔧 Tool Call',    'text-amber-400'],
                                'tool_result' => ['📦 Tool Result',  'text-emerald-400'],
                                default       => ['• Step',          'text-gray-400'],
                            };
                        @endphp
                        <div class="{{ $cls }} bg-gray-900 rounded-r p-2.5 border border-gray-800">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="font-bold {{ $label[1] }}">{{ $label[0] }}</span>
                                @if(!empty($step['tool']))
                                    <span class="text-gray-300">{{ $step['tool'] }}</span>
                                @endif
                                @if(!empty($step['source']))
                                    <span class="text-xs text-gray-600 border border-gray-700 rounded px-1">{{ $step['source'] }}</span>
                                @endif
                                <span class="text-gray-600 text-xs ml-auto">{{ $step['at'] ?? '' }}</span>
                            </div>

                            @if($step['type'] === 'thinking')
                                <div class="text-gray-300 leading-relaxed">{{ $step['text'] }}</div>
                            @elseif($step['type'] === 'tool_use')
                                <pre class="text-amber-300 text-xs">{{ json_encode($step['input'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            @elseif($step['type'] === 'tool_result')
                                @if(!empty($step['input']))
                                <div class="text-gray-500 text-xs mb-1">Input: <span class="text-gray-400">{{ json_encode($step['input']) }}</span></div>
                                @endif
                                @php $out = $step['output'] ?? []; @endphp
                                @if(isset($out['alternatives']))
                                    <div class="text-gray-400">Found {{ count($out['alternatives']) }} alternatives — cheapest: R$ {{ number_format($out['cheapest']['price'] ?? 0, 2, ',', '.') }} ({{ $out['cheapest']['supplier'] ?? '' }})</div>
                                @elseif(isset($out['price']))
                                    <div class="text-gray-400">Price: R$ {{ number_format($out['price'], 2, ',', '.') }} — {{ $out['source'] ?? '' }}</div>
                                @elseif(isset($out['decision']))
                                    <div class="text-gray-400">Decision: {{ $out['decision'] }} — {{ Str::limit($out['reason'] ?? '', 120) }}</div>
                                @elseif(isset($out['recorded']))
                                    <div class="text-gray-400">Decision recorded.</div>
                                @else
                                    <pre class="text-gray-400 text-xs">{{ json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                @endif
                            @endif
                        </div>
                        @endforeach
                    </div>
                </div>
                @else
                    <div class="text-gray-600 text-xs italic">No trace captured (evaluation pre-dates this feature)</div>
                @endif
            </div>
        </details>
        @empty
        <div class="px-4 py-8 text-center text-gray-600">No evaluations yet.</div>
        @endforelse
    </div>

    {{-- RIGHT: API Calls --}}
    <div class="overflow-y-auto">
        <div class="px-4 py-2 bg-gray-800 border-b border-gray-700 text-xs text-gray-400 uppercase tracking-wider sticky top-0">
            Onfly API Calls
        </div>

        @forelse($apiLogs as $log)
        @php
            $statusColor = match(true) {
                $log->status_code >= 200 && $log->status_code < 300 => 'text-green-400',
                $log->status_code >= 400 && $log->status_code < 500 => 'text-yellow-400',
                $log->status_code >= 500                             => 'text-red-400',
                default                                              => 'text-gray-500',
            };
        @endphp
        <details class="border-b border-gray-800 group">
            <summary class="px-4 py-2.5 cursor-pointer hover:bg-gray-800 list-none flex items-center gap-3">
                <span class="font-bold {{ $statusColor }} w-8 shrink-0 text-center">{{ $log->status_code ?? '—' }}</span>
                <span class="text-gray-500 w-10 shrink-0">{{ $log->method }}</span>
                <span class="text-gray-300 flex-1 truncate">{{ $log->endpoint }}</span>
                <span class="text-gray-600 text-xs shrink-0">{{ $log->duration_ms }}ms</span>
                <span class="text-gray-700 text-xs shrink-0">{{ $log->created_at->format('H:i:s') }}</span>
            </summary>

            <div class="bg-gray-950 px-4 py-3 space-y-2 text-xs">
                @if($log->request_payload)
                <div>
                    <div class="text-gray-500 uppercase mb-1">Request</div>
                    <pre class="text-amber-300 bg-gray-900 rounded p-2 border border-gray-800">{{ json_encode($log->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
                @endif

                @if($log->response_body)
                <div>
                    <div class="text-gray-500 uppercase mb-1">Response</div>
                    <pre class="text-green-300 bg-gray-900 rounded p-2 border border-gray-800 max-h-64 overflow-y-auto">{{ json_encode($log->response_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
                @elseif($log->response_raw)
                <div>
                    <div class="text-gray-500 uppercase mb-1">Response (raw)</div>
                    <pre class="text-red-300 bg-gray-900 rounded p-2 border border-gray-800">{{ $log->response_raw }}</pre>
                </div>
                @endif
            </div>
        </details>
        @empty
        <div class="px-4 py-8 text-center text-gray-600">No API calls yet.</div>
        @endforelse
    </div>
</div>

</body>
</html>
