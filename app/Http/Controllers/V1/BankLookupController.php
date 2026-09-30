<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\PurposeCode;
use Illuminate\Http\JsonResponse;

class BankLookupController extends Controller
{
    /**
     * List active banks with their SWIFT code and account number format.
     */
    public function banks(): JsonResponse
    {
        $banks = Bank::active()->orderBy('name')->get([
            'id', 'name', 'swift_code', 'account_number_length',
            'account_number_format', 'account_number_example', 'is_commercial',
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $banks,
        ]);
    }

    /**
     * List active purpose codes.
     */
    public function purposeCodes(): JsonResponse
    {
        $codes = PurposeCode::active()->orderBy('code')->get(['id', 'code', 'description', 'is_default']);

        return response()->json([
            'status' => 'success',
            'data' => $codes,
        ]);
    }
}
