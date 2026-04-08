<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AgentEvaluation;
use App\Models\ApiCallLog;
use App\Services\OnflyService;

class DashboardController extends Controller
{
    public function index(OnflyService $onfly)
    {
        $evaluations = AgentEvaluation::latest()->limit(20)->get();
        $apiLogs     = ApiCallLog::latest()->limit(50)->get();
        $authStatus  = $onfly->getAuthStatus();

        $summary = [
            'total'         => AgentEvaluation::count(),
            'approved'      => AgentEvaluation::where('decision', 'approved')->count(),
            'rejected'      => AgentEvaluation::where('decision', 'rejected')->count(),
            'needs_review'  => AgentEvaluation::where('decision', 'needs_review')->count(),
            'total_savings' => (float) AgentEvaluation::whereNotNull('savings')->sum('savings'),
            'api_calls'     => ApiCallLog::count(),
            'api_success'   => ApiCallLog::where('success', true)->count(),
        ];

        return view('dashboard', compact('evaluations', 'apiLogs', 'summary', 'authStatus'));
    }
}
