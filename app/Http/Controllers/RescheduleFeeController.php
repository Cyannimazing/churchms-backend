<?php

namespace App\Http\Controllers;

use App\Models\Church;
use App\Models\ChurchRescheduleFee;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class RescheduleFeeController extends Controller
{
    /**
     * Get the reschedule fee for a specific church
     */
    public function getChurchRescheduleFee(string $churchName): JsonResponse
    {
        try {
            // Sanitize church name by removing any unexpected suffix (e.g., ":1")
            $churchName = preg_replace('/:\d+$/', '', $churchName);
            // Convert URL-friendly church name to proper case (e.g., "holy-trinity-church" to "Holy Trinity Church")
            $name = str_replace('-', ' ', ucwords($churchName, '-'));

            // Find the church by name (case-insensitive)
            $church = Church::whereRaw('LOWER(ChurchName) = ?', [strtolower($name)])->first();

            if (!$church) {
                return response()->json([
                    'success' => false,
                    'message' => 'Church not found.',
                ], 404);
            }

            // Get the active reschedule fee
            $rescheduleFee = ChurchRescheduleFee::getActiveForChurch($church->ChurchID);

            return response()->json([
                'success' => true,
                'reschedule_fee' => $rescheduleFee,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching reschedule fee.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store or update the reschedule fee for a church
     */
    public function storeOrUpdate(Request $request, string $churchName): JsonResponse
    {
        try {
            // Validate request data
            $validator = Validator::make($request->all(), [
                'fee_name'  => 'required|string|max:255',
                'fee_type'  => 'required|in:percent,fixed',
                'fee_value' => 'required|numeric|min:0',
                'is_active' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            // Additional validation for percent type
            if ($request->fee_type === 'percent' && $request->fee_value > 100) {
                return response()->json([
                    'success' => false,
                    'message' => 'Percentage value cannot exceed 100.',
                ], 422);
            }

            // Sanitize church name by removing any unexpected suffix (e.g., ":1")
            $churchName = preg_replace('/:\d+$/', '', $churchName);
            // Convert URL-friendly church name to proper case (e.g., "holy-trinity-church" to "Holy Trinity Church")
            $name = str_replace('-', ' ', ucwords($churchName, '-'));

            // Find the church by name (case-insensitive)
            $church = Church::whereRaw('LOWER(ChurchName) = ?', [strtolower($name)])->first();

            if (!$church) {
                return response()->json([
                    'success' => false,
                    'message' => 'Church not found.',
                ], 404);
            }

            // Check if there's an existing active reschedule fee
            $existingFee = ChurchRescheduleFee::getActiveForChurch($church->ChurchID);

            if ($existingFee) {
                // Update existing fee
                $existingFee->update($request->only([
                    'fee_name',
                    'fee_type',
                    'fee_value',
                    'is_active',
                ]));
                $rescheduleFee = $existingFee;
            } else {
                // Create new fee
                $rescheduleFee = ChurchRescheduleFee::create([
                    'church_id' => $church->ChurchID,
                    'fee_name'  => $request->fee_name,
                    'fee_type'  => $request->fee_type,
                    'fee_value' => $request->fee_value,
                    'is_active' => $request->is_active ?? true,
                ]);
            }

            return response()->json([
                'success'        => true,
                'message'        => 'Reschedule fee saved successfully.',
                'reschedule_fee' => $rescheduleFee,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while saving reschedule fee.',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}