<?php

namespace App\Http\Controllers;

use App\Models\ChurchSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Models\PaymentSession;
use App\Services\PayMongoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ChurchSubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $userId = Auth::id();
        $activeSubscription = ChurchSubscription::where('UserID', $userId)
            ->where('Status', 'Active')
            ->with('plan')
            ->first();
        $pendingSubscription = ChurchSubscription::where('UserID', $userId)
            ->where('Status', 'Pending')
            ->with('plan')
            ->first();

        $response = [
            'active' => $activeSubscription,
            'pending' => $pendingSubscription,
        ];

        
        return response()->json($response);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'exists:SubscriptionPlan,PlanID'],
            'payment_method' => ['required', 'string', 'max:50'],
        ]);

        $userId = Auth::id();
        $newPlan = SubscriptionPlan::findOrFail($validated['plan_id']);

        $pendingExists = ChurchSubscription::where('UserID', $userId)
            ->where('Status', 'Pending')
            ->exists();

        if ($pendingExists) {
            return response()->json(['error' => 'A pending subscription already exists'], 400);
        }

        $activeSubscription = ChurchSubscription::where('UserID', $userId)
            ->where('Status', 'Active')
            ->first();

        $startDate = $activeSubscription 
            ? \Carbon\Carbon::parse($activeSubscription->EndDate)
            : now();

        $duration = max(1, $newPlan->DurationInMonths);
        $endDate = $startDate->copy()->addMonths($duration);

        DB::beginTransaction();

        try {
            $churchSubscription = ChurchSubscription::create([
                'UserID' => $userId,
                'PlanID' => $validated['plan_id'],
                'StartDate' => $startDate,
                'EndDate' => $endDate,
                'Status' => 'Pending',
            ]);

            SubscriptionTransaction::create([
                'user_id' => $userId,
                'OldPlanID' => $activeSubscription?->PlanID,
                'NewPlanID' => $validated['plan_id'],
                'PaymentMethod' => $validated['payment_method'],
                'AmountPaid' => $newPlan->Price,
                'TransactionDate' => now(),
                'Notes' => 'Subscription change queued to start on ' . $startDate->toDateString(),
            ]);

            DB::commit();

            $churchSubscription->load('plan');

            return response()->json($churchSubscription, 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'An unexpected error occurred', 'message' => $e->getMessage()], 500);
        }
    }

    public function cancelPending(Request $request)
    {
        return response()->json(['error' => 'Subscription cancellation is disabled'], 405);

        DB::beginTransaction();
        try {
            // Find the latest related paid payment session for this user and plan
            $paymentSession = PaymentSession::where('user_id', $userId)
                ->where('plan_id', $pendingSubscription->PlanID)
                ->where('status', 'paid')
                ->orderBy('created_at', 'desc')
                ->first();

            $refundProcessed = false;
            $refund = null;

            if ($paymentSession) {
                // Find matching transaction
                $transaction = SubscriptionTransaction::where('user_id', $userId)
                    ->where('NewPlanID', $pendingSubscription->PlanID)
                    ->where('Notes', 'like', '%Pending start%')
                    ->where('Notes', 'not like', '%REFUNDED%')
                    ->orderBy('TransactionDate', 'desc')
                    ->first();

                if ($transaction) {
                    $paymongo = new PayMongoService();
                    $paymentIdResult = $paymongo->getPaymentIdFromSession($paymentSession->paymongo_session_id);

                    Log::info('Payment ID result', ['result' => $paymentIdResult]);

                    if ($paymentIdResult['success']) {
                        $refundResult = $paymongo->createRefund(
                            $paymentIdResult['payment_id'],
                            $transaction->AmountPaid,
                            'User cancelled pending subscription'
                        );

                        Log::info('Refund result', [
                            'success' => $refundResult['success'],
                            'data' => $refundResult['data'] ?? null,
                            'error' => $refundResult['error'] ?? null,
                            'details' => $refundResult['details'] ?? null
                        ]);

                        if ($refundResult['success']) {
                            $refundProcessed = true;
                            $refund = $refundResult['data'];

                            // Update records
                            $transaction->update([
                                'Notes' => ($transaction->Notes . ' | REFUNDED: ' . now()->format('Y-m-d H:i:s') . ' - User cancelled')
                            ]);

                            $paymentSession->update(['status' => 'refunded']);
                        } else {
                            Log::warning('Refund failed but continuing with cancellation', [
                                'error' => $refundResult['error'] ?? 'Unknown error',
                                'transaction_id' => $transaction->SubTransactionID
                            ]);
                        }
                    } else {
                        Log::warning('Could not get payment ID from session', [
                            'session_id' => $paymentSession->paymongo_session_id,
                            'error' => $paymentIdResult['error'] ?? 'Unknown error'
                        ]);
                    }
                }
            }

            // Delete the pending subscription regardless (policy: refund only if paid)
            $pendingSubscription->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'refunded' => $refundProcessed,
                'refund' => $refund
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel subscription',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create GCash payment session
     */
    public function createGCashPayment(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'exists:SubscriptionPlan,PlanID'],
        ]);

        $userId = Auth::id();
        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);

        // Check if user already has pending subscription
        $pendingExists = ChurchSubscription::where('UserID', $userId)
            ->where('Status', 'Pending')
            ->exists();

        if ($pendingExists) {
            return response()->json(['error' => 'A pending subscription already exists'], 400);
        }

        // Check for existing pending payment session
        $existingSession = PaymentSession::where('user_id', $userId)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if ($existingSession) {
            return response()->json([
                'success' => true,
                'checkout_url' => $existingSession->checkout_url,
                'session_id' => $existingSession->paymongo_session_id
            ]);
        }

        $paymongoService = new PayMongoService();
        
        $successUrl = url('/payment/success?session_id={CHECKOUT_SESSION_ID}');
        $cancelUrl = url('/payment/cancel?session_id={CHECKOUT_SESSION_ID}');
        
        // Generate unique reference number
        $referenceNumber = 'SUB-' . strtoupper(substr(uniqid(), -8));
        
        $metadata = [
            'user_id' => $userId,
            'plan_id' => $plan->PlanID,
            'plan_name' => $plan->PlanName,
            'receipt_code' => $referenceNumber,
        ];

        $result = $paymongoService->createGCashCheckout(
            $plan->Price,
            "[Ref: {$referenceNumber}] Subscription to {$plan->PlanName} Plan",
            $successUrl,
            $cancelUrl,
            $metadata
        );

        if (!$result['success']) {
            return response()->json([
                'error' => 'Failed to create payment session',
                'details' => $result['error']
            ], 500);
        }

        $checkoutData = $result['data'];

        // Determine expiration (PayMongo may not return expires_at)
        $expiresAtRaw = $checkoutData['attributes']['expires_at'] ?? null;
        $expiresAt = $expiresAtRaw ? Carbon::parse($expiresAtRaw) : now()->addMinutes(30);
        
        // Store payment session with receipt code in metadata
        $paymentSession = PaymentSession::create([
            'user_id' => $userId,
            'plan_id' => $plan->PlanID,
            'paymongo_session_id' => $checkoutData['id'],
            'payment_method' => 'gcash',
            'amount' => $plan->Price,
            'currency' => 'PHP',
            'status' => 'pending',
            'checkout_url' => $checkoutData['attributes']['checkout_url'] ?? null,
            'metadata' => $metadata,
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'success' => true,
            'checkout_url' => $checkoutData['attributes']['checkout_url'],
            'session_id' => $checkoutData['id']
        ]);
    }

    /**
     * Create payment session for multiple payment methods
     */
    public function createPayment(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'exists:SubscriptionPlan,PlanID'],
        ]);

        $userId = Auth::id();
        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);

        // Check if user already has pending subscription
        $pendingExists = ChurchSubscription::where('UserID', $userId)
            ->where('Status', 'Pending')
            ->exists();

        if ($pendingExists) {
            return response()->json(['error' => 'A pending subscription already exists'], 400);
        }

        // Check for existing pending payment session
        $existingSession = PaymentSession::where('user_id', $userId)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if ($existingSession) {
            return response()->json([
                'success' => true,
                'checkout_url' => $existingSession->checkout_url,
                'session_id' => $existingSession->paymongo_session_id
            ]);
        }

        $paymongoService = new PayMongoService();
        
        $successUrl = url('/payment/success?session_id={CHECKOUT_SESSION_ID}');
        $cancelUrl = url('/payment/cancel?session_id={CHECKOUT_SESSION_ID}');
        
        // Generate unique reference number
        $referenceNumber = 'SUB-' . strtoupper(substr(uniqid(), -8));
        
        $metadata = [
            'user_id' => $userId,
            'plan_id' => $plan->PlanID,
            'plan_name' => $plan->PlanName,
            'receipt_code' => $referenceNumber,
        ];

        // Always create multi-payment checkout (GCash and Card only)
        $result = $paymongoService->createMultiPaymentCheckout(
            $plan->Price,
            "[Ref: {$referenceNumber}] Subscription to {$plan->PlanName} Plan",
            $successUrl,
            $cancelUrl,
            $metadata
        );

        if (!$result['success']) {
            return response()->json([
                'error' => 'Failed to create payment session',
                'details' => $result['error']
            ], 500);
        }

        $checkoutData = $result['data'];

        // Determine expiration (PayMongo may not return expires_at)
        $expiresAtRaw = $checkoutData['attributes']['expires_at'] ?? null;
        $expiresAt = $expiresAtRaw ? Carbon::parse($expiresAtRaw) : now()->addMinutes(30);
        
        // Store payment session
        $paymentSession = PaymentSession::create([
            'user_id' => $userId,
            'plan_id' => $plan->PlanID,
            'paymongo_session_id' => $checkoutData['id'],
            'payment_method' => 'multi', // Multiple payment methods available
            'amount' => $plan->Price,
            'currency' => 'PHP',
            'status' => 'pending',
            'checkout_url' => $checkoutData['attributes']['checkout_url'] ?? null,
            'metadata' => $metadata,
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'success' => true,
            'checkout_url' => $checkoutData['attributes']['checkout_url'],
            'session_id' => $checkoutData['id']
        ]);
    }

    /**
     * Handle successful payment for both subscriptions and appointments
     */
    public function handlePaymentSuccess(Request $request)
    {
        $sessionId = $request->query('session_id');
        
        Log::info('Payment success handler called', [
            'session_id' => $sessionId,
            'full_url' => $request->fullUrl(),
            'all_params' => $request->all()
        ]);
        
        if (!$sessionId) {
            Log::warning('No session_id provided');
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard?error=missing_session');
        }

        // Handle case where PayMongo doesn't replace the placeholder
        if ($sessionId === '{CHECKOUT_SESSION_ID}') {
            Log::warning('PayMongo did not replace session_id placeholder, using latest session');
            
            // Find most recent pending PaymentSession (EXACTLY like subscriptions)
            $paymentSession = PaymentSession::where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->first();
                
            if (!$paymentSession) {
                $paymentSession = PaymentSession::where('status', 'paid')
                    ->where('updated_at', '>', now()->subMinutes(10))
                    ->orderBy('updated_at', 'desc')
                    ->first();
            }
            
            if ($paymentSession) {
                $metadata = $paymentSession->metadata ?? [];
                if (($metadata['type'] ?? null) === 'appointment_payment') {
                    return $this->activateAppointment($paymentSession);
                } else {
                    $transaction = $this->activateSubscription($paymentSession);
                    return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/payment/success?transaction_id=' . $transaction->SubTransactionID . '&session_id=' . $paymentSession->paymongo_session_id);
                }
            }
            
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard?success=payment_completed');
        }

        // First, get the session from PayMongo to check metadata type
        $paymongoService = new PayMongoService();
        $result = $paymongoService->getCheckoutSession($sessionId);
        
        if (!$result['success']) {
            Log::warning('PayMongo verification failed', ['session_id' => $sessionId]);
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard?error=payment_verification_failed');
        }

        $sessionData = $result['data'];
        $metadata = $sessionData['attributes']['metadata'] ?? [];
        $paymentType = $metadata['type'] ?? 'subscription'; // Default to subscription for backward compatibility
        
        Log::info('Payment type detected', ['type' => $paymentType, 'session_id' => $sessionId]);
        
        // Route to appropriate handler based on payment type
        if ($paymentType === 'appointment_payment') {
            return $this->handleAppointmentPayment($sessionId, $sessionData);
        } else {
            return $this->handleSubscriptionPayment($sessionId, $sessionData);
        }
    }
    
    /**
     * Handle appointment payment success
     */
    private function handleAppointmentPayment($sessionId, $sessionData)
    {
        $appointmentController = new \App\Http\Controllers\AppointmentController();
        return $appointmentController->processAppointmentPaymentSuccess($sessionId, $sessionData);
    }
    
    /**
     * Handle subscription payment success
     */
    private function handleSubscriptionPayment($sessionId, $sessionData)
    {
        // Find payment session
        $paymentSession = PaymentSession::where('paymongo_session_id', $sessionId)->first();
        
        if (!$paymentSession) {
            Log::error('Payment session not found for subscription', ['session_id' => $sessionId]);
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/subscriptions?error=session_not_found');
        }
        
        // If payment session is already processed, redirect to success immediately
        if ($paymentSession->status === 'paid') {
            $transaction = SubscriptionTransaction::where('user_id', $paymentSession->user_id)
                ->where('Notes', 'like', '%Session ID: ' . $paymentSession->paymongo_session_id . '%')
                ->orderBy('TransactionDate', 'desc')
                ->first();
                
            if ($transaction) {
                return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/payment/success?transaction_id=' . $transaction->SubTransactionID . '&session_id=' . $paymentSession->paymongo_session_id);
            }
        }

        $paymentStatus = $sessionData['attributes']['payment_intent']['attributes']['status'] ?? 'succeeded';
        
        Log::info('Subscription payment status', ['status' => $paymentStatus, 'session_id' => $sessionId]);
        
        $transaction = $this->activateSubscription($paymentSession);
        return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/payment/success?transaction_id=' . $transaction->SubTransactionID . '&session_id=' . $paymentSession->paymongo_session_id);
    }

    /**
     * Handle cancelled payment
     */
    public function handlePaymentCancel(Request $request)
    {
        $sessionId = $request->query('session_id');
        
        if ($sessionId) {
            PaymentSession::where('paymongo_session_id', $sessionId)
                ->update(['status' => 'cancelled']);
        }

        return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/subscriptions?info=payment_cancelled');
    }

    /**
     * Handle PayMongo webhook
     */
    public function handlePayMongoWebhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('paymongo-signature');
        
        $paymongoService = new PayMongoService();
        
        // Verify webhook signature
        if (!$paymongoService->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Invalid PayMongo webhook signature');
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $result = $paymongoService->processWebhook($payload);
        
        if (!$result['success']) {
            return response()->json(['error' => 'Invalid webhook data'], 400);
        }

        $eventType = $result['event_type'];
        $eventData = $result['data'];

        Log::info('PayMongo webhook received', [
            'event_type' => $eventType,
            'event_id' => $eventData['id'] ?? null
        ]);

        // Handle checkout session completed event
        if ($eventType === 'checkout_session.payment.paid') {
            $sessionId = $eventData['attributes']['checkout_session']['id'] ?? null;
            
            if ($sessionId) {
                $paymentSession = PaymentSession::where('paymongo_session_id', $sessionId)->first();
                
                if ($paymentSession && $paymentSession->isPending()) {
                    $this->activateSubscription($paymentSession);
                }
            }
        }

        return response()->json(['success' => true]);
    }
    
    /**
     * Activate appointment after successful payment (create ChurchTransaction)
     */
    private function activateAppointment(PaymentSession $paymentSession)
    {
        $metadata = $paymentSession->metadata ?? [];
        
        // Update payment session status  
        $paymentSession->update(['status' => 'paid']);
        
        // Create ChurchTransaction record
        $transaction = \App\Models\ChurchTransaction::create([
            'user_id' => $paymentSession->user_id,
            'church_id' => $metadata['church_id'] ?? 1,
            'appointment_id' => null,
            'paymongo_session_id' => $paymentSession->paymongo_session_id,
            'payment_method' => $paymentSession->payment_method === 'multi' ? 'gcash' : $paymentSession->payment_method,
            'amount_paid' => $paymentSession->amount,
            'currency' => 'PHP',
            'transaction_type' => 'appointment_payment',
            'transaction_date' => now(),
            'notes' => 'Appointment payment completed successfully',
            'metadata' => [
                'church_name' => $metadata['church_name'] ?? 'Church',
                'service_name' => $metadata['service_name'] ?? 'Service',
                'appointment_date' => $metadata['appointment_date'] ?? null,
                'payment_status' => 'completed'
            ]
        ]);
        
        Log::info('ChurchTransaction created from PaymentSession', [
            'transaction_id' => $transaction->ChurchTransactionID,
            'payment_session_id' => $paymentSession->id
        ]);
        
        return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/payment/success?transaction_id=' . $transaction->ChurchTransactionID . '&type=appointment&session_id=' . $paymentSession->paymongo_session_id);
    }

    /**
     * Activate subscription after successful payment
     */
    private function activateSubscription(PaymentSession $paymentSession)
    {
        DB::beginTransaction();
        
        try {
            $userId = $paymentSession->user_id;
            $plan = $paymentSession->plan;
            
            // Check if transaction already exists for this payment session to prevent duplicates
            $existingTransaction = SubscriptionTransaction::where('paymongo_session_id', $paymentSession->paymongo_session_id)->first();
            
            if ($existingTransaction) {
                DB::commit();
                Log::info('Subscription already activated for this payment session', [
                    'payment_session_id' => $paymentSession->paymongo_session_id,
                    'transaction_id' => $existingTransaction->SubTransactionID
                ]);
                return $existingTransaction;
            }
            
            // Get actual payment method from PayMongo session
            $actualPaymentMethod = $this->getActualPaymentMethod($paymentSession->paymongo_session_id);
            
            // Update payment session status and actual payment method
            $paymentSession->update([
                'status' => 'paid',
                'payment_method' => $actualPaymentMethod
            ]);
            
            // Check for existing active subscription
            $activeSubscription = ChurchSubscription::where('UserID', $userId)
                ->where('Status', 'Active')
                ->first();
            
            // Check for existing pending subscription
            $existingPending = ChurchSubscription::where('UserID', $userId)
                ->where('Status', 'Pending')
                ->first();
            
            // If there's already a pending subscription, don't create another one
            if ($existingPending) {
                DB::commit();
                Log::warning('Pending subscription already exists, skipping creation', [
                    'user_id' => $userId,
                    'existing_subscription_id' => $existingPending->SubscriptionID,
                    'payment_session_id' => $paymentSession->paymongo_session_id
                ]);
                
                // Still create the transaction record if it doesn't exist
                $referenceNumber = $paymentSession->metadata['receipt_code'] ?? 'SUB-' . strtoupper(substr(uniqid(), -8));
                
                $transaction = SubscriptionTransaction::create([
                    'user_id' => $userId,
                    'OldPlanID' => $activeSubscription?->PlanID,
                    'NewPlanID' => $plan->PlanID,
                    'PaymentMethod' => $actualPaymentMethod,
                    'AmountPaid' => $plan->Price,
                    'TransactionDate' => now(),
                    'receipt_code' => $referenceNumber,
                    'paymongo_session_id' => $paymentSession->paymongo_session_id,
                    'Notes' => 'Duplicate payment attempt - ' . $actualPaymentMethod . ' payment via PayMongo - Reference: ' . $referenceNumber,
                ]);
                
                return $transaction;
            }
            
            // Determine subscription status and dates
            $hasActiveSubscription = $activeSubscription !== null;
            $subscriptionStatus = $hasActiveSubscription ? 'Pending' : 'Active';
            $startDate = $hasActiveSubscription 
                ? Carbon::parse($activeSubscription->EndDate)
                : now();
            
            $duration = max(1, $plan->DurationInMonths);
            $endDate = $startDate->copy()->addMonths($duration);
            
            // Create new subscription
            $churchSubscription = ChurchSubscription::create([
                'UserID' => $userId,
                'PlanID' => $plan->PlanID,
                'StartDate' => $startDate,
                'EndDate' => $endDate,
                'Status' => $subscriptionStatus,
            ]);
            
            // Check if transaction already exists for this payment session
            $transaction = SubscriptionTransaction::where('paymongo_session_id', $paymentSession->paymongo_session_id)->first();
            
            if (!$transaction) {
                // Get reference number from payment session metadata
                $referenceNumber = $paymentSession->metadata['receipt_code'] ?? 'SUB-' . strtoupper(substr(uniqid(), -8));
                
                // Create transaction record
                $transaction = SubscriptionTransaction::create([
                    'user_id' => $userId,
                    'OldPlanID' => $activeSubscription?->PlanID,
                    'NewPlanID' => $plan->PlanID,
                    'PaymentMethod' => $actualPaymentMethod,
                    'AmountPaid' => $plan->Price,
                    'TransactionDate' => now(),
                    'receipt_code' => $referenceNumber,
                    'paymongo_session_id' => $paymentSession->paymongo_session_id,
                    'Notes' => $hasActiveSubscription 
                        ? $actualPaymentMethod . ' payment via PayMongo - Pending start on ' . $startDate->toDateString() . ' - Reference: ' . $referenceNumber
                        : $actualPaymentMethod . ' payment via PayMongo - Reference: ' . $referenceNumber,
                ]);
            }
            
            // Don't deactivate old subscription if new one is pending
            // The old subscription will remain active until the new one starts
            
            DB::commit();
            
            Log::info('Subscription created successfully', [
                'user_id' => $userId,
                'subscription_id' => $churchSubscription->SubscriptionID,
                'status' => $subscriptionStatus,
                'start_date' => $startDate->toDateString(),
                'has_active_subscription' => $hasActiveSubscription,
                'payment_session_id' => $paymentSession->id
            ]);
            
            return $transaction;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to activate subscription', [
                'error' => $e->getMessage(),
                'payment_session_id' => $paymentSession->id,
                'user_id' => $paymentSession->user_id
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Get actual payment method from PayMongo session
     */
    private function getActualPaymentMethod($sessionId)
    {
        try {
            $paymongoService = new PayMongoService();
            $result = $paymongoService->getCheckoutSession($sessionId);
            
            if ($result['success']) {
                $sessionData = $result['data'];
                $paymentIntent = $sessionData['attributes']['payment_intent'] ?? null;
                
                if ($paymentIntent) {
                    $payments = $paymentIntent['attributes']['payments'] ?? [];
                    
                    if (!empty($payments)) {
                        $payment = $payments[0];
                        $paymentType = $payment['attributes']['source']['type'] ?? null;
                        
                        // Map PayMongo payment types to our system
                        $paymentMethodMap = [
                            'gcash' => 'GCash',
                            'card' => 'Card',
                            'grab_pay' => 'GrabPay',
                            'paymaya' => 'PayMaya',
                        ];
                        
                        return $paymentMethodMap[$paymentType] ?? ucfirst($paymentType);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Could not retrieve actual payment method', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
        }
        
        // Fallback to default
        return 'Online Payment';
    }

    /**
     * Get transaction details
     */
    public function getTransactionDetails(Request $request, $transactionId)
    {
        try {
            $transaction = SubscriptionTransaction::with(['newPlan', 'user'])
                ->where('SubTransactionID', $transactionId)
                ->where('user_id', Auth::id())
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found'
                ], 404);
            }

            // Find the related payment session
            $sessionId = $request->query('session_id');
            $paymentSession = null;
            
            if ($sessionId) {
                $paymentSession = PaymentSession::where('paymongo_session_id', $sessionId)
                    ->where('user_id', Auth::id())
                    ->first();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'transaction' => $transaction,
                    'payment_session' => $paymentSession,
                    'receipt_number' => (string) $transaction->SubTransactionID,
                    'receipt_code' => $transaction->receipt_code,
                    'formatted_date' => $transaction->TransactionDate->format('F j, Y g:i A'),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch transaction details', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transaction details'
            ], 500);
        }
    }


}
