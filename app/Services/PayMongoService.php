<?php

namespace App\Services;

use App\Models\ChurchPaymentConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class PayMongoService
{
    protected $secretKey;
    protected $publicKey;
    protected $churchId;
    protected $paymentConfig;
    protected $baseUrl;

    public function __construct($churchId = null)
    {
        $this->baseUrl = 'https://api.paymongo.com/v1';
        
        if ($churchId) {
            $this->churchId = $churchId;
            $this->loadChurchPaymentConfig();
        } else {
            // Fallback to config-based keys (for backward compatibility)
            $this->secretKey = config('services.paymongo.secret_key');
            $this->publicKey = config('services.paymongo.public_key');
        }
    }
    
    protected function loadChurchPaymentConfig()
    {
        Log::info('Loading PayMongo config for church', ['church_id' => $this->churchId]);
        
        $this->paymentConfig = ChurchPaymentConfig::where('church_id', $this->churchId)
            ->where('provider', 'paymongo')
            ->where('is_active', true)
            ->first();

        if (!$this->paymentConfig) {
            Log::error('No active PayMongo config found for church', ['church_id' => $this->churchId]);
            throw new Exception('No PayMongo configuration found for this church');
        }
        
        if (!$this->paymentConfig->isComplete()) {
            Log::error('Incomplete PayMongo config for church', [
                'church_id' => $this->churchId,
                'has_public_key' => !empty($this->paymentConfig->public_key),
                'has_secret_key' => !empty($this->paymentConfig->secret_key)
            ]);
            throw new Exception('PayMongo configuration is incomplete for this church');
        }
        
        $this->secretKey = $this->paymentConfig->secret_key;
        $this->publicKey = $this->paymentConfig->public_key;
        
        Log::info('PayMongo config loaded successfully', [
            'church_id' => $this->churchId,
            'public_key_prefix' => substr($this->publicKey, 0, 10) . '...',
            'secret_key_prefix' => substr($this->secretKey, 0, 10) . '...'
        ]);
    }
    
    public function isConfigured()
    {
        return !empty($this->secretKey) && !empty($this->publicKey);
    }
    
    public function getPublicKey()
    {
        return $this->publicKey;
    }

    /**
     * Create a checkout session for multiple payment methods
     */
    public function createCheckoutSession($amount, $description, $successUrl, $cancelUrl, $paymentMethods = ['gcash'], $metadata = [])
    {
        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->baseUrl . '/checkout_sessions', [
                    'data' => [
                        'attributes' => array_filter((function() use ($amount, $description, $paymentMethods, $successUrl, $cancelUrl, $metadata) {
                            $ref = $metadata['reference_number'] ?? ($metadata['receipt_code'] ?? null);
                            $lineItemName = $ref ? ($description . ' (Ref: ' . $ref . ')') : $description;
                            return [
                                'send_email_receipt' => true,
                                'show_description' => true,
                                'show_line_items' => true,
                                'description' => $description,
                                'payment_method_types' => $paymentMethods,
                                'success_url' => $successUrl,
                                'cancel_url' => $cancelUrl,
                                // If provided, this shows up as "Reference Number" in PayMongo checkout/email
                                'reference_number' => $ref,
                                'metadata' => $metadata,
                                'line_items' => [
                                    [
                                        'currency' => 'PHP',
                                        'amount' => $amount * 100, // Convert to centavos
                                        'description' => $lineItemName,
                                        'name' => $lineItemName,
                                        'quantity' => 1,
                                    ]
                                ],
                            ];
                        })(), function($v) { return $v !== null; })
                    ]
                ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('PayMongo checkout session created', ['session_id' => $data['data']['id']]);
                return [
                    'success' => true,
                    'data' => $data['data']
                ];
            }

            Log::error('PayMongo checkout creation failed', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create payment session',
                'details' => $response->json()
            ];

        } catch (Exception $e) {
            Log::error('PayMongo service error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Payment service unavailable',
                'details' => $e->getMessage()
            ];
        }
    }

    /**
     * Create a GCash checkout session (backward compatibility)
     */
    public function createGCashCheckout($amount, $description, $successUrl, $cancelUrl, $metadata = [])
    {
        return $this->createCheckoutSession($amount, $description, $successUrl, $cancelUrl, ['gcash'], $metadata);
    }

    /**
     * Create a Credit/Debit Card checkout session
     */
    public function createCardCheckout($amount, $description, $successUrl, $cancelUrl, $metadata = [])
    {
        return $this->createCheckoutSession($amount, $description, $successUrl, $cancelUrl, ['card'], $metadata);
    }

    /**
     * Create a PayPal checkout session
     */
    public function createPayPalCheckout($amount, $description, $successUrl, $cancelUrl, $metadata = [])
    {
        return $this->createCheckoutSession($amount, $description, $successUrl, $cancelUrl, ['paypal'], $metadata);
    }

    /**
     * Create a multi-payment method checkout session
     */
    public function createMultiPaymentCheckout($amount, $description, $successUrl, $cancelUrl, $metadata = [])
    {
        // Restrict to GCash and Card payments only (per requirement)
        return $this->createCheckoutSession($amount, $description, $successUrl, $cancelUrl, ['gcash', 'card'], $metadata);
    }

    /**
     * Retrieve checkout session details
     */
    public function getCheckoutSession($sessionId)
    {
        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->get($this->baseUrl . '/checkout_sessions/' . $sessionId);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()['data']
                ];
            }

            return [
                'success' => false,
                'error' => 'Session not found'
            ];

        } catch (Exception $e) {
            Log::error('Error retrieving checkout session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve session'
            ];
        }
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature($payload, $signature)
    {
        $computedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);
        return hash_equals($computedSignature, $signature);
    }

    /**
     * Process webhook payload
     */
    public function processWebhook($payload)
    {
        try {
            $data = json_decode($payload, true);
            
            if (!$data) {
                throw new Exception('Invalid webhook payload');
            }

            return [
                'success' => true,
                'event_type' => $data['data']['attributes']['type'] ?? null,
                'data' => $data['data']
            ];

        } catch (Exception $e) {
            Log::error('Webhook processing error', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);

            return [
                'success' => false,
                'error' => 'Invalid webhook data'
            ];
        }
    }

    /**
     * Create a refund for a payment
     */
    public function createRefund($paymentId, $amount, $reason = null)
    {
        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->baseUrl . '/refunds', [
                    'data' => [
                        'attributes' => array_filter([
                            'amount' => $amount * 100, // Convert to centavos
                            'payment_id' => $paymentId,
                            'reason' => $reason,
                            'notes' => $reason,
                        ], function($v) { return $v !== null; })
                    ]
                ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('PayMongo refund created', ['refund_id' => $data['data']['id']]);
                return [
                    'success' => true,
                    'data' => $data['data']
                ];
            }

            Log::error('PayMongo refund creation failed', [
                'status' => $response->status(),
                'response' => $response->json()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create refund',
                'details' => $response->json()
            ];

        } catch (Exception $e) {
            Log::error('PayMongo refund service error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Refund service unavailable',
                'details' => $e->getMessage()
            ];
        }
    }

    /**
     * Get payment ID from checkout session
     */
    public function getPaymentIdFromSession($sessionId)
    {
        try {
            $result = $this->getCheckoutSession($sessionId);
            
            if ($result['success']) {
                $sessionData = $result['data'];
                $paymentIntent = $sessionData['attributes']['payment_intent'] ?? null;
                
                if ($paymentIntent) {
                    $payments = $paymentIntent['attributes']['payments'] ?? [];
                    
                    if (!empty($payments)) {
                        return [
                            'success' => true,
                            'payment_id' => $payments[0]['id']
                        ];
                    }
                }
            }
            
            return [
                'success' => false,
                'error' => 'Payment ID not found in session'
            ];
            
        } catch (Exception $e) {
            Log::error('Error retrieving payment ID from session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => 'Failed to retrieve payment ID'
            ];
        }
    }
}
