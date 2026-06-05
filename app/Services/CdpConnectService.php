<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CdpConnectService
{
    protected string $url;
    protected string $key;

    public function __construct()
    {
        $this->url = config('services.cdp_api.url') ?? '';
        $this->key = config('services.cdp_api.key') ?? '';
    }

    /**
     * Fetch the employee performance metrics from CDP API using the employee code.
     *
     * @param string $employeeCode
     * @param string|null $periodKey
     * @return array|null
     */
    public function fetchEmployeeMetrics(string $employeeCode, ?string $periodKey = null): ?array
    {
        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->key,
                'Accept' => 'application/json',
            ])->get("{$this->url}/v1/external/employees-summary", [
                'search' => $employeeCode,
                'period_key' => $periodKey,
                'per_page' => 10,
            ]);

            if ($response->successful()) {
                $payload = $response->json();
                
                // Handle both paginated 'data.data' and direct array responses
                $data = $payload['data']['data'] ?? $payload['data'] ?? [];

                // Find the exact match for employee_code (case insensitive, trimmed)
                foreach ($data as $item) {
                    if (isset($item['employee_code']) && strtolower(trim($item['employee_code'])) === strtolower(trim($employeeCode))) {
                        return $item;
                    }
                }
            } else {
                Log::error('CDP Connect API returned error status', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'employee_code' => $employeeCode,
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('Error connecting to CDP Connect API', [
                'message' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
                'employee_code' => $employeeCode,
            ]);
        }

        return null;
    }
}
