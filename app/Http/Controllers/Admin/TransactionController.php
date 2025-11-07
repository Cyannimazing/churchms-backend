<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionTransaction;
use App\Models\ChurchSubscription;
use App\Models\PaymentSession;
use App\Services\PayMongoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    /**
     * List all subscription transactions
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search');
        
        $query = SubscriptionTransaction::with(['user.profile', 'newPlan', 'oldPlan', 'paymentSession'])
            ->orderBy('TransactionDate', 'desc');
        
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('receipt_code', 'LIKE', "%{$search}%")
                  ->orWhere('paymongo_session_id', 'LIKE', "%{$search}%")
                  ->orWhere('SubTransactionID', $search)
                  ->orWhereHas('user', function($userQuery) use ($search) {
                      $userQuery->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%");
                  });
            });
        }
        
        $transactions = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $transactions
        ]);
    }

    /**
     * Get single transaction details
     */
    public function show($id)
    {
        $transaction = SubscriptionTransaction::with(['user.profile', 'newPlan', 'oldPlan', 'paymentSession'])
            ->where('SubTransactionID', $id)
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }

        // Get associated subscription
        $subscription = ChurchSubscription::where('UserID', $transaction->user_id)
            ->where('PlanID', $transaction->NewPlanID)
            ->orderBy('created_at', 'desc')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'transaction' => $transaction,
                'subscription' => $subscription,
            ]
        ]);
    }

    /**
     * Process refund for a transaction
     * Only allow refunds for pending subscriptions
     */
    public function refund(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500'
        ]);

        DB::beginTransaction();

        try {
            $transaction = SubscriptionTransaction::with(['user.profile', 'newPlan', 'paymentSession'])
                ->where('SubTransactionID', $id)
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found'
                ], 404);
            }

            // Check if subscription is still pending
            $subscription = ChurchSubscription::where('UserID', $transaction->user_id)
                ->where('PlanID', $transaction->NewPlanID)
                ->where('Status', 'Pending')
                ->first();

            if (!$subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund not allowed. Subscription is already active or not found.'
                ], 400);
            }

            // Get PayMongo payment ID
            $paymongoService = new PayMongoService();
            $paymentIdResult = $paymongoService->getPaymentIdFromSession($transaction->paymongo_session_id);

            if (!$paymentIdResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to retrieve payment information for refund'
                ], 400);
            }

            // Process refund via PayMongo
            $refundReason = $validated['reason'] ?? 'Subscription cancellation - Pending status';
            $refundResult = $paymongoService->createRefund(
                $paymentIdResult['payment_id'],
                $transaction->AmountPaid,
                $refundReason
            );

            if (!$refundResult['success']) {
                throw new \Exception($refundResult['error'] ?? 'Refund failed');
            }

            // Update transaction notes
            $transaction->update([
                'Notes' => $transaction->Notes . ' | REFUNDED: ' . now()->format('Y-m-d H:i:s') . ' - ' . $refundReason
            ]);

            // Delete the pending subscription
            $subscription->delete();

            // Update payment session status
            PaymentSession::where('paymongo_session_id', $transaction->paymongo_session_id)
                ->update(['status' => 'refunded']);

            DB::commit();

            Log::info('Refund processed successfully', [
                'transaction_id' => $transaction->SubTransactionID,
                'amount' => $transaction->AmountPaid,
                'refund_id' => $refundResult['data']['id'] ?? null
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Refund processed successfully',
                'data' => [
                    'transaction' => $transaction->fresh(),
                    'refund' => $refundResult['data']
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Refund processing failed', [
                'transaction_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Refund processing failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Search transactions by reference number
     */
    public function searchByReference(Request $request)
    {
        $validated = $request->validate([
            'reference' => 'required|string|max:50'
        ]);

        $transaction = SubscriptionTransaction::with(['user.profile', 'newPlan', 'oldPlan', 'paymentSession'])
            ->where('receipt_code', $validated['reference'])
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'No transaction found with that reference number'
            ], 404);
        }

        // Get associated subscription
        $subscription = ChurchSubscription::where('UserID', $transaction->user_id)
            ->where('PlanID', $transaction->NewPlanID)
            ->orderBy('created_at', 'desc')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'transaction' => $transaction,
                'subscription' => $subscription,
            ]
        ]);
    }
}
