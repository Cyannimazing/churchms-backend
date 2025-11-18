<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\ChurchSubscriptionController;
use App\Http\Controllers\AppointmentController;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// Payment callback routes (no auth required)
Route::get('/payment/success', [ChurchSubscriptionController::class, 'handlePaymentSuccess'])->name('web.payment.success');
Route::get('/payment/cancel', [ChurchSubscriptionController::class, 'handlePaymentCancel'])->name('web.payment.cancel');

// Appointment payment callback routes (no auth required)
Route::get('/appointment-payment/success', [AppointmentController::class, 'handleAppointmentPaymentSuccess'])->name('appointment.payment.success');
Route::get('/appointment-payment/cancel', [AppointmentController::class, 'handleAppointmentPaymentCancel'])->name('appointment.payment.cancel');

// Appointment RESCHEDULE payment callback routes (no auth required)
// These URLs are used as success/cancel redirect URLs from PayMongo checkout.
// Example: /appointment-reschedule/payment/success?session_id=...&appointment_id=123
Route::get('/appointment-reschedule/payment/success', [AppointmentController::class, 'handleReschedulePaymentSuccess'])->name('web.appointment.reschedule.payment.success');
Route::get('/appointment-reschedule/payment/cancel', [AppointmentController::class, 'handleReschedulePaymentCancel'])->name('web.appointment.reschedule.payment.cancel');

// Broadcasting authentication route  
Broadcast::routes(['middleware' => ['auth:sanctum']]);

