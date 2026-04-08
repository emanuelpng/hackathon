<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AgentEvaluation;
use App\Models\ApiCallLog;
use App\Services\AgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function __construct(
        private readonly AgentService $agent,
    ) {}

    /**
     * POST /api/agent/evaluate
     */
    public function evaluate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reservation'       => 'required|array',
            'reservation.id'    => 'required|string',
            'reservation.type'  => 'required|string|in:flight,hotel,car,bus',
            'reservation.data'  => 'required|array',
            'prompt'            => 'nullable|string|max:1000',
            'budget'            => 'nullable|numeric|min:0',
        ]);

        $budget = isset($validated['budget']) ? (float) $validated['budget'] : null;

        try {
            $result = $this->agent->evaluate(
                reservation: $validated['reservation'],
                prompt: $validated['prompt'] ?? null,
                budget: $budget,
            );

            AgentEvaluation::create([
                'reservation_id'    => $validated['reservation']['id'],
                'reservation_type'  => $validated['reservation']['type'],
                'reservation_data'  => $validated['reservation']['data'],
                'budget'            => $budget ?? config('agent.approval.default_budget'),
                'reservation_price' => $validated['reservation']['data']['price'] ?? null,
                'decision'          => $result['decision'],
                'reason'            => $result['reason'],
                'alternative'       => $result['alternative'] ?? null,
                'savings'           => $result['savings'] ?? null,
                'savings_percentage' => $result['alternative']['savings_percentage']
                    ?? ($result['savings'] && isset($validated['reservation']['data']['price']) && $validated['reservation']['data']['price'] > 0
                        ? round(($result['savings'] / $validated['reservation']['data']['price']) * 100, 2)
                        : null),
                'api_fallback'      => $result['api_fallback'] ?? false,
            ]);

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Erro interno ao processar a requisição.'], 500);
        }
    }

    /**
     * GET /api/agent/api-logs
     * Returns raw production API call history — proof of real Onfly API usage.
     */
    public function apiLogs(Request $request): JsonResponse
    {
        $logs = ApiCallLog::query()
            ->latest()
            ->limit(50)
            ->get();

        $summary = [
            'total_calls'    => ApiCallLog::count(),
            'successful'     => ApiCallLog::where('success', true)->count(),
            'failed'         => ApiCallLog::where('success', false)->count(),
            'avg_duration_ms' => (int) ApiCallLog::avg('duration_ms'),
            'endpoints'      => ApiCallLog::selectRaw('endpoint, count(*) as calls, avg(duration_ms) as avg_ms')
                ->groupBy('endpoint')
                ->get(),
        ];

        return response()->json(['summary' => $summary, 'logs' => $logs]);
    }

    /**
     * GET /api/agent/evaluations
     * Returns paginated evaluation history for reports.
     */
    public function evaluations(Request $request): JsonResponse
    {
        $query = AgentEvaluation::query()->latest();

        if ($request->filled('decision')) {
            $query->where('decision', $request->decision);
        }
        if ($request->filled('type')) {
            $query->where('reservation_type', $request->type);
        }

        $evaluations = $query->paginate(20);

        $summary = [
            'total'        => AgentEvaluation::count(),
            'approved'     => AgentEvaluation::where('decision', 'approved')->count(),
            'rejected'     => AgentEvaluation::where('decision', 'rejected')->count(),
            'needs_review' => AgentEvaluation::where('decision', 'needs_review')->count(),
            'total_savings' => AgentEvaluation::whereNotNull('savings')->sum('savings'),
        ];

        return response()->json([
            'summary'     => $summary,
            'evaluations' => $evaluations,
        ]);
    }
}
