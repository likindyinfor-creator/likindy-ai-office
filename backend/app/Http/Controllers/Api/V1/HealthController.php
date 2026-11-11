<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
        } catch (Throwable $exception) {
            Log::warning('API readiness check failed.', [
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'status' => 'unavailable',
                'service' => 'likindy-ai-office-api',
                'version' => 'v1',
                'checks' => ['database' => 'unavailable'],
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'service' => 'likindy-ai-office-api',
            'version' => 'v1',
            'checks' => ['database' => 'ok'],
        ]);
    }
}
