<?php

namespace App\Http\Controllers;

use App\Models\Church;
use App\Models\ChurchPaymentConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class PaymentConfigController extends Controller
{
    /**
     * Get payment configuration for a church
     */
    public function show($churchId)
    {
        try {
            // Check if user owns the church
            $church = Church::where('ChurchID', $churchId)
                ->where('user_id', auth()->id())
                ->first();

            if (!$church) {
                return response()->json(['error' => 'Unauthorized or church not found'], 403);
            }

            $config = ChurchPaymentConfig::where('church_id', $churchId)
                ->where('provider', 'paymongo')
                ->first();

            if (!$config) {
                return response()->json([
                    'config' => null,
                    'has_config' => false
                ]);
            }

            return response()->json([
                'config' => [
                    'id' => $config->id,
                    'public_key' => $config->public_key,
                    'masked_secret_key' => $config->masked_secret_key,
                    'is_active' => $config->is_active,
                    'environment' => strpos($config->public_key, 'pk_test_') === 0 ? 'test' : 'live',
                    'created_at' => $config->created_at,
                    'updated_at' => $config->updated_at,
                ],
                'has_config' => true
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve payment configuration'], 500);
        }
    }

    /**
     * Store or update payment configuration
     */
    public function store(Request $request, $churchId)
    {
        try {
            \Log::info('PayMongo config save attempt', [
                'church_id' => $churchId,
                'user_id' => auth()->id(),
                'request_data' => $request->except(['secret_key']) // Don't log secret key
            ]);

            // Check if user owns the church - only church owner can manage payment settings
            $church = Church::where('ChurchID', $churchId)
                ->where('user_id', auth()->id())
                ->first();

            if (!$church) {
                \Log::warning('Unauthorized PayMongo config access', ['church_id' => $churchId, 'user_id' => auth()->id()]);
                return response()->json(['error' => 'Unauthorized or church not found'], 403);
            }

            // Validate input
            $validator = Validator::make($request->all(), [
                'public_key' => ['required', 'string'],
                'secret_key' => ['required', 'string'],
                'is_active' => 'boolean'
            ]);

            if ($validator->fails()) {
                \Log::info('PayMongo validation failed', ['errors' => $validator->errors()]);
                return response()->json([
                    'error' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Manual validation for PayMongo key formats
            $publicKey = $request->public_key;
            $secretKey = $request->secret_key;
            
            if (!preg_match('/^pk_(test|live)_[a-zA-Z0-9]+$/', $publicKey)) {
                return response()->json([
                    'error' => 'Validation failed',
                    'errors' => ['public_key' => ['Invalid PayMongo public key format. Must start with pk_test_ or pk_live_']]
                ], 422);
            }
            
            if (!preg_match('/^sk_(test|live)_[a-zA-Z0-9]+$/', $secretKey)) {
                return response()->json([
                    'error' => 'Validation failed', 
                    'errors' => ['secret_key' => ['Invalid PayMongo secret key format. Must start with sk_test_ or sk_live_']]
                ], 422);
            }

            // Check environment matching
            $publicEnv = strpos($publicKey, 'pk_test_') === 0 ? 'test' : 'live';
            $secretEnv = strpos($secretKey, 'sk_test_') === 0 ? 'test' : 'live';

            if ($publicEnv !== $secretEnv) {
                return response()->json([
                    'error' => 'Validation failed',
                    'errors' => ['environment' => ['Public and secret keys must be from the same environment (test or live)']]
                ], 422);
            }

            // Create or update configuration - keys are saved in database, not env
            $config = ChurchPaymentConfig::updateOrCreate(
                [
                    'church_id' => $churchId,
                    'provider' => 'paymongo'
                ],
                [
                    'public_key' => $request->public_key,
                    'secret_key' => $request->secret_key, // Will be encrypted by model mutator
                    'is_active' => $request->boolean('is_active', true),
                ]
            );

            return response()->json([
                'message' => 'Payment configuration saved successfully',
                'config' => [
                    'id' => $config->id,
                    'public_key' => $config->public_key,
                    'masked_secret_key' => $config->masked_secret_key,
                    'is_active' => $config->is_active,
                    'environment' => strpos($config->public_key, 'pk_test_') === 0 ? 'test' : 'live',
                    'created_at' => $config->created_at,
                    'updated_at' => $config->updated_at,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Payment config save error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to save payment configuration'], 500);
        }
    }

    /**
     * Update configuration status
     */
    public function updateStatus(Request $request, $churchId)
    {
        try {
            // Check if user owns the church
            $church = Church::where('ChurchID', $churchId)
                ->where('user_id', auth()->id())
                ->first();

            if (!$church) {
                return response()->json(['error' => 'Unauthorized or church not found'], 403);
            }

            $validator = Validator::make($request->all(), [
                'is_active' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $config = ChurchPaymentConfig::where('church_id', $churchId)
                ->where('provider', 'paymongo')
                ->first();

            if (!$config) {
                return response()->json(['error' => 'Payment configuration not found'], 404);
            }

            $config->is_active = $request->boolean('is_active');
            $config->save();

            return response()->json([
                'message' => 'Payment configuration status updated successfully',
                'is_active' => $config->is_active
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update payment configuration status'], 500);
        }
    }

    /**
     * Delete payment configuration
     */
    public function destroy($churchId)
    {
        try {
            // Check if user owns the church
            $church = Church::where('ChurchID', $churchId)
                ->where('user_id', auth()->id())
                ->first();

            if (!$church) {
                return response()->json(['error' => 'Unauthorized or church not found'], 403);
            }

            $config = ChurchPaymentConfig::where('church_id', $churchId)
                ->where('provider', 'paymongo')
                ->first();

            if (!$config) {
                return response()->json(['error' => 'Payment configuration not found'], 404);
            }

            $config->delete();

            return response()->json([
                'message' => 'Payment configuration deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete payment configuration'], 500);
        }
    }
}