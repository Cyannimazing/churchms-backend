<?php

namespace App\Http\Controllers;

use App\Models\Church;
use App\Models\ChurchConvenienceFee;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ConvenienceFeeController extends Controller
{
    /**
     * Get the convenience fee for a specific church
     */
    public function getChurchConvenienceFee(string $churchName): JsonResponse
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
                    'message' => 'Church not found.'
                ], 404);
            }

            // Get the active convenience fee
            $convenienceFee = ChurchConvenienceFee::getActiveForChurch($church->ChurchID);

            return response()->json([
                'success' => true,
                'convenience_fee' => $convenienceFee
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching convenience fee.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Store or update the convenience fee for a church
     */
    public function storeOrUpdate(Request $request, string $churchName): JsonResponse
    {
        try {
            // Validate request data
            $validator = Validator::make($request->all(), [
                'fee_name' => 'required|string|max:255',
                'fee_type' => 'required|in:percent,fixed',
                'fee_value' => 'required|numeric|min:0',
                'is_active' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Additional validation for percent type
            if ($request->fee_type === 'percent' && $request->fee_value > 100) {
                return response()->json([
                    'success' => false,
                    'message' => 'Percentage value cannot exceed 100.'
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
                    'message' => 'Church not found.'
                ], 404);
            }

            // Check if there's an existing active convenience fee
            $existingFee = ChurchConvenienceFee::getActiveForChurch($church->ChurchID);

            if ($existingFee) {
                // Update existing fee
                $existingFee->update($request->only([
                    'fee_name', 'fee_type', 'fee_value', 'is_active'
                ]));
                $convenienceFee = $existingFee;
            } else {
                // Create new fee
                $convenienceFee = ChurchConvenienceFee::create([
                    'church_id' => $church->ChurchID,
                    'fee_name' => $request->fee_name,
                    'fee_type' => $request->fee_type,
                    'fee_value' => $request->fee_value,
                    'is_active' => $request->is_active ?? true,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Convenience fee saved successfully.',
                'convenience_fee' => $convenienceFee
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while saving convenience fee.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Calculate refund amount with convenience fee deduction
     */
    public function calculateRefund(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'church_name' => 'required|string',
                'original_amount' => 'required|numeric|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Sanitize church name by removing any unexpected suffix (e.g., ":1")
            $churchName = preg_replace('/:\d+$/', '', $request->church_name);
            // Convert URL-friendly church name to proper case (e.g., "holy-trinity-church" to "Holy Trinity Church")
            $name = str_replace('-', ' ', ucwords($churchName, '-'));
            
            // Find the church by name (case-insensitive)
            $church = Church::whereRaw('LOWER(ChurchName) = ?', [strtolower($name)])->first();
            
            if (!$church) {
                return response()->json([
                    'success' => false,
                    'message' => 'Church not found.'
                ], 404);
            }

            $originalAmount = (float) $request->original_amount;
            $convenienceFee = ChurchConvenienceFee::getActiveForChurch($church->ChurchID);

            $result = [
                'original_amount' => $originalAmount,
                'convenience_fee_amount' => 0,
                'refund_amount' => $originalAmount,
                'has_convenience_fee' => false
            ];

            if ($convenienceFee && $convenienceFee->is_active) {
                $feeAmount = $convenienceFee->calculateFee($originalAmount);
                $refundAmount = $convenienceFee->calculateRefundAmount($originalAmount);

                $result = [
                    'original_amount' => $originalAmount,
                    'convenience_fee_amount' => $feeAmount,
                    'refund_amount' => $refundAmount,
                    'has_convenience_fee' => true,
                    'convenience_fee' => [
                        'fee_name' => $convenienceFee->fee_name,
                        'fee_type' => $convenienceFee->fee_type,
                        'fee_value' => $convenienceFee->fee_value
                    ]
                ];
            }

            return response()->json([
                'success' => true,
                'calculation' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while calculating refund.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}