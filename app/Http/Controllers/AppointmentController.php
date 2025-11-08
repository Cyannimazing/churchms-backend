<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\Church;
use App\Models\User;
use App\Models\Appointment;
use App\Models\ServiceSchedule;
use App\Models\ServiceScheduleTime;
use App\Models\SacramentService;
use App\Models\PaymentSession;
use App\Models\ChurchTransaction;
use App\Models\ChurchConvenienceFee;
use App\Models\Notification;
use App\Services\PayMongoService;
use App\Events\AppointmentCreated;
use App\Events\NotificationCreated;
use App\Events\NotificationRead;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

class AppointmentController extends Controller
{
    /**
     * Submit a simplified sacrament application (no form data required)
     */
    public function store(Request $request)
    {
        try {
            // Basic validation
            $validator = Validator::make($request->all(), [
                'church_id' => 'required|integer|exists:Church,ChurchID',
                'service_id' => 'required|integer|exists:sacrament_service,ServiceID',
                'schedule_id' => 'required|integer|exists:service_schedules,ScheduleID',
                'schedule_time_id' => 'required|integer|exists:schedule_times,ScheduleTimeID',
                'selected_date' => 'required|date|after_or_equal:today',
                'status' => 'sometimes|in:pending,accepted,rejected'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verify user is authenticated
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'error' => 'Authentication required.'
                ], 401);
            }

            // Verify church is active and public
            $church = Church::where('ChurchID', $request->church_id)
                          ->where('ChurchStatus', Church::STATUS_ACTIVE)
                          ->where('IsPublic', true)
                          ->first();

            if (!$church) {
                return response()->json([
                    'error' => 'Church not found or not available.'
                ], 404);
            }

            // Verify service belongs to church
            $service = SacramentService::where('ServiceID', $request->service_id)
                                     ->where('ChurchID', $request->church_id)
                                     ->first();

            if (!$service) {
                return response()->json([
                    'error' => 'Service not found or not available.'
                ], 404);
            }

            // Verify schedule belongs to service
            $schedule = ServiceSchedule::where('ScheduleID', $request->schedule_id)
                                     ->where('ServiceID', $request->service_id)
                                     ->first();

            if (!$schedule) {
                return response()->json([
                    'error' => 'Schedule not found.'
                ], 404);
            }

            // Verify schedule time belongs to schedule
            $scheduleTime = DB::table('schedule_times')
                ->where('ScheduleTimeID', $request->schedule_time_id)
                ->where('ScheduleID', $request->schedule_id)
                ->first();

            if (!$scheduleTime) {
                return response()->json([
                    'error' => 'Schedule time not found.'
                ], 404);
            }

            // Note: Removed duplicate application check to allow multiple appointments
            // for the same user at the same time/date (e.g., booking multiple children for baptism)
            // Slot availability is still enforced to prevent overbooking

            // Check slot availability before creating appointment
            $appointmentDate = $request->selected_date;
            $slotCapacity = $schedule->SlotCapacity;
            
            // Ensure date slot exists and check availability (needed for both free and paid services)
            $this->ensureDateSlotExists($request->schedule_time_id, $appointmentDate, $slotCapacity);
            
            $currentSlot = DB::table('schedule_time_date_slots')
                ->where('ScheduleTimeID', $request->schedule_time_id)
                ->where('SlotDate', $appointmentDate)
                ->first();
            
            if (!$currentSlot || $currentSlot->RemainingSlots <= 0) {
                Log::warning('Appointment blocked - no slots available', [
                    'user_id' => $user->id,
                    'schedule_time_id' => $request->schedule_time_id,
                    'appointment_date' => $appointmentDate,
                    'current_slot' => $currentSlot
                ]);
                return response()->json([
                    'error' => 'No slots available for the selected date and time.',
                    'debug_info' => [
                        'remaining_slots' => $currentSlot ? $currentSlot->RemainingSlots : 'No slot record',
                        'slot_capacity' => $slotCapacity
                    ]
                ], 409);
            }
            
            // Check if this service has any payable amount
            // Fee Logic: If isMultipleService, fee comes from sub_sacrament_services (variant)
            //           Otherwise, fee comes from sacrament_service (parent)
            $originalTotalAmount = 0;
            
            if ($service->isMultipleService && $schedule->SubSacramentServiceID) {
                // Service has variants - get fee from sub_sacrament_services
                $subSacramentService = DB::table('sub_sacrament_services')
                    ->where('SubSacramentServiceID', $schedule->SubSacramentServiceID)
                    ->first();
                    
                if ($subSacramentService && isset($subSacramentService->fee)) {
                    $originalTotalAmount = (float) $subSacramentService->fee;
                    Log::info('Using variant fee', [
                        'sub_service_id' => $schedule->SubSacramentServiceID,
                        'fee' => $subSacramentService->fee
                    ]);
                }
            } else {
                // Service has no variants - get fee from sacrament_service
                $originalTotalAmount = (float) ($service->fee ?? 0);
                Log::info('Using parent service fee', [
                    'service_id' => $service->ServiceID,
                    'fee' => $service->fee
                ]);
            }
            
            // Apply member discount if user is an approved member
            $totalAmount = $this->applyMemberDiscount($originalTotalAmount, $service, $user, $request->church_id);
            
            // If service is free (not Mass), require approved membership
            if ($totalAmount == 0 && !$service->isMass) {
                $membership = \App\Models\ChurchMember::where('user_id', $user->id)
                    ->where('church_id', $request->church_id)
                    ->where('status', 'approved')
                    ->first();
                    
                if (!$membership) {
                    return response()->json([
                        'error' => 'This is a free service and requires approved membership at this church. Please apply for membership first.'
                    ], 403);
                }
            }
            
            // If fees are required, create payment checkout session instead of appointment
            if ($totalAmount > 0) {
                Log::info('Payment required for appointment', [
                    'church_id' => $request->church_id,
                    'service_id' => $request->service_id,
                    'total_amount' => $totalAmount,
                    'original_amount' => $originalTotalAmount
                ]);
                
                // Check if church has payment configuration
                $paymentConfig = \App\Models\ChurchPaymentConfig::where('church_id', $request->church_id)
                    ->where('provider', 'paymongo')
                    ->first();
                
                if (!$paymentConfig) {
                    Log::error('No PayMongo config found for church', ['church_id' => $request->church_id]);
                    return response()->json([
                        'error' => 'Payment system is not configured for this church. Please contact the church administrator.',
                        'requires_setup' => true,
                        'debug_info' => 'No PayMongo configuration found'
                    ], 503);
                }
                
                if (!$paymentConfig->is_active) {
                    Log::error('PayMongo config is inactive for church', ['church_id' => $request->church_id]);
                    return response()->json([
                        'error' => 'Payment system is currently disabled for this church.',
                        'requires_setup' => true,
                        'debug_info' => 'PayMongo configuration is inactive'
                    ], 503);
                }
                
                if (!$paymentConfig->isComplete()) {
                    Log::error('Incomplete PayMongo config for church', [
                        'church_id' => $request->church_id,
                        'has_public_key' => !empty($paymentConfig->public_key),
                        'has_secret_key' => !empty($paymentConfig->secret_key)
                    ]);
                    return response()->json([
                        'error' => 'Payment system configuration is incomplete for this church.',
                        'requires_setup' => true,
                        'debug_info' => 'PayMongo keys are missing or invalid'
                    ], 503);
                }
                
                try {
                    // Initialize PayMongo service for this church
                    $paymongoService = new PayMongoService($request->church_id);
                    
                    if (!$paymongoService->isConfigured()) {
                        Log::error('PayMongo service not configured after initialization', ['church_id' => $request->church_id]);
                        return response()->json([
                            'error' => 'Payment system initialization failed.',
                            'requires_setup' => true,
                            'debug_info' => 'Service configuration failed'
                        ], 503);
                    }
                } catch (\Exception $e) {
                    Log::error('Error initializing PayMongo service', [
                        'church_id' => $request->church_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    return response()->json([
                        'error' => 'Failed to initialize payment system.',
                        'details' => $e->getMessage(),
                        'debug_info' => 'Service initialization exception'
                    ], 503);
                }
                
                // Format appointment date time
                $appointmentDateTime = \Carbon\Carbon::parse($request->selected_date)
                    ->setTimeFromTimeString($scheduleTime->StartTime)
                    ->format('Y-m-d H:i:s');
                
                // Generate unique reference number for this appointment payment
                $receiptCode = 'APT-' . strtoupper(substr(uniqid(), -8));
                
                // Prepare metadata for the checkout session
                $metadata = [
                    'user_id' => $user->id,
                    'church_id' => $request->church_id,
                    'service_id' => $request->service_id,
                    'schedule_id' => $request->schedule_id,
                    'schedule_time_id' => $request->schedule_time_id,
                    'appointment_date' => $appointmentDateTime,
                    'form_data' => null, // No form data for simple applications
                    'receipt_code' => $receiptCode,
                    'reference_number' => $receiptCode,
                    'type' => 'appointment_payment'
                ];
                
                // Build description with reference number for tracing
                $description = sprintf(
                    '[Ref: %s] %s - %s (Date: %s, Time: %s)',
                    $receiptCode,
                    $church->ChurchName,
                    $service->ServiceName,
                    $appointmentDate,
                    $scheduleTime->StartTime
                );
                
                // Create success and cancel URLs (dedicated appointment endpoints)
                $successUrl = url('/appointment-payment/success?session_id={CHECKOUT_SESSION_ID}&church_id=' . $request->church_id);
                $cancelUrl = url('/appointment-payment/cancel?session_id={CHECKOUT_SESSION_ID}&church_id=' . $request->church_id);
                
                // Create PayMongo checkout session with GCash and Card only
                $checkoutResult = $paymongoService->createCheckoutSession(
                    $totalAmount,
                    $description,
                    $successUrl,
                    $cancelUrl,
                    ['gcash', 'card'], // Only GCash and Card payments
                    $metadata
                );
                
                if (!$checkoutResult['success']) {
                    Log::error('Failed to create PayMongo checkout session', [
                        'church_id' => $request->church_id,
                        'service_id' => $request->service_id,
                        'user_id' => $user->id,
                        'error' => $checkoutResult['error'] ?? 'Unknown error'
                    ]);
                    
                    return response()->json([
                        'error' => 'Failed to create payment session. Please try again later.',
                        'details' => $checkoutResult['error']
                    ], 500);
                }
                
                $checkoutData = $checkoutResult['data'];
                
                // Store appointment payment session
                $expiresAtRaw = $checkoutData['attributes']['expires_at'] ?? null;
                $expiresAt = $expiresAtRaw ? \Carbon\Carbon::createFromTimestamp($expiresAtRaw) : now()->addMinutes(30);
                
                try {
                    ChurchTransaction::create([
                        'user_id' => $user->id,
                        'church_id' => $request->church_id,
                        'service_id' => $request->service_id,
                        'schedule_id' => $request->schedule_id,
                        'schedule_time_id' => $request->schedule_time_id,
                        'appointment_id' => null, // Will be set after payment success
                        'paymongo_session_id' => $checkoutData['id'],
                        'receipt_code' => $receiptCode,
                        'payment_method' => 'multi',
                        'amount_paid' => $totalAmount,
                        'currency' => 'PHP',
                        'transaction_type' => 'appointment_payment',
                        'status' => 'pending',
                        'checkout_url' => $checkoutData['attributes']['checkout_url'] ?? null,
                        'appointment_date' => $appointmentDateTime,
                        'transaction_date' => now(),
                        'notes' => sprintf('Pending payment for %s at %s', $service->ServiceName, $church->ChurchName),
                        'metadata' => [
                            'church_name' => $church->ChurchName,
                            'service_name' => $service->ServiceName,
                            'receipt_code' => $receiptCode,
                            'form_data' => null
                        ],
                        'expires_at' => $expiresAt
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to persist payment transaction', [
                        'error' => $e->getMessage()
                    ]);
                }
                
                Log::info('PayMongo checkout session created for appointment', [
                    'checkout_session_id' => $checkoutData['id'],
                    'user_id' => $user->id,
                    'church_id' => $request->church_id,
                    'service_id' => $request->service_id
                ]);
                
                $checkoutUrl = $checkoutData['attributes']['checkout_url'];
                
                // Return payment info for frontend to handle redirect
                return response()->json([
                    'success' => false,
                    'requires_payment' => true,
                    'redirect_url' => $checkoutUrl,
                    'message' => 'Payment required. Please complete payment to finalize your appointment.',
                    'payment_session' => [
                        'id' => $checkoutData['id'],
                        'checkout_url' => $checkoutUrl,
                        'expires_at' => $checkoutData['attributes']['expires_at'] ?? null
                    ],
                    'payment_details' => [
                        'amount' => $totalAmount,
                        'currency' => 'PHP',
                        'description' => $description,
                        'payment_methods' => ['gcash', 'card'],
                        'fee_breakdown' => [
                            [
                                'type' => 'Service Fee',
                                'amount' => $originalTotalAmount,
                                'discounted_amount' => $totalAmount
                            ]
                        ]
                    ],
                    'appointment_details' => [
                        'church_name' => $church->ChurchName,
                        'service_name' => $service->ServiceName,
                        'appointment_date' => $appointmentDateTime,
                        'available_slots' => $currentSlot->RemainingSlots
                    ]
                ], 402);
            }

            // Free service - create appointment directly
            Log::info('Creating free appointment', [
                'user_id' => $user->id,
                'church_id' => $request->church_id,
                'service_id' => $request->service_id,
                'schedule_id' => $request->schedule_id,
                'total_amount' => $totalAmount
            ]);
            
            // Start database transaction for atomic operations (free services)
            DB::beginTransaction();
            
            try {
                
                // Combine the selected date with the schedule time's start time
                $appointmentDateTime = \Carbon\Carbon::parse($request->selected_date)
                    ->setTimeFromTimeString($scheduleTime->StartTime)
                    ->format('Y-m-d H:i:s');

                // Create appointment
                $appointmentData = [
                    'UserID' => $user->id,
                    'ChurchID' => $request->church_id,
                    'ServiceID' => $request->service_id,
                    'ScheduleID' => $request->schedule_id,
                    'ScheduleTimeID' => $request->schedule_time_id,
                    'AppointmentDate' => $appointmentDateTime,
                    'Status' => ucfirst($request->get('status', 'pending')),
                    'Notes' => '',
                    'created_at' => now(),
                    'updated_at' => now()
                ];

                Log::info('Inserting appointment data', ['appointment_data' => $appointmentData]);
                $appointmentId = DB::table('Appointment')->insertGetId($appointmentData);
                Log::info('Appointment created successfully', ['appointment_id' => $appointmentId]);
                
                // IMMEDIATELY RESERVE THE SLOT for pending applications
                // This prevents double-booking while waiting for staff confirmation
                $statusLower = strtolower($appointmentData['Status']);
                Log::info('Checking if slot should be reserved', [
                    'appointment_status' => $appointmentData['Status'],
                    'status_lower' => $statusLower,
                    'will_reserve_slot' => ($statusLower === 'pending')
                ]);
                
                if ($statusLower === 'pending') {
                    Log::info('Reserving slot for pending appointment');
                    $this->adjustRemainingSlots($request->schedule_time_id, $appointmentDate, -1, $slotCapacity);
                } else {
                    Log::info('Not reserving slot - appointment status is not pending');
                }
                
                // Create notification for church staff
                $notification = $this->createAppointmentNotification(
                    $request->church_id,
                    $appointmentId,
                    $user,
                    $service,
                    $appointmentDateTime
                );
                
                // Load the full appointment to broadcast
                $appointment = Appointment::find($appointmentId);
                
                // Broadcast event to church staff
                event(new AppointmentCreated($appointment, $request->church_id, $notification));
                
                DB::commit();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Your sacrament application has been submitted successfully. The slot has been reserved for you.',
                    'application' => [
                        'id' => $appointmentId,
                        'church_name' => $church->ChurchName,
                        'service_name' => $service->ServiceName,
                        'appointment_date' => $appointmentDateTime,
                        'status' => ucfirst($request->get('status', 'pending'))
                    ]
                ], 201);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while submitting your application.',
                'details' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Submit appointment with form data (for custom forms when isStaffForm=false)
     */
    public function storeWithFormData(Request $request)
    {
        try {
            // Basic validation including form data
            $validator = Validator::make($request->all(), [
                'church_id' => 'required|integer|exists:Church,ChurchID',
                'service_id' => 'required|integer|exists:sacrament_service,ServiceID',
                'schedule_id' => 'required|integer|exists:service_schedules,ScheduleID',
                'schedule_time_id' => 'required|integer|exists:schedule_times,ScheduleTimeID',
                'selected_date' => 'required|date|after_or_equal:today',
                'form_data' => 'required|string', // JSON string of form data
                'documents.*' => 'sometimes|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240' // 10MB max per file
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verify user is authenticated
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'error' => 'Authentication required.'
                ], 401);
            }

            // Verify church is active and public
            $church = Church::where('ChurchID', $request->church_id)
                          ->where('ChurchStatus', Church::STATUS_ACTIVE)
                          ->where('IsPublic', true)
                          ->first();

            if (!$church) {
                return response()->json([
                    'error' => 'Church not found or not available.'
                ], 404);
            }

            // Verify service belongs to church
            $service = SacramentService::where('ServiceID', $request->service_id)
                                     ->where('ChurchID', $request->church_id)
                                     ->first();

            if (!$service) {
                return response()->json([
                    'error' => 'Service not found or not available.'
                ], 404);
            }

            // Verify schedule belongs to service
            $schedule = ServiceSchedule::where('ScheduleID', $request->schedule_id)
                                     ->where('ServiceID', $request->service_id)
                                     ->first();

            if (!$schedule) {
                return response()->json([
                    'error' => 'Schedule not found.'
                ], 404);
            }

            // Verify schedule time belongs to schedule
            $scheduleTime = DB::table('schedule_times')
                ->where('ScheduleTimeID', $request->schedule_time_id)
                ->where('ScheduleID', $request->schedule_id)
                ->first();

            if (!$scheduleTime) {
                return response()->json([
                    'error' => 'Schedule time not found.'
                ], 404);
            }

            // Note: Removed duplicate application check to allow multiple appointments
            // for the same user at the same time/date (e.g., booking multiple children for baptism)
            // Slot availability is still enforced to prevent overbooking

            // Parse form data
            $formData = json_decode($request->form_data, true);
            if (!$formData) {
                return response()->json([
                    'error' => 'Invalid form data provided.'
                ], 422);
            }
            
            // Check slot availability before creating appointment
            $appointmentDate = $request->selected_date;
            $slotCapacity = $schedule->SlotCapacity;
            
            // Ensure date slot exists and check availability (needed for both free and paid services)
            $this->ensureDateSlotExists($request->schedule_time_id, $appointmentDate, $slotCapacity);
            
            $currentSlot = DB::table('schedule_time_date_slots')
                ->where('ScheduleTimeID', $request->schedule_time_id)
                ->where('SlotDate', $appointmentDate)
                ->first();
            
            if (!$currentSlot || $currentSlot->RemainingSlots <= 0) {
                return response()->json([
                    'error' => 'No slots available for the selected date and time.'
                ], 409);
            }
            
            // Check if this service has any payable amount
            // Fee Logic: If isMultipleService, fee comes from sub_sacrament_services (variant)
            //           Otherwise, fee comes from sacrament_service (parent)
            $originalTotalAmount = 0;
            
            if ($service->isMultipleService && $schedule->SubSacramentServiceID) {
                // Service has variants - get fee from sub_sacrament_services
                $subSacramentService = DB::table('sub_sacrament_services')
                    ->where('SubSacramentServiceID', $schedule->SubSacramentServiceID)
                    ->first();
                    
                if ($subSacramentService && isset($subSacramentService->fee)) {
                    $originalTotalAmount = (float) $subSacramentService->fee;
                }
            } else {
                // Service has no variants - get fee from sacrament_service
                $originalTotalAmount = (float) ($service->fee ?? 0);
            }
            
            // For Mass services (isMass = true), donation is required with minimum ₱50
            if ($service->isMass) {
                if (!$request->has('donation_amount')) {
                    return response()->json([
                        'error' => 'Donation amount is required for Mass services.'
                    ], 422);
                }
                
                $donationAmount = floatval($request->donation_amount);
                
                if ($donationAmount < 50) {
                    return response()->json([
                        'error' => 'Minimum donation amount for Mass services is ₱50.00'
                    ], 422);
                }
                
                // Add donation amount to the total
                $originalTotalAmount += $donationAmount;
                Log::info('Mass service donation amount added', [
                    'service_id' => $service->ServiceID,
                    'donation_amount' => $donationAmount,
                    'new_total' => $originalTotalAmount
                ]);
            }
            
            // Apply member discount if user is an approved member
            $totalAmount = $this->applyMemberDiscount($originalTotalAmount, $service, $user, $request->church_id);
            
            // If service is free (not Mass), require approved membership
            if ($totalAmount == 0 && !$service->isMass) {
                $membership = \App\Models\ChurchMember::where('user_id', $user->id)
                    ->where('church_id', $request->church_id)
                    ->where('status', 'approved')
                    ->first();
                    
                if (!$membership) {
                    return response()->json([
                        'error' => 'This is a free service and requires approved membership at this church. Please apply for membership first.'
                    ], 403);
                }
            }
            
            // If fees are required, create payment checkout session instead of appointment
            if ($totalAmount > 0) {
                Log::info('Payment required for appointment with form data', [
                    'church_id' => $request->church_id,
                    'service_id' => $request->service_id,
                    'total_amount' => $totalAmount,
                    'original_amount' => $originalTotalAmount
                ]);
                
                // Check if church has payment configuration
                $paymentConfig = \App\Models\ChurchPaymentConfig::where('church_id', $request->church_id)
                    ->where('provider', 'paymongo')
                    ->first();
                
                if (!$paymentConfig) {
                    Log::error('No PayMongo config found for church', ['church_id' => $request->church_id]);
                    return response()->json([
                        'error' => 'Payment system is not configured for this church. Please contact the church administrator.',
                        'requires_setup' => true,
                        'debug_info' => 'No PayMongo configuration found'
                    ], 503);
                }
                
                if (!$paymentConfig->is_active) {
                    Log::error('PayMongo config is inactive for church', ['church_id' => $request->church_id]);
                    return response()->json([
                        'error' => 'Payment system is currently disabled for this church.',
                        'requires_setup' => true,
                        'debug_info' => 'PayMongo configuration is inactive'
                    ], 503);
                }
                
                if (!$paymentConfig->isComplete()) {
                    Log::error('Incomplete PayMongo config for church', [
                        'church_id' => $request->church_id,
                        'has_public_key' => !empty($paymentConfig->public_key),
                        'has_secret_key' => !empty($paymentConfig->secret_key)
                    ]);
                    return response()->json([
                        'error' => 'Payment system configuration is incomplete for this church.',
                        'requires_setup' => true,
                        'debug_info' => 'PayMongo keys are missing or invalid'
                    ], 503);
                }
                
                try {
                    // Initialize PayMongo service for this church
                    $paymongoService = new PayMongoService($request->church_id);
                    
                    if (!$paymongoService->isConfigured()) {
                        Log::error('PayMongo service not configured after initialization', ['church_id' => $request->church_id]);
                        return response()->json([
                            'error' => 'Payment system initialization failed.',
                            'requires_setup' => true,
                            'debug_info' => 'Service configuration failed'
                        ], 503);
                    }
                } catch (\Exception $e) {
                    Log::error('Error initializing PayMongo service', [
                        'church_id' => $request->church_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    return response()->json([
                        'error' => 'Failed to initialize payment system.',
                        'details' => $e->getMessage(),
                        'debug_info' => 'Service initialization exception'
                    ], 503);
                }
                
                // Format appointment date time
                $appointmentDateTime = \Carbon\Carbon::parse($request->selected_date)
                    ->setTimeFromTimeString($scheduleTime->StartTime)
                    ->format('Y-m-d H:i:s');
                
                // Generate unique reference number for this appointment payment
                $receiptCode = 'APT-' . strtoupper(substr(uniqid(), -8));
                
                // Prepare metadata for the checkout session
                $metadata = [
                    'user_id' => $user->id,
                    'church_id' => $request->church_id,
                    'service_id' => $request->service_id,
                    'schedule_id' => $request->schedule_id,
                    'schedule_time_id' => $request->schedule_time_id,
                    'appointment_date' => $appointmentDateTime,
                    'form_data' => $request->form_data ?? null, // Include form data for custom forms
                    'donation_amount' => $request->donation_amount ?? null, // Include donation amount for Mass services
                    'is_mass' => $service->isMass ?? false, // Flag for Mass services
                    'receipt_code' => $receiptCode,
                    'reference_number' => $receiptCode, // This will display in PayMongo checkout
                    'type' => 'appointment_payment'
                ];
                
                // Build description with reference number for tracing
                $description = sprintf(
                    '[Ref: %s] %s - %s (Date: %s, Time: %s)',
                    $receiptCode,
                    $church->ChurchName,
                    $service->ServiceName,
                    $appointmentDate,
                    $scheduleTime->StartTime
                );
                
                // Create success and cancel URLs (same as simple appointments)
                $successUrl = url('/appointment-payment/success?session_id={CHECKOUT_SESSION_ID}&church_id=' . $request->church_id);
                $cancelUrl = url('/appointment-payment/cancel?session_id={CHECKOUT_SESSION_ID}&church_id=' . $request->church_id);
                
                // Create PayMongo checkout session with GCash and Card only
                $checkoutResult = $paymongoService->createCheckoutSession(
                    $totalAmount,
                    $description,
                    $successUrl,
                    $cancelUrl,
                    ['gcash', 'card'], // Only GCash and Card payments
                    $metadata
                );
                
                if (!$checkoutResult['success']) {
                    Log::error('Failed to create PayMongo checkout session', [
                        'church_id' => $request->church_id,
                        'service_id' => $request->service_id,
                        'user_id' => $user->id,
                        'error' => $checkoutResult['error'] ?? 'Unknown error'
                    ]);
                    
                    return response()->json([
                        'error' => 'Failed to create payment session. Please try again later.',
                        'details' => $checkoutResult['error']
                    ], 500);
                }
                
                $checkoutData = $checkoutResult['data'];
                
                // Get expiration time from checkout data
                $expiresAtRaw = $checkoutData['attributes']['expires_at'] ?? null;
                
                // Create ChurchTransaction to track this payment
                try {
                    ChurchTransaction::create([
                        'user_id' => $user->id,
                        'church_id' => $request->church_id,
                        'service_id' => $request->service_id,
                        'schedule_id' => $request->schedule_id,
                        'schedule_time_id' => $request->schedule_time_id,
                        'appointment_id' => null, // Will be set after payment success
                        'paymongo_session_id' => $checkoutData['id'],
                        'receipt_code' => $receiptCode,
                        'payment_method' => 'multi', // Multi-payment (GCash/Card) - will be updated after payment
                        'amount_paid' => $totalAmount,
                        'currency' => 'PHP',
                        'transaction_type' => 'appointment_payment',
                        'status' => 'pending',
                        'checkout_url' => $checkoutData['attributes']['checkout_url'] ?? null,
                        'appointment_date' => $appointmentDateTime,
                        'transaction_date' => now(),
                        'notes' => sprintf('Pending payment for %s at %s', $service->ServiceName, $church->ChurchName),
                        'metadata' => [
                            'church_name' => $church->ChurchName,
                            'service_name' => $service->ServiceName,
                            'is_mass' => $service->isMass ?? false,
                            'receipt_code' => $receiptCode,
                            'form_data' => $request->form_data,
                            'donation_amount' => $request->donation_amount ?? null
                        ],
                        'expires_at' => $expiresAtRaw ? \Carbon\Carbon::createFromTimestamp($expiresAtRaw) : now()->addMinutes(30)
                    ]);
                    
                    Log::info('ChurchTransaction created for payment', [
                        'session_id' => $checkoutData['id'],
                        'user_id' => $user->id,
                        'church_id' => $request->church_id
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to create ChurchTransaction', [
                        'error' => $e->getMessage(),
                        'session_id' => $checkoutData['id']
                    ]);
                    // Don't fail the whole request, continue with payment
                }
                
                Log::info('PayMongo checkout session created for appointment with form data', [
                    'checkout_session_id' => $checkoutData['id'],
                    'user_id' => $user->id,
                    'church_id' => $request->church_id,
                    'service_id' => $request->service_id
                ]);
                
                $checkoutUrl = $checkoutData['attributes']['checkout_url'];
                
                // Return payment info for frontend to handle redirect
                return response()->json([
                    'success' => false,
                    'requires_payment' => true,
                    'redirect_url' => $checkoutUrl,
                    'message' => 'Payment required. Please complete payment to finalize your appointment.',
                    'payment_session' => [
                        'id' => $checkoutData['id'],
                        'checkout_url' => $checkoutUrl,
                        'expires_at' => $checkoutData['attributes']['expires_at'] ?? null
                    ],
                    'payment_details' => [
                        'amount' => $totalAmount,
                        'currency' => 'PHP',
                        'description' => $description,
                        'payment_methods' => ['gcash', 'card']
                    ],
                    'appointment_details' => [
                        'church_name' => $church->ChurchName,
                        'service_name' => $service->ServiceName,
                        'appointment_date' => $appointmentDateTime,
                        'available_slots' => $currentSlot->RemainingSlots
                    ]
                ], 402);
            }

            // Start database transaction for atomic operations (free services)
            DB::beginTransaction();
            
            try {
                
                // Combine the selected date with the schedule time's start time
                $appointmentDateTime = \Carbon\Carbon::parse($request->selected_date)
                    ->setTimeFromTimeString($scheduleTime->StartTime)
                    ->format('Y-m-d H:i:s');

                // Create appointment
                $appointmentData = [
                    'UserID' => $user->id,
                    'ChurchID' => $request->church_id,
                    'ServiceID' => $request->service_id,
                    'ScheduleID' => $request->schedule_id,
                    'ScheduleTimeID' => $request->schedule_time_id,
                    'AppointmentDate' => $appointmentDateTime,
                    'Status' => 'Pending',
                    'Notes' => '',
                    'created_at' => now(),
                    'updated_at' => now()
                ];

                $appointmentId = DB::table('Appointment')->insertGetId($appointmentData);
                
                // Save form data answers
                foreach ($formData as $fieldKey => $answerValue) {
                    // Extract field ID from field key (e.g., "field_123" -> 123)
                    $inputFieldId = $this->extractFieldId($fieldKey);
                    
                    if (!$inputFieldId || empty($answerValue)) {
                        continue; // Skip invalid or empty fields
                    }
                    
                    // Verify the field exists and belongs to this service
                    $fieldExists = DB::table('service_input_field')
                        ->where('InputFieldID', $inputFieldId)
                        ->where('ServiceID', $request->service_id)
                        ->exists();
                    
                    if (!$fieldExists) {
                        continue; // Skip fields that don't belong to this service
                    }
                    
                    // Insert the answer
                    DB::table('AppointmentInputAnswer')->insert([
                        'AppointmentID' => $appointmentId,
                        'InputFieldID' => $inputFieldId,
                        'AnswerText' => is_array($answerValue) ? json_encode($answerValue) : $answerValue,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
                
                // Handle document uploads if any
                if ($request->hasFile('documents')) {
                    $documentsPath = storage_path('app/public/appointment_documents');
                    if (!file_exists($documentsPath)) {
                        mkdir($documentsPath, 0755, true);
                    }
                    
                    foreach ($request->file('documents') as $key => $file) {
                        if ($file->isValid()) {
                            $filename = time() . '_' . $appointmentId . '_' . $file->getClientOriginalName();
                            $file->move($documentsPath, $filename);
                            
                            // Save document info to database (you might want to create a documents table)
                            // For now, we'll store it in the notes or create a simple log
                        }
                    }
                }
                
                // IMMEDIATELY RESERVE THE SLOT for pending applications
                $this->adjustRemainingSlots($request->schedule_time_id, $appointmentDate, -1, $slotCapacity);
                
                DB::commit();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Your sacrament application with form data has been submitted successfully.',
                    'application' => [
                        'id' => $appointmentId,
                        'church_name' => $church->ChurchName,
                        'service_name' => $service->ServiceName,
                        'appointment_date' => $appointmentDateTime,
                        'status' => 'Pending'
                    ]
                ], 201);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while submitting your application.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's appointments
     */
    public function getUserAppointments(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'error' => 'Authentication required.'
                ], 401);
            }

            $appointments = DB::table('Appointment as a')
                             ->leftJoin('Church as c', 'a.ChurchID', '=', 'c.ChurchID')
                             ->leftJoin('sacrament_service as s', 'a.ServiceID', '=', 's.ServiceID')
                             ->leftJoin('schedule_times as st', 'a.ScheduleTimeID', '=', 'st.ScheduleTimeID')
                             ->leftJoin('service_schedules as ss', 'st.ScheduleID', '=', 'ss.ScheduleID')
                             ->leftJoin('sub_sacrament_services as sss', 'ss.SubSacramentServiceID', '=', 'sss.SubSacramentServiceID')
                             ->where('a.UserID', $user->id)
                             ->orderBy('a.created_at', 'desc')
                             ->select([
                                 'a.AppointmentID',
                                 'a.AppointmentDate',
                                 'a.Status',
                                 'a.Notes',
                                 'a.created_at',
                                 'c.ChurchName',
                                 's.ServiceName',
                                 's.Description as ServiceDescription',
                                 's.isMultipleService',
                                 'st.StartTime',
                                 'st.EndTime',
                                 'sss.SubServiceName',
                                 'sss.SubSacramentServiceID'
                             ])
                             ->get();

            return response()->json([
                'appointments' => $appointments
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching appointments.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get appointment details
     */
    public function show(Request $request, int $appointmentId): JsonResponse
    {
        try {
            // Get appointment with related data (no user restriction for church staff)
            $appointment = DB::table('Appointment as a')
                            ->join('Church as c', 'a.ChurchID', '=', 'c.ChurchID')
                            ->join('sacrament_service as s', 'a.ServiceID', '=', 's.ServiceID')
                            ->where('a.AppointmentID', $appointmentId)
                            ->select([
                                'a.AppointmentID',
                                'a.AppointmentDate',
                                'a.Status',
                                'a.Notes',
                                'c.ChurchName',
                                'c.ChurchID',
                                'c.Street',
                                'c.City',
                                'c.Province',
                                's.ServiceName',
                                's.ServiceID',
                                's.Description as ServiceDescription',
                                's.isMass',
                                's.isCertificateGeneration'
                            ])
                            ->first();

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found.'
                ], 404);
            }

            // Get complete form configuration for this sacrament service
            $formFields = DB::table('service_input_field')
                            ->where('ServiceID', $appointment->ServiceID)
                            ->orderBy('SortOrder')
                            ->get();

            // Get service requirements
            $requirements = DB::table('service_requirement')
                            ->where('ServiceID', $appointment->ServiceID)
                            ->orderBy('SortOrder')
                            ->get();

            // Get saved answers for this appointment
            $savedAnswers = DB::table('AppointmentInputAnswer')
                            ->where('AppointmentID', $appointmentId)
                            ->get()
                            ->keyBy('InputFieldID'); // Key by InputFieldID for easy lookup

            // Format complete form configuration for the frontend
            $formElements = [];
            $containerElement = null;
            
            // First pass: identify container element
            foreach ($formFields as $field) {
                if ($field->InputType === 'container') {
                    $containerElement = $field;
                    break;
                }
            }
            
            foreach ($formFields as $field) {
                $inputType = $field->InputType;
                // Map backend types to frontend types
                if ($inputType === 'phone') {
                    $inputType = 'tel';
                }

                // Determine containerId - elements inside container should reference container
                $containerId = null;
                if ($containerElement && $field->InputFieldID !== $containerElement->InputFieldID) {
                    // Check if element is positioned inside the container bounds
                    $containerX = $containerElement->x_position ?? 0;
                    $containerY = $containerElement->y_position ?? 0;
                    $containerWidth = $containerElement->width ?? 600;
                    $containerHeight = $containerElement->height ?? 400;
                    $containerPadding = 30; // Default padding
                    
                    $elementX = $field->x_position ?? 0;
                    $elementY = $field->y_position ?? 0;
                    $elementWidth = $field->width ?? 300;
                    $elementHeight = $field->height ?? 40;
                    
                    // Check if element is inside container bounds (accounting for padding)
                    if ($elementX >= $containerX + $containerPadding &&
                        $elementY >= $containerY + $containerPadding &&
                        $elementX + $elementWidth <= $containerX + $containerWidth - $containerPadding &&
                        $elementY + $elementHeight <= $containerY + $containerHeight - $containerPadding) {
                        $containerId = $containerElement->InputFieldID;
                        // Convert absolute position to relative position within container
                        $field->x_position = $elementX - $containerX - $containerPadding;
                        $field->y_position = $elementY - $containerY - $containerPadding;
                    }
                }

                // Get previously saved answer for this field, or blank if none
                $savedAnswer = $savedAnswers->get($field->InputFieldID);
                $answerText = $savedAnswer ? $savedAnswer->AnswerText : '';

                // Debug logging for labels
                \Log::info('Backend label data:', [
                    'InputFieldID' => $field->InputFieldID,
                    'Label' => $field->Label,
                    'InputType' => $inputType,
                    'IsRequired' => $field->IsRequired
                ]);
                
                $formElements[] = [
                    'id' => $field->InputFieldID,
                    'type' => $inputType,
                    'label' => $field->Label,
                    'placeholder' => $field->Placeholder,
                    'required' => $field->IsRequired,
                    'options' => $field->Options ? json_decode($field->Options, true) : [],
                    'x' => $field->x_position ?? 0,
                    'y' => $field->y_position ?? 0,
                    'width' => $field->width ?? 300,
                    'height' => $field->height ?? 40,
                    'content' => $field->text_content ?? '',
                    'headingSize' => $field->text_size ?? 'h2',
                    'textAlign' => $field->text_align ?? 'left',
                    'textColor' => $field->text_color ?? '#000000',
                    'rows' => $field->textarea_rows ?? 3,
                    'zIndex' => $field->z_index ?? 1,
                    'containerId' => $containerId,
                    'answer' => $answerText,
                    // Additional styling properties for container
                    'backgroundColor' => $inputType === 'container' ? '#ffffff' : null,
                    'borderColor' => $inputType === 'container' ? '#e5e7eb' : null,
                    'borderWidth' => $inputType === 'container' ? 2 : null,
                    'borderRadius' => $inputType === 'container' ? 8 : null,
                    'padding' => $inputType === 'container' ? 30 : null,
                ];
            }

            // Get requirement submissions for this appointment
            $requirementSubmissions = DB::table('appointment_requirement_submissions')
                ->where('AppointmentID', $appointmentId)
                ->get()
                ->keyBy('RequirementID');

            // Format requirements with submission status
            $formRequirements = [];
            foreach ($requirements as $req) {
                $submission = $requirementSubmissions->get($req->RequirementID);
                $formRequirements[] = [
                    'id' => $req->RequirementID,
                    'description' => $req->Description,
                    'needed' => $req->isNeeded,
                    'isSubmitted' => $submission ? $submission->isSubmitted : false,
                    'submitted_at' => $submission ? $submission->submitted_at : null,
                    'notes' => $submission ? $submission->notes : null
                ];
            }

            // Get sub-services for this sacrament service
            $subServices = DB::table('sub_service')
                ->where('ServiceID', $appointment->ServiceID)
                ->where('IsActive', true)
                ->get();

            // Get sub-service completion status for this appointment
            $subServiceStatuses = DB::table('appointment_sub_service_status')
                ->where('AppointmentID', $appointmentId)
                ->get()
                ->keyBy('SubServiceID');

            // Get sub-service requirements and their submission status
            $formSubServices = [];
            foreach ($subServices as $subService) {
                // Get requirements for this sub-service
                $subServiceRequirements = DB::table('sub_service_requirements')
                    ->where('SubServiceID', $subService->SubServiceID)
                    ->orderBy('SortOrder')
                    ->get();

                // Get requirement submissions for this appointment's sub-service
                $subServiceRequirementSubmissions = DB::table('appointment_sub_service_requirement_submissions')
                    ->where('AppointmentID', $appointmentId)
                    ->whereIn('SubServiceRequirementID', $subServiceRequirements->pluck('RequirementID'))
                    ->get()
                    ->keyBy('SubServiceRequirementID');

                // Format sub-service requirements
                $formattedSubServiceRequirements = [];
                foreach ($subServiceRequirements as $req) {
                    $submission = $subServiceRequirementSubmissions->get($req->RequirementID);
                    $formattedSubServiceRequirements[] = [
                        'id' => $req->RequirementID,
                        'description' => $req->RequirementName,
                        'needed' => $req->isNeeded,
                        'isSubmitted' => $submission ? $submission->isSubmitted : false,
                        'submitted_at' => $submission ? $submission->submitted_at : null,
                        'notes' => $submission ? $submission->notes : null
                    ];
                }

                // Get completion status for this sub-service
                $status = $subServiceStatuses->get($subService->SubServiceID);
                $formSubServices[] = [
                    'id' => $subService->SubServiceID,
                    'name' => $subService->SubServiceName,
                    'description' => $subService->Description,
                    'isCompleted' => $status ? $status->isCompleted : false,
                    'completed_at' => $status ? $status->completed_at : null,
                    'requirements' => $formattedSubServiceRequirements
                ];
            }

            // Structure the response with proper service object
            $responseData = [
                'AppointmentID' => $appointment->AppointmentID,
                'AppointmentDate' => $appointment->AppointmentDate,
                'Status' => $appointment->Status,
                'Notes' => $appointment->Notes,
                'ChurchID' => $appointment->ChurchID,
                'ChurchName' => $appointment->ChurchName,
                'ServiceID' => $appointment->ServiceID,
                'ServiceName' => $appointment->ServiceName,
                'ServiceDescription' => $appointment->ServiceDescription,
                'church' => [
                    'church_name' => $appointment->ChurchName,
                    'street' => $appointment->Street,
                    'city' => $appointment->City,
                    'province' => $appointment->Province
                ],
                'service' => [
                    'ServiceID' => $appointment->ServiceID,
                    'ServiceName' => $appointment->ServiceName,
                    'Description' => $appointment->ServiceDescription,
                    'isMass' => $appointment->isMass,
                    'isCertificateGeneration' => $appointment->isCertificateGeneration
                ],
                'formConfiguration' => [
                    'form_elements' => $formElements,
                    'requirements' => $formRequirements,
                    'sub_services' => $formSubServices
                ]
            ];

            return response()->json($responseData);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching appointment details.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get appointments for a specific church (for church staff)
     */
    public function getChurchAppointments(Request $request, $churchName): JsonResponse
    {
        try {
            // Convert URL-friendly church name to proper case (e.g., "humble" to "Humble")
            $name = str_replace('-', ' ', ucwords($churchName, '-'));

            // Find the church by name (case-insensitive)
            $church = DB::table('Church')
                ->whereRaw('LOWER(ChurchName) = ?', [strtolower($name)])
                ->first();

            if (!$church) {
                return response()->json(['error' => 'Church not found'], 404);
            }

            // Get appointments for the church with user and service information
            $appointments = DB::table('Appointment as a')
                ->join('users as u', 'a.UserID', '=', 'u.id')
                ->join('user_profiles as p', 'u.id', '=', 'p.user_id')
                ->join('sacrament_service as s', 'a.ServiceID', '=', 's.ServiceID')
                ->join('schedule_times as st', 'a.ScheduleTimeID', '=', 'st.ScheduleTimeID')
                ->join('service_schedules as ss', 'st.ScheduleID', '=', 'ss.ScheduleID')
                ->leftJoin('sub_sacrament_services as sss', 'ss.SubSacramentServiceID', '=', 'sss.SubSacramentServiceID')
                ->where('a.ChurchID', $church->ChurchID)
                ->orderBy('a.created_at', 'asc')
                ->select([
                    'a.AppointmentID',
                    'a.AppointmentDate',
                    'a.ScheduleTimeID',
                    'a.Status',
                    'a.Notes',
                    'a.cancellation_category',
                    'a.cancellation_note',
                    'a.cancelled_at',
                    'a.created_at',
                    'u.email as UserEmail',
                    DB::raw("COALESCE(p.first_name, '') || ' ' || COALESCE(p.middle_name || '. ', '') || COALESCE(p.last_name, '') as UserName"),
                    's.ServiceID',
                    's.ServiceName',
                    's.Description as ServiceDescription',
                    's.isMass',
                    'st.StartTime',
                    'st.EndTime',
                    'sss.SubServiceName',
                    'sss.SubSacramentServiceID'
                ])
                ->get();

            return response()->json([
                'ChurchID' => $church->ChurchID,
                'ChurchName' => $church->ChurchName,
                'appointments' => $appointments
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching church appointments.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update appointment status with slot management
     */
    public function updateStatus(Request $request, int $appointmentId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:Pending,Approved,Rejected,Cancelled,Completed',
                'cancellation_category' => 'nullable|string|in:no_fee,with_fee',
                'cancellation_note' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Invalid status provided.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get current appointment details before updating
            $appointment = DB::table('Appointment')
                ->where('AppointmentID', $appointmentId)
                ->first();

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found.'
                ], 404);
            }

            $newStatus = $request->status;
            $oldStatus = $appointment->Status;

            // Start database transaction for atomic operations
            DB::beginTransaction();

            try {
                // Prepare update data
                $updateData = [
                    'Status' => $newStatus,
                    'updated_at' => now()
                ];
                
                // If changing to Cancelled, handle cancellation category and note
                if ($newStatus === 'Cancelled') {
                    $updateData['cancelled_at'] = now();
                    
                    // Use custom category and note if provided, otherwise auto-determine
                    if ($request->has('cancellation_category') && $request->has('cancellation_note')) {
                        // Staff manually selected category and provided note
                        $updateData['cancellation_category'] = $request->cancellation_category;
                        $updateData['cancellation_note'] = $request->cancellation_note;
                    } else {
                        // Auto-determine based on previous status (fallback)
                        if ($oldStatus === 'Pending') {
                            $updateData['cancellation_category'] = 'no_fee';
                            $updateData['cancellation_note'] = 'Cancelled before approval - No preparation done. Full refund applicable (no convenience fee).';
                        } else if ($oldStatus === 'Approved' || $oldStatus === 'Completed') {
                            $updateData['cancellation_category'] = 'with_fee';
                            $updateData['cancellation_note'] = 'Cancelled after approval - Preparation already started. Convenience fee applies to refund.';
                        } else {
                            $updateData['cancellation_category'] = 'no_fee';
                            $updateData['cancellation_note'] = 'Cancelled from ' . $oldStatus . ' status. Full refund applicable (no convenience fee).';
                        }
                    }
                }
                
                // Update appointment status
                $updated = DB::table('Appointment')
                    ->where('AppointmentID', $appointmentId)
                    ->update($updateData);

                if (!$updated) {
                    throw new \Exception('Failed to update appointment status.');
                }

                // Handle slot management based on status changes
                $this->updateSlotAvailability($appointment, $oldStatus, $newStatus);

                // Send notifications for status changes
                $this->sendStatusChangeNotifications($appointmentId, $appointment, $newStatus, $oldStatus);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Appointment status updated successfully.',
                    'status' => $newStatus,
                    'previous_status' => $oldStatus
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while updating appointment status.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update requirement submission status
     */
    public function updateRequirementSubmission(Request $request, int $appointmentId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'requirement_id' => 'required|integer|exists:service_requirement,RequirementID',
                'is_submitted' => 'required|boolean',
                'notes' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Invalid data provided.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'error' => 'Authentication required.'
                ], 401);
            }

            // Verify appointment exists
            $appointment = DB::table('Appointment')
                ->where('AppointmentID', $appointmentId)
                ->first();

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found.'
                ], 404);
            }

            // Create or update requirement submission
            $submissionData = [
                'AppointmentID' => $appointmentId,
                'RequirementID' => $request->requirement_id,
                'isSubmitted' => $request->is_submitted,
                'notes' => $request->notes,
                'reviewed_by' => $user->id,
                'submitted_at' => $request->is_submitted ? now() : null,
                'updated_at' => now()
            ];

            DB::table('appointment_requirement_submissions')
                ->updateOrInsert(
                    [
                        'AppointmentID' => $appointmentId,
                        'RequirementID' => $request->requirement_id
                    ],
                    array_merge($submissionData, ['created_at' => now()])
                );

            return response()->json([
                'message' => 'Requirement submission status updated successfully.',
                'data' => [
                    'appointment_id' => $appointmentId,
                    'requirement_id' => $request->requirement_id,
                    'is_submitted' => $request->is_submitted,
                    'submitted_at' => $request->is_submitted ? now()->toISOString() : null
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while updating requirement submission.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update sub-service completion status
     */
    public function updateSubServiceCompletion(Request $request, int $appointmentId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'sub_service_id' => 'required|integer|exists:sub_service,SubServiceID',
                'is_completed' => 'required|boolean',
                'notes' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Invalid data provided.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();
            if (!$user) {
                return response()->json(['error' => 'Authentication required.'], 401);
            }

            // Verify appointment exists
            $appointment = DB::table('Appointment')->where('AppointmentID', $appointmentId)->first();
            if (!$appointment) {
                return response()->json(['error' => 'Appointment not found.'], 404);
            }

            // Update sub-service completion status
            $statusData = [
                'AppointmentID' => $appointmentId,
                'SubServiceID' => $request->sub_service_id,
                'isCompleted' => $request->is_completed,
                'notes' => $request->notes,
                'reviewed_by' => $user->id,
                'completed_at' => $request->is_completed ? now() : null,
                'updated_at' => now()
            ];

            DB::table('appointment_sub_service_status')
                ->updateOrInsert(
                    ['AppointmentID' => $appointmentId, 'SubServiceID' => $request->sub_service_id],
                    array_merge($statusData, ['created_at' => now()])
                );

            return response()->json([
                'message' => 'Sub-service completion status updated successfully.',
                'data' => [
                    'appointment_id' => $appointmentId,
                    'sub_service_id' => $request->sub_service_id,
                    'is_completed' => $request->is_completed,
                    'completed_at' => $request->is_completed ? now()->toISOString() : null
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while updating sub-service completion.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update sub-service requirement submission status
     */
    public function updateSubServiceRequirementSubmission(Request $request, int $appointmentId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'sub_service_requirement_id' => 'required|integer|exists:sub_service_requirements,RequirementID',
                'is_submitted' => 'required|boolean',
                'notes' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Invalid data provided.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();
            if (!$user) {
                return response()->json(['error' => 'Authentication required.'], 401);
            }

            // Verify appointment exists
            $appointment = DB::table('Appointment')->where('AppointmentID', $appointmentId)->first();
            if (!$appointment) {
                return response()->json(['error' => 'Appointment not found.'], 404);
            }

            // Update sub-service requirement submission
            $submissionData = [
                'AppointmentID' => $appointmentId,
                'SubServiceRequirementID' => $request->sub_service_requirement_id,
                'isSubmitted' => $request->is_submitted,
                'notes' => $request->notes,
                'reviewed_by' => $user->id,
                'submitted_at' => $request->is_submitted ? now() : null,
                'updated_at' => now()
            ];

            DB::table('appointment_sub_service_requirement_submissions')
                ->updateOrInsert(
                    ['AppointmentID' => $appointmentId, 'SubServiceRequirementID' => $request->sub_service_requirement_id],
                    array_merge($submissionData, ['created_at' => now()])
                );

            return response()->json([
                'message' => 'Sub-service requirement submission updated successfully.',
                'data' => [
                    'appointment_id' => $appointmentId,
                    'sub_service_requirement_id' => $request->sub_service_requirement_id,
                    'is_submitted' => $request->is_submitted,
                    'submitted_at' => $request->is_submitted ? now()->toISOString() : null
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while updating sub-service requirement submission.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update slot availability based on appointment status changes
     */
    private function updateSlotAvailability($appointment, string $oldStatus, string $newStatus): void
    {
        // Get the appointment date (just the date part)
        $appointmentDate = \Carbon\Carbon::parse($appointment->AppointmentDate)->format('Y-m-d');
        $scheduleTimeId = $appointment->ScheduleTimeID;
        $scheduleId = $appointment->ScheduleID;

        // Get schedule capacity to initialize slots if needed
        $schedule = DB::table('service_schedules')
            ->where('ScheduleID', $scheduleId)
            ->first();

        if (!$schedule) {
            throw new \Exception('Schedule not found.');
        }

        $slotCapacity = $schedule->SlotCapacity;

        // Ensure date slot exists for this schedule time and date
        $this->ensureDateSlotExists($scheduleTimeId, $appointmentDate, $slotCapacity);

        // Determine slot adjustment based on status transition
        $slotAdjustment = $this->calculateSlotAdjustment($oldStatus, $newStatus);

        if ($slotAdjustment !== 0) {
            // Update remaining slots
            $this->adjustRemainingSlots($scheduleTimeId, $appointmentDate, $slotAdjustment, $slotCapacity);
        }
    }

    /**
     * Calculate how many slots to adjust based on status change
     */
    private function calculateSlotAdjustment(string $oldStatus, string $newStatus): int
    {
        // Define which statuses "consume" a slot (reduce availability)
        // Pending and Approved both consume slots to prevent double booking
        $slotConsumingStatuses = ['Pending', 'Approved'];
        
        $oldConsumesSlot = in_array($oldStatus, $slotConsumingStatuses);
        $newConsumesSlot = in_array($newStatus, $slotConsumingStatuses);

        if (!$oldConsumesSlot && $newConsumesSlot) {
            // Transitioning to a slot-consuming status: decrease available slots
            return -1;
        } elseif ($oldConsumesSlot && !$newConsumesSlot) {
            // Transitioning from a slot-consuming status: increase available slots
            return 1;
        }
        
        // No slot adjustment needed (both consume slots or both don't)
        return 0;
    }

    /**
     * Ensure a date slot record exists for the given schedule time and date
     */
    private function ensureDateSlotExists(int $scheduleTimeId, string $date, int $slotCapacity): void
    {
        $existingSlot = DB::table('schedule_time_date_slots')
            ->where('ScheduleTimeID', $scheduleTimeId)
            ->where('SlotDate', $date)
            ->first();

        if (!$existingSlot) {
            // Create new date slot record with full capacity
            DB::table('schedule_time_date_slots')->insert([
                'ScheduleTimeID' => $scheduleTimeId,
                'SlotDate' => $date,
                'RemainingSlots' => $slotCapacity,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }

    /**
     * Adjust remaining slots for a specific schedule time and date
     */
    private function adjustRemainingSlots(int $scheduleTimeId, string $date, int $adjustment, int $maxCapacity): void
    {
        // Get current slot info
        $currentSlot = DB::table('schedule_time_date_slots')
            ->where('ScheduleTimeID', $scheduleTimeId)
            ->where('SlotDate', $date)
            ->first();

        if (!$currentSlot) {
            throw new \Exception('Date slot record not found.');
        }

        $newRemainingSlots = $currentSlot->RemainingSlots + $adjustment;

        // Ensure remaining slots don't go below 0 or above capacity
        if ($newRemainingSlots < 0) {
            throw new \Exception('Cannot approve appointment: No slots remaining for this date and time.');
        }
        
        if ($newRemainingSlots > $maxCapacity) {
            $newRemainingSlots = $maxCapacity;
        }

        // Update the remaining slots
        $updated = DB::table('schedule_time_date_slots')
            ->where('ScheduleTimeID', $scheduleTimeId)
            ->where('SlotDate', $date)
            ->update([
                'RemainingSlots' => $newRemainingSlots,
                'updated_at' => now()
            ]);

        if (!$updated) {
            throw new \Exception('Failed to update slot availability.');
        }
    }

    /**
     * Save form data for an appointment
     */
    public function saveFormData(Request $request, int $appointmentId): JsonResponse
    {
        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'formData' => 'required|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Invalid form data provided.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verify appointment exists
            $appointment = DB::table('Appointment')
                ->where('AppointmentID', $appointmentId)
                ->first();

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found.'
                ], 404);
            }

            $formData = $request->formData;
            $savedAnswers = [];

            // Start transaction for atomic operations
            DB::beginTransaction();

            try {
                foreach ($formData as $fieldName => $answerText) {
                    // Extract field ID from field name (assuming format like "field_123" or just "123")
                    $inputFieldId = $this->extractFieldId($fieldName);
                    
                    if (!$inputFieldId) {
                        continue; // Skip invalid field names
                    }

                    // Verify the field exists and belongs to this appointment's service
                    $fieldExists = DB::table('service_input_field')
                        ->where('InputFieldID', $inputFieldId)
                        ->where('ServiceID', $appointment->ServiceID)
                        ->exists();

                    if (!$fieldExists) {
                        continue; // Skip fields that don't exist or don't belong to this service
                    }

                    // Insert or update the answer
                    DB::table('AppointmentInputAnswer')->updateOrInsert(
                        [
                            'AppointmentID' => $appointmentId,
                            'InputFieldID' => $inputFieldId
                        ],
                        [
                            'AnswerText' => $answerText,
                            'updated_at' => now()
                        ]
                    );

                    $savedAnswers[] = [
                        'field_id' => $inputFieldId,
                        'field_name' => $fieldName,
                        'answer' => $answerText
                    ];
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Form data saved successfully.',
                    'appointment_id' => $appointmentId,
                    'saved_answers' => $savedAnswers,
                    'total_answers' => count($savedAnswers)
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while saving form data.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create PayMongo checkout session for appointment payment
     */
    public function createPaymentCheckout(Request $request): JsonResponse
    {
        try {
            // Validate required fields
            $validator = Validator::make($request->all(), [
                'church_id' => 'required|integer|exists:Church,ChurchID',
                'service_id' => 'required|integer|exists:sacrament_service,ServiceID',
                'schedule_id' => 'required|integer|exists:service_schedules,ScheduleID',
                'schedule_time_id' => 'required|integer|exists:schedule_times,ScheduleTimeID',
                'selected_date' => 'required|date|after_or_equal:today',
                'form_data' => 'sometimes|string', // JSON string of form data (optional)
                'success_url' => 'required|url',
                'cancel_url' => 'required|url'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verify user is authenticated
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'error' => 'Authentication required.'
                ], 401);
            }

            // Verify church is active and public
            $church = Church::where('ChurchID', $request->church_id)
                          ->where('ChurchStatus', Church::STATUS_ACTIVE)
                          ->where('IsPublic', true)
                          ->first();

            if (!$church) {
                return response()->json([
                    'error' => 'Church not found or not available.'
                ], 404);
            }

            // Verify service belongs to church
            $service = SacramentService::where('ServiceID', $request->service_id)
                                     ->where('ChurchID', $request->church_id)
                                     ->first();

            if (!$service) {
                return response()->json([
                    'error' => 'Service not found or not available.'
                ], 404);
            }

            // Verify schedule belongs to service
            $schedule = ServiceSchedule::where('ScheduleID', $request->schedule_id)
                                     ->where('ServiceID', $request->service_id)
                                     ->first();

            if (!$schedule) {
                return response()->json([
                    'error' => 'Schedule not found.'
                ], 404);
            }

            // Verify schedule time belongs to schedule
            $scheduleTime = DB::table('schedule_times')
                ->where('ScheduleTimeID', $request->schedule_time_id)
                ->where('ScheduleID', $request->schedule_id)
                ->first();

            if (!$scheduleTime) {
                return response()->json([
                    'error' => 'Schedule time not found.'
                ], 404);
            }

            // Get schedule fees
            $fees = ScheduleFee::where('ScheduleID', $request->schedule_id)->get();
            
            if ($fees->isEmpty()) {
                return response()->json([
                    'error' => 'No fees configured for this schedule.'
                ], 404);
            }

            // Calculate total amount (include Fee and Donation as long as amount > 0)
            $payableFees = $fees->filter(function ($fee) { return ($fee->Fee ?? 0) > 0; });
            $totalAmount = $payableFees->sum('Fee');
            
            if ($totalAmount <= 0) {
                return response()->json([
                    'error' => 'No payment required for this appointment.'
                ], 400);
            }

            // Note: Removed duplicate application check to allow multiple appointments
            // for the same user at the same time/date (e.g., booking multiple children for baptism)
            // Slot availability is still enforced to prevent overbooking

            // Check slot availability
            $appointmentDate = $request->selected_date;
            $slotCapacity = $schedule->SlotCapacity;
            
            // Ensure date slot exists for this schedule time and date
            $this->ensureDateSlotExists($request->schedule_time_id, $appointmentDate, $slotCapacity);
            
            // Check if slots are available
            $currentSlot = DB::table('schedule_time_date_slots')
                ->where('ScheduleTimeID', $request->schedule_time_id)
                ->where('SlotDate', $appointmentDate)
                ->first();
            
            if (!$currentSlot || $currentSlot->RemainingSlots <= 0) {
                return response()->json([
                    'error' => 'No slots available for the selected date and time.'
                ], 409);
            }

            // Initialize PayMongo service for this church
            $paymongoService = new PayMongoService($request->church_id);
            
            if (!$paymongoService->isConfigured()) {
                return response()->json([
                    'error' => 'Payment system is not configured for this church.'
                ], 503);
            }

            // Format appointment date time
            $appointmentDateTime = \Carbon\Carbon::parse($request->selected_date)
                ->setTimeFromTimeString($scheduleTime->StartTime)
                ->format('Y-m-d H:i:s');

            // Generate unique receipt code for PayMongo email receipt reference
            do {
                $receiptCode = 'TXN' . str_pad(mt_rand(1, 99999999), 8, '0', STR_PAD_LEFT);
            } while (ChurchTransaction::where('receipt_code', $receiptCode)->exists());

            // Prepare metadata for the checkout session
            $metadata = [
                'user_id' => $user->id,
                'church_id' => $request->church_id,
                'service_id' => $request->service_id,
                'schedule_id' => $request->schedule_id,
                'schedule_time_id' => $request->schedule_time_id,
                'appointment_date' => $appointmentDateTime,
                'form_data' => $request->form_data ?? null,
                'type' => 'appointment_payment',
                'church_name' => $church->ChurchName,
                'service_name' => $service->ServiceName,
                'receipt_code' => $receiptCode
            ];

            // Build description
            $description = sprintf(
                '%s - %s (Date: %s, Time: %s)',
                $church->ChurchName,
                $service->ServiceName,
                $appointmentDate,
                $scheduleTime->StartTime
            );

            // Create PayMongo checkout session with multiple payment methods  
            $successUrl = url('/appointment-payment/success?session_id={CHECKOUT_SESSION_ID}&church_id=' . $request->church_id);
            $cancelUrl = url('/appointment-payment/cancel?session_id={CHECKOUT_SESSION_ID}');
            
            $checkoutResult = $paymongoService->createMultiPaymentCheckout(
                $totalAmount,
                $description,
                $successUrl,
                $cancelUrl,
                $metadata
            );

            if (!$checkoutResult['success']) {
                Log::error('Failed to create PayMongo checkout session', [
                    'church_id' => $request->church_id,
                    'service_id' => $request->service_id,
                    'user_id' => $user->id,
                    'error' => $checkoutResult['error'] ?? 'Unknown error',
                    'details' => $checkoutResult['details'] ?? null
                ]);

                return response()->json([
                    'error' => 'Failed to create payment session. Please try again later.',
                    'details' => $checkoutResult['error']
                ], 500);
            }

            $checkoutData = $checkoutResult['data'];

            // Log successful session creation (without sensitive data)
            Log::info('PayMongo checkout session created for appointment', [
                'checkout_session_id' => $checkoutData['id'],
                'user_id' => $user->id,
                'church_id' => $request->church_id,
                'service_id' => $request->service_id,
                'amount' => $totalAmount,
                'description' => $description
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment session created successfully.',
                'checkout_session' => [
                    'id' => $checkoutData['id'],
                    'checkout_url' => $checkoutData['attributes']['checkout_url'],
                    'expires_at' => $checkoutData['attributes']['expires_at'] ?? null
                ],
                'payment_details' => [
                    'amount' => $totalAmount,
                    'currency' => 'PHP',
                    'description' => $description,
                    'fees' => $fees->map(function ($fee) {
                        return [
                            'type' => $fee->FeeType,
                            'amount' => $fee->Fee,
                            'description' => $fee->getDescription()
                        ];
                    })
                ],
                'appointment_details' => [
                    'church_name' => $church->ChurchName,
                    'service_name' => $service->ServiceName,
                    'appointment_date' => $appointmentDateTime,
                    'available_slots' => $currentSlot->RemainingSlots
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error creating payment checkout session', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['form_data']) // Exclude potentially large form data
            ]);

            return response()->json([
                'error' => 'An error occurred while creating the payment session.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle successful payment and create appointment
     */
    public function handlePaymentSuccess(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'checkout_session_id' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Invalid request.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'error' => 'Authentication required.'
                ], 401);
            }

// Retrieve checkout session from PayMongo to validate payment
            $checkoutSessionId = $request->checkout_session_id;
            
            // Require church_id from frontend (stored before redirect)
            if (!$request->church_id) {
                return response()->json([
                    'error' => 'Church ID is required to validate payment.'
                ], 400);
            }

            $paymongoService = new PayMongoService($request->church_id);
            $sessionResult = $paymongoService->getCheckoutSession($checkoutSessionId);

            if (!$sessionResult['success']) {
                return response()->json([
                    'error' => 'Invalid or expired payment session.'
                ], 400);
            }

            $sessionData = $sessionResult['data'];
            $attributes = $sessionData['attributes'] ?? [];
            $metadata = $attributes['metadata'] ?? [];

            // Verify the payment was successful (support multiple status shapes)
            $paymentStatus = $attributes['payment_status'] ?? null;
            $genericStatus = $attributes['status'] ?? null;
            $payments = $attributes['payments'] ?? [];

            $isPaid = ($paymentStatus === 'paid') || ($genericStatus === 'paid');
            if (!$isPaid && is_array($payments)) {
                foreach ($payments as $p) {
                    if (($p['attributes']['status'] ?? null) === 'paid') { $isPaid = true; break; }
                }
            }

            if (!$isPaid) {
                \Log::warning('Payment success called but session not paid', [
                    'session_id' => $request->checkout_session_id,
                    'payment_status' => $paymentStatus,
                    'status' => $genericStatus
                ]);
                return response()->json([
                    'error' => 'Payment has not been completed yet.'
                ], 400);
            }

            // Verify this session belongs to the authenticated user
            if (($metadata['user_id'] ?? null) != $user->id) {
                return response()->json([
                    'error' => 'Unauthorized access to payment session.'
                ], 403);
            }

// Extract appointment details from metadata
            $churchId = $metadata['church_id'] ?? $request->church_id;
            $serviceId = $metadata['service_id'] ?? null;
            $scheduleId = $metadata['schedule_id'] ?? null;
            $scheduleTimeId = $metadata['schedule_time_id'] ?? null;
            $appointmentDate = $metadata['appointment_date'] ?? null;
            $formData = $metadata['form_data'] ?? null;

            // Check if appointment was already created for this specific payment session
            $existingTransaction = DB::table('church_transactions')
                ->where('paymongo_session_id', $checkoutSessionId)
                ->first();

            if ($existingTransaction) {
                return response()->json([
                    'success' => true,
                    'message' => 'Appointment already exists for this payment session.',
                    'appointment_id' => $existingTransaction->appointment_id
                ]);
            }

            // Get related entities for validation
            $church = Church::find($churchId);
            $service = SacramentService::find($serviceId);
            $schedule = ServiceSchedule::find($scheduleId);

            if (!$church || !$service || !$schedule) {
                return response()->json([
                    'error' => 'Invalid appointment data in payment session.'
                ], 400);
            }

            // Start database transaction for atomic operations
            DB::beginTransaction();
            
            try {
                // Final slot availability check
                $slotDate = \Carbon\Carbon::parse($appointmentDate)->format('Y-m-d');
                $currentSlot = DB::table('schedule_time_date_slots')
                    ->where('ScheduleTimeID', $scheduleTimeId)
                    ->where('SlotDate', $slotDate)
                    ->first();
                
                if (!$currentSlot || $currentSlot->RemainingSlots <= 0) {
                    throw new \Exception('No slots available for the selected date and time.');
                }

                // Create appointment
                $appointmentData = [
                    'UserID' => $user->id,
                    'ChurchID' => $churchId,
                    'ServiceID' => $serviceId,
                    'ScheduleID' => $scheduleId,
                    'ScheduleTimeID' => $scheduleTimeId,
                    'AppointmentDate' => $appointmentDate,
                    'Status' => 'Pending', // Payment confirmed, awaiting church approval
                    'Notes' => '',
                    'created_at' => now(),
                    'updated_at' => now()
                ];

                $appointmentId = DB::table('Appointment')->insertGetId($appointmentData);
                
                // Save form data if provided
                if ($formData) {
                    $formDataArray = json_decode($formData, true);
                    if ($formDataArray) {
                        foreach ($formDataArray as $fieldKey => $answerValue) {
                            $inputFieldId = $this->extractFieldId($fieldKey);
                            
                            if (!$inputFieldId || empty($answerValue)) {
                                continue;
                            }
                            
                            // Verify the field exists and belongs to this service
                            $fieldExists = DB::table('service_input_field')
                                ->where('InputFieldID', $inputFieldId)
                                ->where('ServiceID', $serviceId)
                                ->exists();
                            
                            if (!$fieldExists) {
                                continue;
                            }
                            
                            // Insert the answer
                            DB::table('AppointmentInputAnswer')->insert([
                                'AppointmentID' => $appointmentId,
                                'InputFieldID' => $inputFieldId,
                                'AnswerText' => is_array($answerValue) ? json_encode($answerValue) : $answerValue,
                                'created_at' => now(),
                                'updated_at' => now()
                            ]);
                        }
                    }
                }
                
                // Reserve the slot
                $this->adjustRemainingSlots($scheduleTimeId, $slotDate, -1, $schedule->SlotCapacity);
                
                // CRITICAL FIX: Always use the actual schedule fees instead of trusting PayMongo amount
                // Get the actual fees for this schedule
                $actualFees = ScheduleFee::where('ScheduleID', $scheduleId)->get();
                $actualTotalAmount = $actualFees->sum('Fee');
                
                // Apply member discount to get the correct amount
                $amountPaid = $this->applyMemberDiscount($actualTotalAmount, $service, $user, $churchId);
                
                \Log::info('Payment Success - Using Correct Service and Amount', [
                    'checkout_session_id' => $checkoutSessionId,
                    'user_id' => $user->id,
                    'service_id' => $serviceId,
                    'service_name' => $service->ServiceName,
                    'schedule_id' => $scheduleId,
                    'actual_fees' => $actualTotalAmount,
                    'final_amount' => $amountPaid
                ]);
                
                $paymentMethod = $sessionData['attributes']['payment_method_used'] ?? 'multi';
                
                // Use receipt code from metadata (generated before checkout)
                $receiptCode = $metadata['receipt_code'] ?? null;
                
                $transaction = ChurchTransaction::create([
                    'user_id' => $user->id,
                    'church_id' => $churchId,
                    'appointment_id' => $appointmentId,
                    'paymongo_session_id' => $checkoutSessionId,
                    'receipt_code' => $receiptCode,
                    'payment_method' => $paymentMethod,
                    'amount_paid' => $amountPaid,
                    'currency' => 'PHP',
                    'transaction_type' => 'appointment_payment',
                    'transaction_date' => now(),
                    'notes' => sprintf(
                        '%s appointment payment for %s - %s on %s',
                        ucfirst($paymentMethod),
                        $church->ChurchName,
                        $service->ServiceName,
                        $appointmentDate
                    ),
                    'metadata' => [
                        'church_name' => $church->ChurchName,
                        'service_name' => $service->ServiceName,
                        'appointment_date' => $appointmentDate,
                        'schedule_time' => $scheduleTimeId,
                        'original_session_data' => $metadata
                    ]
                ]);
                
                // Create notification for church staff
                $notification = $this->createAppointmentNotification(
                    $churchId,
                    $appointmentId,
                    $user,
                    $service,
                    $appointmentDate
                );
                
                // Load the full appointment to broadcast
                $appointment = Appointment::find($appointmentId);
                
                // Broadcast event to church staff
                event(new AppointmentCreated($appointment, $churchId, $notification));
                // Also notify applicant user channel
                if ($notification) {
                    event(new NotificationCreated($user->id, $notification));
                }
                
                DB::commit();
                
                Log::info('Appointment created after successful payment', [
                    'appointment_id' => $appointmentId,
                    'transaction_id' => $transaction->ChurchTransactionID,
                    'user_id' => $user->id,
                    'checkout_session_id' => $checkoutSessionId,
                    'church_id' => $churchId,
                    'service_id' => $serviceId
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Your appointment has been created successfully after payment confirmation.',
                    'redirect_url' => env('FRONTEND_URL', 'http://localhost:3000') . '/payment/success?transaction_id=' . $transaction->ChurchTransactionID . '&type=appointment',
                    'appointment' => [
                        'id' => $appointmentId,
                        'church_name' => $church->ChurchName,
                        'service_name' => $service->ServiceName,
                        'appointment_date' => $appointmentDate,
                        'status' => 'Pending'
                    ],
                    'transaction' => [
                        'id' => $transaction->ChurchTransactionID,
                        'amount_paid' => $transaction->amount_paid,
                        'currency' => $transaction->currency,
                        'payment_method' => $transaction->payment_method,
                        'transaction_date' => $transaction->transaction_date->format('F j, Y g:i A')
                    ]
                ], 201);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Error handling payment success', [
                'checkout_session_id' => $request->checkout_session_id ?? 'unknown',
                'user_id' => $user->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'An error occurred while processing your payment confirmation.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extract field ID from field name
     */
    private function extractFieldId(string $fieldName): ?int
    {
        // Try to extract numeric ID from various field name formats
        if (is_numeric($fieldName)) {
            return (int) $fieldName;
        }
        
        // Handle "field_123" format
        if (preg_match('/field[_-]?(\d+)/', $fieldName, $matches)) {
            return (int) $matches[1];
        }
        
        // Handle other numeric patterns
        if (preg_match('/\d+/', $fieldName, $matches)) {
            return (int) $matches[0];
        }
        
        return null;
    }

    /**
     * Apply member discount to fee amount if user is an approved member
     */
    private function applyMemberDiscount(float $originalAmount, $service, $user, int $churchId): float
    {
        // Check if service has discount configured
        if (!$service->member_discount_type || !$service->member_discount_value) {
            return $originalAmount;
        }

        // Check if user is an approved member of this specific church
        try {
            $membership = \App\Models\ChurchMember::where('user_id', $user->id)
                ->where('church_id', $churchId)
                ->where('status', 'approved')
                ->first();

            if (!$membership) {
                return $originalAmount; // Not an approved member
            }

            // Apply discount
            $discountValue = floatval($service->member_discount_value);
            if ($discountValue <= 0) {
                return $originalAmount;
            }

            if ($service->member_discount_type === 'percentage') {
                $discount = ($originalAmount * $discountValue) / 100;
                return max(0, $originalAmount - $discount); // Don't go below 0
            } else if ($service->member_discount_type === 'fixed') {
                return max(0, $originalAmount - $discountValue); // Don't go below 0
            }

        } catch (\Exception $e) {
            \Log::warning('Error checking membership for discount', [
                'user_id' => $user->id,
                'church_id' => $churchId,
                'error' => $e->getMessage()
            ]);
        }

        return $originalAmount;
    }

    /**
     * Handle appointment payment success callback (GET route from PayMongo)
     */
    public function handleAppointmentPaymentSuccess(Request $request)
    {
        $sessionId = $request->query('session_id');
        $churchId = $request->query('church_id'); // Pass church_id in the success URL
        
        if (!$sessionId) {
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard?error=missing_session');
        }

        if (!$churchId) {
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard?error=missing_church_id');
        }

        // Handle PayMongo template placeholder - find recent transaction
        if ($sessionId === '{CHECKOUT_SESSION_ID}') {
            Log::warning('PayMongo placeholder received, finding ChurchTransaction', ['church_id' => $churchId]);
            
            // Find most recent pending transaction
            $transaction = ChurchTransaction::where('status', 'pending')
                ->where('church_id', $churchId)
                ->where('transaction_type', 'appointment_payment')
                ->orderBy('created_at', 'desc')
                ->first();
            
            Log::info('ChurchTransaction search results', [
                'pending_transaction_found' => $transaction ? true : false,
                'session_id' => $transaction ? $transaction->paymongo_session_id : null,
                'church_id' => $churchId
            ]);
            
            if (!$transaction) {
                // Try paid transactions from last 10 minutes
                $transaction = ChurchTransaction::where('status', 'paid')
                    ->where('church_id', $churchId)
                    ->where('transaction_type', 'appointment_payment')
                    ->where('updated_at', '>', now()->subMinutes(10))
                    ->orderBy('updated_at', 'desc')
                    ->first();
                    
                Log::info('Fallback paid transaction search', [
                    'paid_transaction_found' => $transaction ? true : false,
                    'session_id' => $transaction ? $transaction->paymongo_session_id : null
                ]);
            }
            
            if ($transaction) {
                // For placeholder sessions, try to determine actual payment method from PayMongo
                $paymentMethod = 'gcash'; // Default to gcash as fallback
                
                try {
                    $paymongoService = new PayMongoService($churchId);
                    $sessionResult = $paymongoService->getCheckoutSession($transaction->paymongo_session_id);
                    
                    if ($sessionResult['success']) {
                        $sessionData = $sessionResult['data'];
                        $attributes = $sessionData['attributes'] ?? [];
                        $paymentMethod = $this->getActualPaymentMethod($attributes);
                    }
                } catch (\Exception $e) {
                    Log::warning('Could not fetch PayMongo session for payment method detection', [
                        'session_id' => $transaction->paymongo_session_id,
                        'error' => $e->getMessage()
                    ]);
                }
                
                $transaction->update(['payment_method' => $paymentMethod]);
                
                $finalTransaction = $this->createAppointmentAndTransaction($transaction);
                if ($finalTransaction) {
                    return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/payment/success?transaction_id=' . $finalTransaction->ChurchTransactionID . '&type=appointment');
                }
            }
            
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard?error=session_processing_failed');
        }

        try {
            // Find ChurchTransaction by session ID
            $transaction = ChurchTransaction::where('paymongo_session_id', $sessionId)
                ->where('church_id', $churchId)
                ->first();
            
            if (!$transaction) {
                Log::warning('ChurchTransaction not found', ['session_id' => $sessionId]);
                return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard?error=session_not_found');
            }
            
            // Verify payment with PayMongo
            $paymongoService = new PayMongoService($churchId);
            $sessionResult = $paymongoService->getCheckoutSession($sessionId);
            
            if ($sessionResult['success']) {
                $sessionData = $sessionResult['data'];
                $attributes = $sessionData['attributes'] ?? [];
                $paymentStatus = $attributes['payment_status'] ?? $attributes['status'] ?? null;
                
                if ($paymentStatus === 'paid') {
                    // Get actual payment method used
                    $paymentMethodUsed = $this->getActualPaymentMethod($attributes);
                    
                    // Update transaction with actual payment method
                    $transaction->update(['payment_method' => $paymentMethodUsed, 'status' => 'paid']);
                    
                    $finalTransaction = $this->createAppointmentAndTransaction($transaction);
                    if ($finalTransaction) {
                        return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/payment/success?transaction_id=' . $finalTransaction->ChurchTransactionID . '&type=appointment');
                    }
                } else {
                    // Mark transaction as failed/expired
                    $newStatus = ($paymentStatus === 'expired') ? 'expired' : 'failed';
                    $transaction->update(['status' => $newStatus]);
                }
            }
            
            Log::warning('Payment verification failed or not paid', ['session_id' => $sessionId]);
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard?error=payment_verification_failed');
        } catch (\Exception $e) {
            Log::error('Error in appointment payment success callback', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'church_id' => $churchId
            ]);

            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard?error=payment_processing');
        }
    }

    /**
     * Handle appointment payment cancel callback
     */
    public function handleAppointmentPaymentCancel(Request $request)
    {
        try {
            $sessionId = $request->query('session_id');
            $churchId = $request->query('church_id');

            $appointmentSession = null;

            if ($sessionId === '{CHECKOUT_SESSION_ID}') {
                // Fallback: latest pending session for this church
                if ($churchId) {
                    $appointmentSession = AppointmentPaymentSession::where('church_id', $churchId)
                        ->where('status', 'pending')
                        ->orderBy('created_at', 'desc')
                        ->first();
                }
            } elseif ($sessionId) {
                $appointmentSession = AppointmentPaymentSession::where('paymongo_session_id', $sessionId)->first();
            }

            if ($appointmentSession && $appointmentSession->status !== 'paid') {
                $appointmentSession->update(['status' => 'cancelled']);
                \Log::info('Appointment payment session cancelled', [
                    'session_id' => $appointmentSession->paymongo_session_id,
                    'church_id' => $appointmentSession->church_id,
                    'user_id' => $appointmentSession->user_id,
                ]);
            }
        } catch (\Exception $e) {
            \Log::warning('Error marking appointment payment session as cancelled', [
                'error' => $e->getMessage()
            ]);
        }

        return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard?info=payment_cancelled');
    }

    /**
     * Process appointment creation from PayMongo session
     */
    private function processAppointmentFromSession($sessionId, $churchId, $sessionData)
    {
        try {
            $attributes = $sessionData['attributes'] ?? [];
            $metadata = $attributes['metadata'] ?? [];
            
            $userId = $metadata['user_id'] ?? null;
            $serviceId = $metadata['service_id'] ?? null;
            $scheduleId = $metadata['schedule_id'] ?? null;
            $scheduleTimeId = $metadata['schedule_time_id'] ?? null;
            $appointmentDate = $metadata['appointment_date'] ?? null;
            $formData = $metadata['form_data'] ?? null;

            if (!$userId || !$serviceId || !$scheduleId || !$scheduleTimeId || !$appointmentDate) {
                Log::warning('Missing required metadata for appointment creation', [
                    'session_id' => $sessionId,
                    'metadata' => $metadata
                ]);
                return null;
            }

            // Check if an appointment was already created for this payment session
            $existingTransactionForSession = ChurchTransaction::where('paymongo_session_id', $sessionId)
                ->whereNotNull('appointment_id')
                ->first();
            
            if ($existingTransactionForSession) {
                Log::info('Appointment already exists for this payment session', [
                    'session_id' => $sessionId,
                    'appointment_id' => $existingTransactionForSession->appointment_id,
                    'transaction_id' => $existingTransactionForSession->ChurchTransactionID
                ]);
                return $existingTransactionForSession;
            }
            
            // Check if appointment already exists (legacy check for backward compatibility)
            $existingAppointment = DB::table('Appointment')
                ->where('UserID', $userId)
                ->where('ServiceID', $serviceId)
                ->where('ScheduleID', $scheduleId)
                ->where('ScheduleTimeID', $scheduleTimeId)
                ->whereDate('AppointmentDate', $appointmentDate)
                ->first();

            if ($existingAppointment) {
                Log::warning('Duplicate appointment detected - linking to existing appointment', [
                    'session_id' => $sessionId,
                    'appointment_id' => $existingAppointment->AppointmentID
                ]);
                // Find existing transaction
                return ChurchTransaction::where('appointment_id', $existingAppointment->AppointmentID)->first();
            }

            DB::beginTransaction();

            // Get related entities
            $church = Church::find($churchId);
            $service = SacramentService::find($serviceId);
            $schedule = ServiceSchedule::find($scheduleId);

            if (!$church || !$service || !$schedule) {
                throw new \Exception('Required entities not found');
            }

            // Create appointment
            $appointmentId = DB::table('Appointment')->insertGetId([
                'UserID' => $userId,
                'ChurchID' => $churchId,
                'ServiceID' => $serviceId,
                'ScheduleID' => $scheduleId,
                'ScheduleTimeID' => $scheduleTimeId,
                'AppointmentDate' => $appointmentDate,
                'Status' => 'Pending',
                'Notes' => 'Appointment created after successful payment via PayMongo - awaiting approval',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Save form data if provided
            if ($formData) {
                $formDataArray = json_decode($formData, true);
                if ($formDataArray) {
                    foreach ($formDataArray as $fieldKey => $answerValue) {
                        $inputFieldId = $this->extractFieldId($fieldKey);
                        
                        if (!$inputFieldId || empty($answerValue)) {
                            continue;
                        }
                        
                        $fieldExists = DB::table('service_input_field')
                            ->where('InputFieldID', $inputFieldId)
                            ->where('ServiceID', $serviceId)
                            ->exists();
                        
                        if (!$fieldExists) {
                            continue;
                        }
                        
                        DB::table('AppointmentInputAnswer')->insert([
                            'AppointmentID' => $appointmentId,
                            'InputFieldID' => $inputFieldId,
                            'AnswerText' => is_array($answerValue) ? json_encode($answerValue) : $answerValue,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                }
            }

            // Reserve slot
            $slotDate = \Carbon\Carbon::parse($appointmentDate)->format('Y-m-d');
            $this->adjustRemainingSlots($scheduleTimeId, $slotDate, -1, $schedule->SlotCapacity);
            
            // Create transaction record
            $amountPaid = ($attributes['amount'] ?? 0) / 100;
            $paymentMethod = $attributes['payment_method_used'] ?? 'multi';
            $user = \App\Models\User::find($userId);
            
            $transaction = ChurchTransaction::create([
                'user_id' => $userId,
                'church_id' => $churchId,
                'appointment_id' => $appointmentId,
                'receipt_code' => $this->generateReceiptCode(),
                'paymongo_session_id' => $sessionId,
                'payment_method' => $paymentMethod,
                'amount_paid' => $amountPaid,
                'currency' => 'PHP',
                'transaction_type' => 'appointment_payment',
                'transaction_date' => now(),
                'notes' => sprintf(
                    '%s appointment payment for %s - %s on %s',
                    ucfirst($paymentMethod === 'multi' ? 'GCash' : $paymentMethod),
                    $church->ChurchName,
                    $service->ServiceName,
                    $appointmentDate
                ),
                'metadata' => [
                    'church_name' => $church->ChurchName,
                    'service_name' => $service->ServiceName,
                    'appointment_date' => $appointmentDate,
                    'schedule_time' => $scheduleTimeId,
                    'is_mass' => $service->isMass ?? false,
                    'original_session_data' => $metadata
                ]
            ]);

            DB::commit();

            Log::info('Appointment and transaction created from callback', [
                'appointment_id' => $appointmentId,
                'transaction_id' => $transaction->ChurchTransactionID,
                'session_id' => $sessionId
            ]);

            return $transaction;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing appointment from session', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'church_id' => $churchId
            ]);
            return null;
        }
    }
    
    /**
     * Process appointment payment success (called from unified payment handler)
     */
    public function processAppointmentPaymentSuccess($sessionId, $sessionData)
    {
        try {
            $metadata = $sessionData['attributes']['metadata'] ?? [];
            $churchId = $metadata['church_id'] ?? null;
            
            if (!$churchId) {
                Log::error('No church_id in appointment payment metadata', ['session_id' => $sessionId]);
                return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard?error=missing_church_id');
            }
            
            // Use the existing processAppointmentFromSession method
            $transaction = $this->processAppointmentFromSession($sessionId, $churchId, $sessionData);
            
            if ($transaction) {
                Log::info('Appointment payment processed successfully', [
                    'session_id' => $sessionId,
                    'transaction_id' => $transaction->ChurchTransactionID
                ]);
                return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/payment/success?transaction_id=' . $transaction->ChurchTransactionID . '&type=appointment&session_id=' . $sessionId);
            } else {
                Log::warning('Failed to create appointment transaction', ['session_id' => $sessionId]);
                return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard?error=appointment_creation_failed');
            }
        } catch (\Exception $e) {
            Log::error('Error processing appointment payment success', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId
            ]);
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard?error=payment_processing_failed');
        }
    }
    
    /**
     * Create both Appointment and ChurchTransaction from PaymentSession data
     */
    private function createAppointmentFromPaymentSession(PaymentSession $paymentSession)
    {
        $metadata = $paymentSession->metadata ?? [];
        
        try {
            DB::beginTransaction();
            
            // Extract appointment details from metadata
            $userId = $paymentSession->user_id;
            $churchId = $metadata['church_id'] ?? null;
            $serviceId = $metadata['service_id'] ?? null;
            $scheduleId = $metadata['schedule_id'] ?? null;
            $scheduleTimeId = $metadata['schedule_time_id'] ?? null;
            $appointmentDate = $metadata['appointment_date'] ?? null;
            $formData = $metadata['form_data'] ?? null;
            
            if (!$churchId || !$serviceId || !$scheduleId || !$scheduleTimeId || !$appointmentDate) {
                Log::error('Missing required metadata for appointment creation', [
                    'payment_session_id' => $paymentSession->id,
                    'metadata' => $metadata
                ]);
                return null;
            }
            
            // Check if appointment already exists
            $existingAppointment = DB::table('Appointment')
                ->where('UserID', $userId)
                ->where('ServiceID', $serviceId)
                ->where('ScheduleID', $scheduleId)
                ->where('ScheduleTimeID', $scheduleTimeId)
                ->whereDate('AppointmentDate', $appointmentDate)
                ->first();
            
            if (!$existingAppointment) {
                // Create appointment
                $appointmentId = DB::table('Appointment')->insertGetId([
                    'UserID' => $userId,
                    'ChurchID' => $churchId,
                    'ServiceID' => $serviceId,
                    'ScheduleID' => $scheduleId,
                    'ScheduleTimeID' => $scheduleTimeId,
                    'AppointmentDate' => $appointmentDate,
                    'Status' => 'Pending',
                    'Notes' => '',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                // Reserve slot
                $schedule = ServiceSchedule::find($scheduleId);
                if ($schedule) {
                    $slotDate = \Carbon\Carbon::parse($appointmentDate)->format('Y-m-d');
                    $this->adjustRemainingSlots($scheduleTimeId, $slotDate, -1, $schedule->SlotCapacity);
                }
            } else {
                $appointmentId = $existingAppointment->AppointmentID;
            }
            
            // Create ChurchTransaction record
            $transaction = ChurchTransaction::create([
                'user_id' => $userId,
                'church_id' => $churchId,
                'appointment_id' => $appointmentId,
                'receipt_code' => $this->generateReceiptCode(),
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
                    'appointment_date' => $appointmentDate,
                    'payment_status' => 'completed'
                ]
            ]);
            
            // Update payment session status
            $paymentSession->update(['status' => 'paid']);
            
            DB::commit();
            
            Log::info('Appointment and Transaction created from PaymentSession', [
                'appointment_id' => $appointmentId,
                'transaction_id' => $transaction->ChurchTransactionID,
                'payment_session_id' => $paymentSession->id
            ]);
            
            return $transaction;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating appointment from PaymentSession', [
                'error' => $e->getMessage(),
                'payment_session_id' => $paymentSession->id,
                'metadata' => $metadata
            ]);
            return null;
        }
    }
    
    /**
     * Create ChurchTransaction from PaymentSession data
     */
    private function createTransactionFromPaymentSession(PaymentSession $paymentSession)
    {
        $metadata = $paymentSession->metadata ?? [];
        
        // Update payment session status
        $paymentSession->update(['status' => 'paid']);
        
        // Create ChurchTransaction record
        $transaction = ChurchTransaction::create([
            'user_id' => $paymentSession->user_id,
            'church_id' => $metadata['church_id'] ?? 1,
            'appointment_id' => null,
            'receipt_code' => $this->generateReceiptCode(),
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
        
        return $transaction;
    }
    
    /**
     * Get appointment transaction details
     */
    public function getAppointmentTransactionDetails(Request $request, $transactionId)
    {
        try {
            Log::info('Looking for transaction', [
                'transaction_id' => $transactionId,
                'auth_user_id' => auth()->id(),
                'all_transactions' => ChurchTransaction::pluck('ChurchTransactionID', 'user_id')->toArray()
            ]);
            
            $transaction = ChurchTransaction::with([
                'church',
                'user.profile',
                'user.contact',
                'appointment.service',
                'service',
                'schedule'
            ])
                ->where('ChurchTransactionID', $transactionId)
                ->where('transaction_type', 'appointment_payment')
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found'
                ], 404);
            }

            // Get schedule time info if available
            $scheduleTime = null;
            if ($transaction->schedule_time_id) {
                $scheduleTime = DB::table('schedule_times')
                    ->where('ScheduleTimeID', $transaction->schedule_time_id)
                    ->first();
            }
            
            // Get sub-sacrament service info if applicable
            $subSacramentService = null;
            if ($transaction->schedule && $transaction->schedule->SubSacramentServiceID) {
                $subSacramentService = DB::table('sub_sacrament_services')
                    ->where('SubSacramentServiceID', $transaction->schedule->SubSacramentServiceID)
                    ->first();
            }

            $isMass = false;
            if ($transaction->appointment && $transaction->appointment->service) {
                $isMass = $transaction->appointment->service->isMass ?? false;
            } else if (isset($transaction->metadata['is_mass'])) {
                $isMass = $transaction->metadata['is_mass'];
            }
            
            // Format user details
            $userDetails = null;
            if ($transaction->user) {
                $userDetails = [
                    'name' => optional($transaction->user->profile)->first_name . ' ' . optional($transaction->user->profile)->last_name,
                    'email' => $transaction->user->email,
                    'phone' => optional($transaction->user->contact)->phone_number,
                ];
            }
            
            // Format appointment details
            $appointmentDetails = null;
            if ($transaction->appointment) {
                $appointmentDetails = [
                    'id' => $transaction->appointment->AppointmentID,
                    'date' => $transaction->appointment->AppointmentDate ? \Carbon\Carbon::parse($transaction->appointment->AppointmentDate)->format('F j, Y') : null,
                    'time' => $scheduleTime ? (date('g:i A', strtotime($scheduleTime->StartTime)) . ' - ' . date('g:i A', strtotime($scheduleTime->EndTime))) : null,
                    'status' => $transaction->appointment->Status,
                    'notes' => $transaction->appointment->Notes,
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    // Transaction basics
                    'id' => $transaction->ChurchTransactionID,
                    'receipt_code' => $transaction->receipt_code,
                    'receipt_number' => (string) $transaction->ChurchTransactionID,
                    'transaction_date' => $transaction->transaction_date->toISOString(),
                    'formatted_date' => $transaction->transaction_date->format('F j, Y g:i A'),
                    
                    // Payment details
                    'amount_paid' => (float) $transaction->amount_paid,
                    'currency' => $transaction->currency,
                    'payment_method' => $transaction->payment_method,
                    'payment_method_display' => $transaction->payment_method === 'card' ? 'Card' : ucfirst($transaction->payment_method),
                    'status' => $transaction->status,
                    'paymongo_session_id' => $transaction->paymongo_session_id,
                    
                    // Church details
                    'church' => [
                        'id' => $transaction->church->ChurchID,
                        'name' => $transaction->church->ChurchName,
                        'address' => trim(implode(', ', array_filter([
                            $transaction->church->Street,
                            $transaction->church->City,
                            $transaction->church->Province
                        ]))),
                    ],
                    
                    // Service details
                    'service' => [
                        'id' => $transaction->service_id,
                        'name' => $transaction->service ? $transaction->service->ServiceName : ($transaction->appointment ? $transaction->appointment->service->ServiceName : 'N/A'),
                        'variant' => $subSacramentService ? $subSacramentService->SubServiceName : null,
                        'is_mass' => $isMass,
                    ],
                    
                    // User details
                    'user' => $userDetails,
                    
                    // Appointment details
                    'appointment' => $appointmentDetails,
                    
                    // Additional metadata
                    'metadata' => $transaction->metadata,
                    'notes' => $transaction->notes,
                    
                    // Full transaction object for backward compatibility
                    'transaction' => $transaction,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch appointment transaction details', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transaction details'
            ], 500);
        }
    }

    /**
     * Get appointment transaction by PayMongo session id
     */
    public function getAppointmentTransactionBySession(Request $request)
    {
        $sessionId = $request->query('session_id');
        if (!$sessionId) {
            return response()->json(['success' => false, 'message' => 'Missing session_id'], 400);
        }
        $transaction = ChurchTransaction::with(['church', 'user', 'appointment.service'])
            ->where('paymongo_session_id', $sessionId)
            ->where('transaction_type', 'appointment_payment')
            ->first();
        if (!$transaction) {
            return response()->json(['success' => false, 'message' => 'Transaction not found'], 404);
        }
        $isMass = false;
        if ($transaction->appointment && $transaction->appointment->service) {
            $isMass = $transaction->appointment->service->isMass ?? false;
        } else if (isset($transaction->metadata['is_mass'])) {
            $isMass = $transaction->metadata['is_mass'];
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'transaction' => $transaction,
                'receipt_number' => (string) $transaction->ChurchTransactionID,
                'receipt_code' => $transaction->receipt_code, // expose directly for frontend
                'formatted_date' => $transaction->transaction_date->format('F j, Y g:i A'),
                'church_name' => optional($transaction->church)->ChurchName,
                'service_name' => optional($transaction->appointment)->service ? $transaction->appointment->service->ServiceName : 'N/A',
                'payment_method_display' => $transaction->payment_method === 'card' ? 'Card' : ucfirst($transaction->payment_method),
                'is_mass' => $isMass,
            ]
        ]);
    }

    /**
     * Download appointment receipt
     */
    public function downloadAppointmentReceipt(Request $request, $transactionId)
    {
        try {
            $transaction = ChurchTransaction::with(['church', 'user', 'appointment.service'])
                ->where('ChurchTransactionID', $transactionId)
                ->where('user_id', auth()->id())
                ->where('transaction_type', 'appointment_payment')
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found'
                ], 404);
            }

            $receiptNumber = (string) $transaction->ChurchTransactionID;
            $paymentMethod = $transaction->payment_method === 'card' ? 'Card' : ucfirst($transaction->payment_method);

            $data = [
                'brand' => 'FAITHSEEKER',
                'receiptNumber' => $receiptNumber,
                'formattedDate' => $transaction->transaction_date->format('F j, Y g:i A'),
                'transaction' => $transaction,
                'church' => $transaction->church,
                'service' => $transaction->appointment ? $transaction->appointment->service->ServiceName : 'N/A',
                'appointmentDate' => $transaction->appointment ? $transaction->appointment->AppointmentDate->format('F j, Y g:i A') : null,
                'amount' => $transaction->amount_paid,
                'paymentMethod' => $paymentMethod,
                'user' => $transaction->user,
            ];

            $pdf = Pdf::loadView('receipts.appointment', $data)->setPaper('a4');
            return $pdf->download('appointment-receipt-' . $receiptNumber . '.pdf');
        } catch (\Exception $e) {
            Log::error('Failed to generate appointment receipt', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate receipt'
            ], 500);
        }
    }

    /**
     * Create appointment and update ChurchTransaction
     */
    private function createAppointmentAndTransaction(ChurchTransaction $transaction)
    {
        try {
            DB::beginTransaction();
            
            // Refresh transaction to get latest state (prevent race conditions)
            $transaction->refresh();
            
            // Check if appointment was already created
            if ($transaction->appointment_id) {
                Log::info('Appointment already exists for this transaction', [
                    'appointment_id' => $transaction->appointment_id,
                    'transaction_id' => $transaction->ChurchTransactionID
                ]);
                DB::commit();
                return $transaction;
            }
            
            // Check if another appointment already exists for the same payment session
            $existingAppointmentForSession = ChurchTransaction::where('paymongo_session_id', $transaction->paymongo_session_id)
                ->whereNotNull('appointment_id')
                ->where('ChurchTransactionID', '!=', $transaction->ChurchTransactionID)
                ->first();
            
            if ($existingAppointmentForSession) {
                Log::warning('Duplicate appointment creation prevented - another appointment exists for this payment session', [
                    'current_transaction_id' => $transaction->ChurchTransactionID,
                    'existing_transaction_id' => $existingAppointmentForSession->ChurchTransactionID,
                    'existing_appointment_id' => $existingAppointmentForSession->appointment_id,
                    'paymongo_session_id' => $transaction->paymongo_session_id
                ]);
                
                // Link this transaction to the existing appointment to maintain consistency
                $transaction->update([
                    'appointment_id' => $existingAppointmentForSession->appointment_id,
                    'status' => 'paid',
                    'notes' => sprintf(
                        '[Ref: %s] Duplicate payment session - linked to existing appointment #%s',
                        $transaction->receipt_code ?? 'N/A',
                        $existingAppointmentForSession->appointment_id
                    )
                ]);
                
                DB::commit();
                return $transaction;
            }
            
            // Create new appointment
            $appointmentId = DB::table('Appointment')->insertGetId([
                'UserID' => $transaction->user_id,
                'ChurchID' => $transaction->church_id,
                'ServiceID' => $transaction->service_id,
                'ScheduleID' => $transaction->schedule_id,
                'ScheduleTimeID' => $transaction->schedule_time_id,
                'AppointmentDate' => $transaction->appointment_date,
                'Status' => 'Pending',
                'Notes' => '',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            // Persist form data answers captured before redirect (if any)
            $sessionFormData = [];
            
            if (isset($transaction->metadata['form_data'])) {
                $decoded = json_decode($transaction->metadata['form_data'], true);
                if (is_array($decoded)) { $sessionFormData = $decoded; }
            }
            
            if (!empty($sessionFormData)) {
                foreach ($sessionFormData as $fieldKey => $answerValue) {
                    $inputFieldId = $this->extractFieldId($fieldKey);
                    if (!$inputFieldId || empty($answerValue)) { continue; }
                    
                    $fieldExists = \DB::table('service_input_field')
                        ->where('InputFieldID', $inputFieldId)
                        ->where('ServiceID', $transaction->service_id)
                        ->exists();
                    if (!$fieldExists) { continue; }
                    
                    \DB::table('AppointmentInputAnswer')->insert([
                        'AppointmentID' => $appointmentId,
                        'InputFieldID' => $inputFieldId,
                        'AnswerText' => is_array($answerValue) ? json_encode($answerValue) : $answerValue,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            // Reserve slot
            $schedule = ServiceSchedule::find($transaction->schedule_id);
            if ($schedule) {
                $slotDate = \Carbon\Carbon::parse($transaction->appointment_date)->format('Y-m-d');
                $this->adjustRemainingSlots($transaction->schedule_time_id, $slotDate, -1, $schedule->SlotCapacity);
            }
            
            // Update transaction with appointment ID and mark as paid
            $transaction->update([
                'appointment_id' => $appointmentId,
                'status' => 'paid',
                'transaction_date' => now(),
                'notes' => sprintf(
                    '[Ref: %s] %s payment for %s - %s appointment on %s',
                    $transaction->receipt_code ?? 'N/A',
                    $transaction->payment_method === 'card' ? 'Card' : ucfirst($transaction->payment_method),
                    $transaction->metadata['church_name'] ?? optional($transaction->church)->ChurchName,
                    $transaction->metadata['service_name'] ?? optional($transaction->service)->ServiceName,
                    \Carbon\Carbon::parse($transaction->appointment_date)->format('Y-m-d')
                )
            ]);
            
            // Create notification for church staff
            $user = User::find($transaction->user_id);
            $service = SacramentService::find($transaction->service_id);
            
            if ($user && $service) {
                $notification = $this->createAppointmentNotification(
                    $transaction->church_id,
                    $appointmentId,
                    $user,
                    $service,
                    $transaction->appointment_date
                );
                
                // Load the full appointment to broadcast
                $appointment = Appointment::find($appointmentId);
                
                // Broadcast event to church staff
                if ($appointment && $notification) {
                    event(new AppointmentCreated($appointment, $transaction->church_id, $notification));
                }
            }
            
            DB::commit();
            
            Log::info('Appointment and transaction created successfully', [
                'appointment_id' => $appointmentId,
                'transaction_id' => $transaction->ChurchTransactionID,
                'paymongo_session_id' => $transaction->paymongo_session_id
            ]);
            
            return $transaction;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating appointment and transaction', [
                'error' => $e->getMessage(),
                'session_id' => $appointmentSession->id
            ]);
            return null;
        }
    }
    
    /**
     * Create appointment from recent PayMongo payment (when session ID is placeholder)
     */
    public function createAppointmentFromRecentPayment(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['error' => 'Authentication required.'], 401);
            }

            // Get the church_id from request
            $churchId = $request->church_id;
            if (!$churchId) {
                return response()->json(['error' => 'Church ID required.'], 400);
            }

            // Get appointment data from localStorage that was stored before payment
            $appointmentData = $request->appointment_data;
            if (!$appointmentData) {
                return response()->json(['error' => 'No appointment data found.'], 400);
            }

            // Validate appointment data
            $validator = Validator::make($appointmentData, [
                'church_id' => 'required|integer',
                'service_id' => 'required|integer', 
                'schedule_id' => 'required|integer',
                'schedule_time_id' => 'required|integer',
                'appointment_date' => 'required|string',
                'church_name' => 'required|string',
                'service_name' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => 'Invalid appointment data.', 'errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            try {
                // Note: Removed duplicate appointment check to allow multiple appointments
                // for the same user at the same time/date (e.g., booking multiple children for baptism)
                // Slot availability is still enforced to prevent overbooking

                // Create appointment
                $appointmentId = DB::table('Appointment')->insertGetId([
                    'UserID' => $user->id,
                    'ChurchID' => $appointmentData['church_id'],
                    'ServiceID' => $appointmentData['service_id'],
                    'ScheduleID' => $appointmentData['schedule_id'],
                    'ScheduleTimeID' => $appointmentData['schedule_time_id'],
                    'AppointmentDate' => $appointmentData['appointment_date'],
                    'Status' => 'Pending',
                    'Notes' => '',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                // Reserve slot
                $schedule = ServiceSchedule::find($appointmentData['schedule_id']);
                if ($schedule) {
                    $slotDate = \Carbon\Carbon::parse($appointmentData['appointment_date'])->format('Y-m-d');
                    $this->adjustRemainingSlots($appointmentData['schedule_time_id'], $slotDate, -1, $schedule->SlotCapacity);
                }

                // Create transaction (estimate amount from recent fees)
                $fees = ScheduleFee::where('ScheduleID', $appointmentData['schedule_id'])->get();
                $totalAmount = $fees->where('FeeType', 'Fee')->sum('Fee');

                $transaction = ChurchTransaction::create([
                    'user_id' => $user->id,
                    'church_id' => $appointmentData['church_id'],
                    'appointment_id' => $appointmentId,
                    'paymongo_session_id' => 'placeholder_' . time(), // Placeholder since we don't have the real session ID
                    'payment_method' => 'gcash', // Assume GCash since it's most common
                    'amount_paid' => $totalAmount,
                    'currency' => 'PHP',
                    'transaction_type' => 'appointment_payment',
                    'transaction_date' => now(),
                    'notes' => 'Appointment payment completed successfully via PayMongo',
                    'metadata' => [
                        'church_name' => $appointmentData['church_name'],
                        'service_name' => $appointmentData['service_name'],
                        'appointment_date' => $appointmentData['appointment_date'],
                        'payment_status' => 'completed',
                        'created_from_placeholder' => true
                    ]
                ]);

                DB::commit();

                Log::info('Appointment and transaction created from recent payment', [
                    'appointment_id' => $appointmentId,
                    'transaction_id' => $transaction->ChurchTransactionID,
                    'user_id' => $user->id
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Appointment created successfully.',
                    'redirect_url' => '/payment/success?transaction_id=' . $transaction->ChurchTransactionID . '&type=appointment'
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Error creating appointment from recent payment', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? 'unknown'
            ]);

            return response()->json([
                'error' => 'Failed to create appointment.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get actual payment method used from PayMongo session attributes
     */
    private function getActualPaymentMethod($attributes)
    {
        // Log the attributes for debugging
        Log::info('Detecting payment method from PayMongo attributes', [
            'payment_method_used' => $attributes['payment_method_used'] ?? 'not_set',
            'has_payments' => isset($attributes['payments']),
            'payment_status' => $attributes['payment_status'] ?? $attributes['status'] ?? 'unknown'
        ]);
        
        // Try payment_method_used first (most reliable for completed payments)
        if (isset($attributes['payment_method_used'])) {
            $method = strtolower($attributes['payment_method_used']);
            Log::info('Found payment_method_used', ['method' => $method]);
            return in_array($method, ['gcash', 'card']) ? $method : 'card';
        }
        
        // Try payments array for completed payments
        if (isset($attributes['payments']) && is_array($attributes['payments']) && count($attributes['payments']) > 0) {
            foreach ($attributes['payments'] as $payment) {
                if (isset($payment['attributes']['source']['type'])) {
                    $sourceType = strtolower($payment['attributes']['source']['type']);
                    Log::info('Found payment source type', ['source_type' => $sourceType]);
                    
                    if ($sourceType === 'gcash') return 'gcash';
                    if ($sourceType === 'card') return 'card';
                }
                
                // Also check payment method in payment attributes
                if (isset($payment['attributes']['payment_method'])) {
                    $paymentMethod = strtolower($payment['attributes']['payment_method']);
                    Log::info('Found payment method in payment', ['payment_method' => $paymentMethod]);
                    
                    if ($paymentMethod === 'gcash') return 'gcash';
                    if ($paymentMethod === 'card') return 'card';
                }
            }
        }
        
        // Try payment_intent for pending/processing payments
        if (isset($attributes['payment_intent'])) {
            $paymentIntent = $attributes['payment_intent'];
            
            // Check payment_method_allowed array
            if (isset($paymentIntent['attributes']['payment_method_allowed'])) {
                $methods = $paymentIntent['attributes']['payment_method_allowed'];
                Log::info('Found payment_method_allowed', ['methods' => $methods]);
                
                if (is_array($methods) && count($methods) > 0) {
                    $method = strtolower($methods[0]);
                    return in_array($method, ['gcash', 'card']) ? $method : 'card';
                }
            }
            
            // Check if there's a selected payment method
            if (isset($paymentIntent['attributes']['payment_method'])) {
                $method = strtolower($paymentIntent['attributes']['payment_method']);
                Log::info('Found payment method in payment intent', ['method' => $method]);
                return in_array($method, ['gcash', 'card']) ? $method : 'card';
            }
        }
        
        // Check line_items or description for hints
        if (isset($attributes['description'])) {
            $description = strtolower($attributes['description']);
            if (strpos($description, 'gcash') !== false) {
                Log::info('Detected GCash from description');
                return 'gcash';
            }
        }
        
        Log::warning('Could not determine payment method, defaulting to gcash');
        // Default fallback - gcash is more commonly used
        return 'gcash';
    }
    
    /**
     * Get church transactions for appointment payments
     */
    public function getChurchTransactions(Request $request, $churchName)
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
                    'message' => 'Church not found'
                ], 404);
            }
            
            // Get transactions with related data (include refunded transactions for display)
            $transactions = ChurchTransaction::with([
                'user.profile',
                'church',
                'appointment' => function($query) {
                    $query->with(['service', 'user.profile']);
                }
            ])
            ->where('church_id', $church->ChurchID)
            ->where('transaction_type', 'appointment_payment')
            ->orderBy('transaction_date', 'desc')
            ->get();
            
            return response()->json([
                'success' => true,
                'transactions' => $transactions
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to fetch church transactions', [
                'error' => $e->getMessage(),
                'church_name' => $churchName
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transactions'
            ], 500);
        }
    }
    
    /**
     * Create notification for church staff about new appointment
     */
    private function createAppointmentNotification($churchId, $appointmentId, $user, $service, $appointmentDateTime)
    {
        try {
            // Get church owner to notify
            $church = Church::find($churchId);
            if (!$church) {
                return null;
            }

            // Get user's full name from profile
            $userName = 'Unknown User';
            if ($user->profile) {
                $userName = trim(($user->profile->first_name ?? '') . ' ' . ($user->profile->last_name ?? ''));
                if (empty($userName)) {
                    $userName = $user->email;
                }
            } else {
                $userName = $user->email;
            }
            
            // Get sub-services for this sacrament service
            $subServices = DB::table('sub_service')
                ->where('ServiceID', $service->ServiceID)
                ->where('IsActive', true)
                ->get();
            
            $subServiceData = [];
            foreach ($subServices as $subService) {
                // Get schedules for this sub-service
                $schedules = DB::table('sub_service_schedule')
                    ->where('SubServiceID', $subService->SubServiceID)
                    ->get();
                
                // Get requirements for this sub-service
                $requirements = DB::table('sub_service_requirements')
                    ->where('SubServiceID', $subService->SubServiceID)
                    ->orderBy('SortOrder')
                    ->get();
                
                $subServiceData[] = [
                    'id' => $subService->SubServiceID,
                    'name' => $subService->SubServiceName,
                    'description' => $subService->Description,
                    'schedules' => $schedules->map(function($schedule) {
                        return [
                            'day' => $schedule->DayOfWeek,
                            'time' => date('g:i A', strtotime($schedule->StartTime)) . ' - ' . date('g:i A', strtotime($schedule->EndTime)),
                            'occurrence' => $schedule->OccurrenceType,
                            'occurrence_value' => $schedule->OccurrenceValue
                        ];
                    })->toArray(),
                    'requirements' => $requirements->map(function($req) {
                        return [
                            'id' => $req->RequirementID,
                            'name' => $req->RequirementName,
                            'needed' => $req->isNeeded
                        ];
                    })->toArray()
                ];
            }
            
            // Create notification for church owner
            $notification = Notification::create([
                'user_id' => $church->user_id,
                'type' => 'appointment_created',
                'title' => 'New Appointment Request',
                'message' => sprintf(
                    '%s has requested an appointment for %s on %s',
                    $userName,
                    $service->ServiceName,
                    Carbon::parse($appointmentDateTime)->format('F j, Y g:i A')
                ),
                'data' => [
                    'appointment_id' => $appointmentId,
                    'church_id' => $churchId,
                    'service_id' => $service->ServiceID,
                    'user_id' => $user->id,
                    'user_name' => $userName,
                    'service_name' => $service->ServiceName,
                    'appointment_date' => $appointmentDateTime,
                    'sub_services' => $subServiceData,
                ],
            ]);
            // Broadcast to owner
            event(new NotificationCreated($church->user_id, $notification));

            // Create notification for the applicant (regular user)
            $userNotification = Notification::create([
                'user_id' => $user->id,
                'type' => 'appointment_submitted',
                'title' => 'Appointment Submitted',
                'message' => sprintf(
                    'Your appointment for %s at %s was submitted. Please prepare requirements within 72 hours.',
                    $service->ServiceName,
                    $church->ChurchName
                ),
                'data' => [
                    'appointment_id' => $appointmentId,
                    'church_id' => $churchId,
                    'service_id' => $service->ServiceID,
                    'service_name' => $service->ServiceName,
                    'appointment_date' => $appointmentDateTime,
                    'sub_services' => $subServiceData,
                ],
            ]);
            event(new NotificationCreated($user->id, $userNotification));

            // Also notify active staff members with view_appointments permission
            $staffMembers = \App\Models\UserChurchRole::where('ChurchID', $churchId)
                ->whereHas('role', function($query) {
                    $query->whereHas('permissions', function($permQuery) {
                        $permQuery->where('PermissionName', 'view_appointments');
                    });
                })
                ->get();

            foreach ($staffMembers as $staffRole) {
                $staffNotif = Notification::create([
                    'user_id' => $staffRole->user_id,
                    'type' => 'appointment_created',
                    'title' => 'New Appointment Request',
                    'message' => sprintf(
                        '%s has requested an appointment for %s on %s',
                        $userName,
                        $service->ServiceName,
                        Carbon::parse($appointmentDateTime)->format('F j, Y g:i A')
                    ),
                    'data' => [
                    'appointment_id' => $appointmentId,
                        'church_id' => $churchId,
                        'service_id' => $service->ServiceID,
                        'user_id' => $user->id,
                        'user_name' => $userName,
                        'service_name' => $service->ServiceName,
                        'appointment_date' => $appointmentDateTime,
                        'sub_services' => $subServiceData,
                    ],
                ]);
                event(new NotificationCreated($staffRole->user_id, $staffNotif));
            }

            return $notification;
        } catch (\Exception $e) {
            Log::error('Failed to create appointment notification', [
                'error' => $e->getMessage(),
                'appointment_id' => $appointmentId
            ]);
            return null;
        }
    }
    
    /**
     * Send notifications when appointment status changes
     */
    private function sendStatusChangeNotifications($appointmentId, $appointment, $newStatus, $oldStatus)
    {
        try {
            // Only send notifications for Approved or Cancelled status
            if (!in_array($newStatus, ['Approved', 'Cancelled', 'Rejected'])) {
                return;
            }
            
            // Get appointment with related data
            $appointmentData = \App\Models\Appointment::with(['user.profile', 'service', 'church'])
                ->where('AppointmentID', $appointmentId)
                ->first();
                
            if (!$appointmentData) {
                return;
            }
            
            $user = $appointmentData->user;
            $service = $appointmentData->service;
            $church = $appointmentData->church;
            $appointmentDateTime = Carbon::parse($appointment->AppointmentDate)->format('F j, Y g:i A');
            
            // Get sub-services for this sacrament service
            $subServices = DB::table('sub_service')
                ->where('ServiceID', $service->ServiceID)
                ->where('IsActive', true)
                ->get();
            
            $subServiceData = [];
            foreach ($subServices as $subService) {
                // Get schedules for this sub-service
                $schedules = DB::table('sub_service_schedule')
                    ->where('SubServiceID', $subService->SubServiceID)
                    ->get();
                
                // Get requirements for this sub-service
                $requirements = DB::table('sub_service_requirements')
                    ->where('SubServiceID', $subService->SubServiceID)
                    ->orderBy('SortOrder')
                    ->get();
                
                // Get completion status for this appointment's sub-service
                $status = DB::table('appointment_sub_service_status')
                    ->where('AppointmentID', $appointmentId)
                    ->where('SubServiceID', $subService->SubServiceID)
                    ->first();
                
                $subServiceData[] = [
                    'id' => $subService->SubServiceID,
                    'name' => $subService->SubServiceName,
                    'description' => $subService->Description,
                    'is_completed' => $status ? $status->isCompleted : false,
                    'schedules' => $schedules->map(function($schedule) {
                        return [
                            'day' => $schedule->DayOfWeek,
                            'time' => date('g:i A', strtotime($schedule->StartTime)) . ' - ' . date('g:i A', strtotime($schedule->EndTime)),
                            'occurrence' => $schedule->OccurrenceType,
                            'occurrence_value' => $schedule->OccurrenceValue
                        ];
                    })->toArray(),
                    'requirements' => $requirements->map(function($req) {
                        return [
                            'id' => $req->RequirementID,
                            'name' => $req->RequirementName,
                            'needed' => $req->isNeeded
                        ];
                    })->toArray()
                ];
            }
            
            // Prepare notification data based on status
            $notificationData = $this->prepareStatusNotificationData($newStatus, $service, $appointmentDateTime);
            
            // 1. Notify the user who owns the appointment
            $userNotification = Notification::create([
                'user_id' => $user->id,
                'type' => 'appointment_status_changed',
                'title' => $notificationData['title'],
                'message' => sprintf(
                    $notificationData['user_message'],
                    $service->ServiceName,
                    $church->ChurchName,
                    $appointmentDateTime
                ),
                'data' => [
                    'appointment_id' => $appointmentId,
                    'church_id' => $church->ChurchID,
                    'service_id' => $service->ServiceID,
                    'service_name' => $service->ServiceName,
                    'appointment_date' => $appointment->AppointmentDate,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'sub_services' => $subServiceData,
                ],
            ]);
            event(new NotificationCreated($user->id, $userNotification));
            
            // 2. Notify church owner
            $ownerNotification = Notification::create([
                'user_id' => $church->user_id,
                'type' => 'appointment_status_changed',
                'title' => $notificationData['staff_title'],
                'message' => sprintf(
                    $notificationData['staff_message'],
                    $user->email,
                    $service->ServiceName,
                    $appointmentDateTime
                ),
                'data' => [
                    'appointment_id' => $appointmentId,
                    'church_id' => $church->ChurchID,
                    'service_id' => $service->ServiceID,
                    'user_id' => $user->id,
                    'service_name' => $service->ServiceName,
                    'appointment_date' => $appointment->AppointmentDate,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'sub_services' => $subServiceData,
                ],
            ]);
            event(new NotificationCreated($church->user_id, $ownerNotification));
            
            // 3. Notify church staff with permissions
            $staffMembers = \App\Models\UserChurchRole::where('ChurchID', $church->ChurchID)
                ->whereHas('role', function($query) {
                    $query->whereHas('permissions', function($permQuery) {
                        $permQuery->where('PermissionName', 'appointment_list');
                    });
                })
                ->get();
                
            foreach ($staffMembers as $staffRole) {
                $staffNotification = Notification::create([
                    'user_id' => $staffRole->user_id,
                    'type' => 'appointment_status_changed',
                    'title' => $notificationData['staff_title'],
                    'message' => sprintf(
                        $notificationData['staff_message'],
                        $user->email,
                        $service->ServiceName,
                        $appointmentDateTime
                    ),
                    'data' => [
                        'appointment_id' => $appointmentId,
                        'church_id' => $church->ChurchID,
                        'service_id' => $service->ServiceID,
                        'user_id' => $user->id,
                        'service_name' => $service->ServiceName,
                        'appointment_date' => $appointment->AppointmentDate,
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                        'sub_services' => $subServiceData,
                    ],
                ]);
                event(new NotificationCreated($staffRole->user_id, $staffNotification));
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to send status change notifications', [
                'error' => $e->getMessage(),
                'appointment_id' => $appointmentId,
                'new_status' => $newStatus
            ]);
        }
    }
    
    /**
     * Prepare notification data based on status
     */
    private function prepareStatusNotificationData($status, $service, $appointmentDateTime)
    {
        switch ($status) {
            case 'Approved':
                return [
                    'title' => 'Appointment Approved',
                    'user_message' => 'Your appointment for %s at %s on %s has been approved!',
                    'staff_title' => 'Appointment Status Updated',
                    'staff_message' => 'Appointment for %s (%s on %s) has been approved.',
                ];
            case 'Cancelled':
            case 'Rejected':
                return [
                    'title' => 'Appointment Cancelled',
                    'user_message' => 'Your appointment for %s at %s on %s has been cancelled. Please contact the church for more details.',
                    'staff_title' => 'Appointment Cancelled',
                    'staff_message' => 'Appointment for %s (%s on %s) has been cancelled.',
                ];
            default:
                return [
                    'title' => 'Appointment Status Changed',
                    'user_message' => 'Your appointment for %s at %s on %s status has been updated.',
                    'staff_title' => 'Appointment Status Updated',
                    'staff_message' => 'Appointment for %s (%s on %s) status has been updated.',
                ];
        }
    }
    
    /**
     * Generate a unique receipt code
     */
    private function generateReceiptCode()
    {
        do {
            // Generate a unique receipt code: TXN + random 8-digit number
            $receiptCode = 'TXN' . str_pad(mt_rand(1, 99999999), 8, '0', STR_PAD_LEFT);
        } while (ChurchTransaction::where('receipt_code', $receiptCode)->exists());
        
        return $receiptCode;
    }
    
    /**
     * Mark a transaction as refunded using receipt code
     */
    public function markTransactionAsRefunded(Request $request, $transactionId)
    {
        try {
            $validated = $request->validate([
                'refund_reason' => 'nullable|string|max:500',
                'apply_convenience_fee' => 'boolean'
            ]);
            
            $transaction = ChurchTransaction::with(['appointment'])
                ->where('ChurchTransactionID', $transactionId)
                ->where('transaction_type', 'appointment_payment')
                ->first();
                
            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found'
                ], 404);
            }
            
            // Check if appointment is cancelled or rejected (refund only allowed for these statuses)
            if (!$transaction->appointment || !in_array($transaction->appointment->Status, ['Cancelled', 'Rejected'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund is only allowed for cancelled or rejected appointments'
                ], 400);
            }
            
            if ($transaction->refund_status === 'refunded') {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction is already refunded'
                ], 400);
            }
            
            // Calculate refund amount based on convenience fee
            $originalAmount = $transaction->amount_paid;
            $refundAmount = $originalAmount;
            $convenienceFeeAmount = 0;
            
            // Check if convenience fee should be applied
            $applyConvenienceFee = $validated['apply_convenience_fee'] ?? false;
            if ($applyConvenienceFee) {
                $convenienceFee = ChurchConvenienceFee::getActiveForChurch($transaction->church_id);
                if ($convenienceFee) {
                    $convenienceFeeAmount = $convenienceFee->calculateFee($originalAmount);
                    $refundAmount = $convenienceFee->calculateRefundAmount($originalAmount);
                }
            }
            
            $transaction->update([
                'refund_status' => 'refunded',
                'refund_date' => now(),
                'refund_reason' => $validated['refund_reason'] ?? 'Appointment cancelled/rejected - refund processed',
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'refund_calculation' => [
                        'original_amount' => $originalAmount,
                        'convenience_fee_applied' => $applyConvenienceFee,
                        'convenience_fee_amount' => $convenienceFeeAmount,
                        'refund_amount' => $refundAmount
                    ]
                ])
            ]);
            
            Log::info('Transaction marked as refunded', [
                'transaction_id' => $transactionId,
                'refund_reason' => $validated['refund_reason'] ?? 'N/A'
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Transaction marked as refunded successfully',
                'transaction' => $transaction->fresh()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to mark transaction as refunded', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark transaction as refunded'
            ], 500);
        }
    }
    
    /**
     * Generate appointment receipt content
     */
    private function generateAppointmentReceiptContent(ChurchTransaction $transaction)
    {
        $receiptNumber = (string) $transaction->ChurchTransactionID;
        $paymentMethod = $transaction->payment_method === 'multi' ? 'GCash' : $transaction->payment_method;
        
        $content = "\n";
        $content .= "================================================\n";
        $content .= "                FAITHSEEKER                    \n";
        $content .= "           APPOINTMENT RECEIPT                \n";
        $content .= "================================================\n\n";
        
        $content .= "Transaction ID: {$receiptNumber}\n";
        $content .= "Receipt Code: " . ($transaction->receipt_code ?? 'N/A') . "\n";
        $content .= "Transaction Date: " . $transaction->transaction_date->format('F j, Y g:i A') . "\n";
        $content .= "Customer: " . $transaction->user->name . "\n";
        $content .= "Email: " . $transaction->user->email . "\n\n";
        
        $content .= "------------------------------------------------\n";
        $content .= "APPOINTMENT DETAILS\n";
        $content .= "------------------------------------------------\n";
        $content .= "Church: " . $transaction->church->ChurchName . "\n";
        $content .= "Service: " . ($transaction->appointment ? $transaction->appointment->service->ServiceName : 'N/A') . "\n";
        $content .= "Date: " . ($transaction->appointment ? $transaction->appointment->AppointmentDate->format('Y-m-d H:i') : 'N/A') . "\n";
        $content .= "Payment Method: " . $paymentMethod . "\n";
        $content .= "PayMongo Session: " . $transaction->paymongo_session_id . "\n";
        
        $content .= "\n------------------------------------------------\n";
        $content .= "PAYMENT SUMMARY\n";
        $content .= "------------------------------------------------\n";
        $content .= "Amount Paid: ₱" . number_format($transaction->amount_paid, 2) . "\n";
        $content .= "Payment Status: PAID\n";
        
        $content .= "\n------------------------------------------------\n";
        $content .= "NOTES\n";
        $content .= "------------------------------------------------\n";
        $content .= ($transaction->notes ?? 'No additional notes') . "\n";
        
        $content .= "\n================================================\n";
        $content .= "     Thank you for your appointment!           \n";
        $content .= "   Your booking is pending church approval.   \n";
        $content .= "================================================\n";
        
        return $content;
    }

    /**
     * Get appointment answers for certificate generation
     */
    public function getAppointmentAnswers(Request $request, int $appointmentId): JsonResponse
    {
        try {
            // Verify appointment exists
            $appointment = DB::table('Appointment')
                ->where('AppointmentID', $appointmentId)
                ->first();

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found.'
                ], 404);
            }

            // Get all answers for this appointment
            $answers = DB::table('AppointmentInputAnswer')
                ->where('AppointmentID', $appointmentId)
                ->get();

            return response()->json([
                'success' => true,
                'answers' => $answers
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch appointment answers', [
                'error' => $e->getMessage(),
                'appointment_id' => $appointmentId
            ]);

            return response()->json([
                'error' => 'Failed to fetch appointment answers'
            ], 500);
        }
    }

    /**
     * Bulk update appointment statuses
     */
    public function bulkStatusUpdate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'appointment_ids' => 'required|array',
                'appointment_ids.*' => 'integer|exists:Appointment,AppointmentID',
                'status' => 'required|in:Pending,Approved,Completed,Cancelled'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $appointmentIds = $request->appointment_ids;
            $status = $request->status;

            // Update all appointments
            $updated = DB::table('Appointment')
                ->whereIn('AppointmentID', $appointmentIds)
                ->update([
                    'Status' => $status,
                    'updated_at' => now()
                ]);

            Log::info('Bulk status update completed', [
                'appointment_ids' => $appointmentIds,
                'new_status' => $status,
                'updated_count' => $updated
            ]);

            return response()->json([
                'success' => true,
                'message' => "Successfully updated {$updated} appointment(s) to {$status}",
                'updated_count' => $updated
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to bulk update appointment statuses', [
                'error' => $e->getMessage(),
                'appointment_ids' => $request->appointment_ids ?? [],
                'status' => $request->status ?? ''
            ]);

            return response()->json([
                'error' => 'Failed to bulk update appointment statuses'
            ], 500);
        }
    }

    /**
     * Generate Mass Intentions Report as PDF
     */
    public function generateMassIntentionsReport(Request $request): mixed
    {
        try {
            $validator = Validator::make($request->all(), [
                'service_id' => 'required|integer|exists:sacrament_service,ServiceID',
                'date' => 'required|date',
                'schedule_time_id' => 'required|integer|exists:schedule_times,ScheduleTimeID'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $serviceId = $request->service_id;
            $date = $request->date;
            $scheduleTimeId = $request->schedule_time_id;

            // Verify this is a Mass service
            $service = SacramentService::where('ServiceID', $serviceId)
                ->where('isMass', true)
                ->with('church')
                ->first();

            if (!$service) {
                return response()->json([
                    'error' => 'Service not found or is not a Mass service.'
                ], 404);
            }

            // Get all approved appointments for this service on the specified date and time
            $appointments = DB::table('Appointment')
                ->join('users', 'Appointment.UserID', '=', 'users.id')
                ->join('user_profiles as p', 'users.id', '=', 'p.user_id')
                ->join('schedule_times as st', 'Appointment.ScheduleTimeID', '=', 'st.ScheduleTimeID')
                ->where('Appointment.ServiceID', $serviceId)
                ->where('Appointment.Status', 'Approved')
                ->where('Appointment.ScheduleTimeID', $scheduleTimeId)
                ->whereDate('Appointment.AppointmentDate', $date)
                ->select(
                    'Appointment.AppointmentID',
                    'Appointment.AppointmentDate',
                    'st.StartTime',
                    'st.EndTime',
                    DB::raw("COALESCE(p.first_name, '') || ' ' || COALESCE(p.middle_name || '. ', '') || COALESCE(p.last_name, '') as UserName"),
                    'users.email as UserEmail'
                )
                ->orderBy('st.StartTime')
                ->get();

            // Get form configuration for this service
            $formElements = DB::table('service_input_field')
                ->where('ServiceID', $serviceId)
                ->whereNotIn('InputType', ['heading', 'paragraph', 'container'])
                ->orderBy('SortOrder')
                ->get();

            // Get answers for each appointment
            $reportData = [];
            foreach ($appointments as $appointment) {
                $answers = DB::table('AppointmentInputAnswer')
                    ->where('AppointmentID', $appointment->AppointmentID)
                    ->pluck('AnswerText', 'InputFieldID');

                $formData = [];
                foreach ($formElements as $element) {
                    $formData[] = [
                        'label' => $element->Label,
                        'answer' => $answers[$element->InputFieldID] ?? 'N/A'
                    ];
                }

                $reportData[] = [
                    'applicant_name' => $appointment->UserName,
                    'applicant_email' => $appointment->UserEmail,
                    'appointment_time' => $appointment->StartTime && $appointment->EndTime
                        ? date('h:i A', strtotime($appointment->StartTime)) . ' - ' . date('h:i A', strtotime($appointment->EndTime))
                        : 'N/A',
                    'form_data' => $formData
                ];
            }

            // Get the schedule time info for the PDF
            $scheduleTime = DB::table('schedule_times')
                ->where('ScheduleTimeID', $scheduleTimeId)
                ->first();

            // Generate PDF
            $pdf = Pdf::loadView('pdf.mass-intentions-report', [
                'service' => $service,
                'church' => $service->church,
                'date' => $date,
                'scheduleTime' => $scheduleTime,
                'reportData' => $reportData,
                'formElements' => $formElements
            ]);

            $pdf->setPaper('a4', 'portrait');

            $filename = 'Mass_Intentions_' . date('Y-m-d', strtotime($date)) . '_' . str_replace(' ', '_', $service->ServiceName) . '.pdf';

            return $pdf->download($filename);

        } catch (\Exception $e) {
            Log::error('Failed to generate Mass intentions report', [
                'error' => $e->getMessage(),
                'service_id' => $request->service_id ?? null,
                'date' => $request->date ?? null
            ]);

            return response()->json([
                'error' => 'Failed to generate report',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
