<?php

declare(strict_types=1);

namespace App\Services;

use Anthropic\Client;
use Anthropic\Lib\Tools\BetaRunnableTool;
use Illuminate\Support\Facades\Log;

class AgentService
{
    public function __construct(
        private readonly Client $anthropic,
        private readonly OnflyService $onfly,
    ) {}

    public function evaluate(
        array $reservation,
        ?string $prompt = null,
        ?float $budget = null,
        ?float $autoApproveThreshold = null,
        ?float $rejectThreshold = null,
    ): array {
        $budget               ??= (float) config('agent.approval.default_budget', 1000.0);
        $autoApproveThreshold ??= (float) config('agent.approval.auto_approve_threshold', 0.10);
        $rejectThreshold      ??= (float) config('agent.approval.reject_if_savings_above', 0.20);

        $decision = [
            'decision'     => 'needs_review',
            'reason'       => 'Nenhuma decisão foi tomada.',
            'alternative'  => null,
            'savings'      => null,
            'api_fallback' => false,
            'trace'        => [],
        ];

        Log::info('Agente: iniciando avaliação', [
            'reservation_id' => $reservation['id'] ?? null,
            'type'           => $reservation['type'] ?? null,
            'budget'         => $budget,
        ]);

        $runner = $this->anthropic->beta->messages->toolRunner(
            model: config('agent.anthropic.model', 'claude-opus-4-6'),
            maxTokens: (int) config('agent.anthropic.max_tokens', 16000),
            messages: $this->buildMessages($reservation, $prompt, $budget, $autoApproveThreshold, $rejectThreshold),
            tools: $this->buildTools($reservation, $budget, $decision),
        );

        foreach ($runner as $message) {
            foreach ($message->content as $block) {
                if ($block->type === 'text' && !empty(trim($block->text))) {
                    Log::debug('Agente: ' . $block->text);
                    $decision['trace'][] = [
                        'type' => 'thinking',
                        'text' => trim($block->text),
                        'at'   => now()->toDateTimeString(),
                    ];
                }
                if ($block->type === 'tool_use') {
                    // Record the model's intent to call a tool (before execution)
                    $decision['trace'][] = [
                        'type'  => 'tool_use',
                        'tool'  => $block->name,
                        'input' => (array) $block->input,
                        'at'    => now()->toDateTimeString(),
                    ];
                }
            }
        }

        Log::info('Agente: decisão', array_diff_key($decision, ['trace' => null]));

        return $decision;
    }

    private function buildTools(array $reservation, float $budget, array &$decision): array
    {
        $onfly = $this->onfly;

        return [
            new BetaRunnableTool(
                definition: [
                    'name'         => 'get_reservation_quote',
                    'description'  => 'Busca a cotação atual da reserva na Onfly. Retorna preço, fornecedor e detalhes.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'reservation_id' => ['type' => 'string', 'description' => 'ID da reserva'],
                            'type'           => [
                                'type'        => 'string',
                                'enum'        => ['flight', 'hotel', 'car', 'bus'],
                                'description' => 'Tipo da reserva',
                            ],
                        ],
                        'required' => ['reservation_id', 'type'],
                    ],
                ],
                run: function (array $input) use ($onfly, $reservation, &$decision): string {
                    Log::info('Tool: get_reservation_quote', $input);

                    $output = $onfly->getBookingOrMock(
                        $input['reservation_id'],
                        $reservation['type'],
                        $reservation['data'] ?? [],
                    );

                    $source = match(true) {
                        !empty($output['mocked']) => 'mock',
                        default                  => 'api',
                    };

                    if ($source === 'mock') {
                        $decision['api_fallback'] = true;
                    }

                    $decision['trace'][] = [
                        'type'   => 'tool_result',
                        'tool'   => 'get_reservation_quote',
                        'input'  => $input,
                        'output' => $output,
                        'source' => $source,
                        'at'     => now()->toDateTimeString(),
                    ];

                    return json_encode($output);
                },
            ),

            new BetaRunnableTool(
                definition: [
                    'name'         => 'search_cheaper_alternatives',
                    'description'  => 'Busca alternativas mais baratas na Onfly usando os mesmos parâmetros da reserva.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'type'          => [
                                'type'        => 'string',
                                'enum'        => ['flight', 'hotel', 'car', 'bus'],
                                'description' => 'Tipo da reserva',
                            ],
                            'search_params' => [
                                'type'                 => 'object',
                                'description'          => 'Parâmetros de busca (origem, destino, datas, passageiros, etc.)',
                                'additionalProperties' => true,
                            ],
                            'current_price' => [
                                'type'        => 'number',
                                'description' => 'Preço atual para comparação',
                            ],
                        ],
                        'required' => ['type', 'search_params', 'current_price'],
                    ],
                ],
                run: function (array $input) use ($onfly, &$decision): string {
                    Log::info('Tool: search_cheaper_alternatives', ['type' => $input['type']]);
                    $result = $onfly->searchAlternatives(
                        $input['type'],
                        $input['search_params'],
                        (float) $input['current_price'],
                    );

                    if (!empty($result['api_fallback'])) {
                        $decision['api_fallback'] = true;
                    }

                    $decision['trace'][] = [
                        'type'        => 'tool_result',
                        'tool'        => 'search_cheaper_alternatives',
                        'input'       => $input,
                        'output'      => $result,
                        'source'      => $result['api_fallback'] ? 'simulated' : 'api',
                        'at'          => now()->toDateTimeString(),
                    ];

                    return json_encode($result);
                },
            ),

            new BetaRunnableTool(
                definition: [
                    'name'         => 'make_decision',
                    'description'  => 'Registra a decisão final. SEMPRE deve ser chamada ao fim da análise.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'decision'    => ['type' => 'string', 'enum' => ['approved', 'rejected', 'needs_review']],
                            'reason'      => ['type' => 'string'],
                            'alternative' => [
                                'type'       => ['object', 'null'],
                                'properties' => [
                                    'supplier'    => ['type' => 'string'],
                                    'price'       => ['type' => 'number'],
                                    'description' => ['type' => 'string'],
                                ],
                            ],
                            'savings' => ['type' => ['number', 'null']],
                        ],
                        'required' => ['decision', 'reason'],
                    ],
                ],
                run: function (array $input) use (&$decision): string {
                    $decision['decision']    = $input['decision'];
                    $decision['reason']      = $input['reason'];
                    $decision['alternative'] = $input['alternative'] ?? null;
                    $decision['savings']     = isset($input['savings']) ? (float) $input['savings'] : null;

                    $decision['trace'][] = [
                        'type'     => 'tool_result',
                        'tool'     => 'make_decision',
                        'input'    => $input,
                        'output'   => ['recorded' => true],
                        'at'       => now()->toDateTimeString(),
                    ];

                    return json_encode(['recorded' => true]);
                },
            ),
        ];
    }

    private function buildMessages(array $reservation, ?string $prompt, float $budget, float $autoApproveThreshold, float $rejectThreshold): array
    {
        $autoApprovePercent = (int) ($autoApproveThreshold * 100);
        $rejectPercent      = (int) ($rejectThreshold * 100);

        $system = <<<PROMPT
Você é um agente de IA especializado em aprovar ou rejeitar reservas de viagens corporativas da Onfly.

## Regras de decisão:
1. **approved**: preço dentro do orçamento e sem alternativa mais de {$autoApprovePercent}% mais barata
2. **rejected**: existe alternativa com economia superior a {$rejectPercent}% — rejeite e sugira a mais barata
3. **needs_review**: acima do orçamento sem alternativas, ou situação especial

## Processo:
1. Use `get_reservation_quote` para obter o preço atual
2. Se acima do orçamento ou economia potencial relevante, use `search_cheaper_alternatives`
3. Finalize SEMPRE com `make_decision`

Orçamento disponível: R\$ {$budget}
PROMPT;

        $reservationJson = json_encode($reservation, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $content = "Sistema: {$system}\n\nAvalie esta reserva:\n```json\n{$reservationJson}\n```";

        if ($prompt) {
            $content .= "\n\nInstrução adicional: {$prompt}";
        }

        return [['role' => 'user', 'content' => $content]];
    }
}
