<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\ChurchSubscriptionController;
use App\Http\Controllers\ChurchController;
use App\Http\Controllers\ChurchStaffController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SacramentServiceController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\PaymentConfigController;
use App\Http\Controllers\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\ChurchMemberController;
use App\Http\Controllers\ConvenienceFeeController;
use App\Http\Controllers\SignatureController;
use App\Http\Controllers\SubServiceController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\CertificateConfigurationController;
use App\Http\Controllers\ClergyController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;

// Authentication Routes (no CSRF required for token-based auth)
Route::post('/register', [RegisteredUserController::class, 'store'])->name('register');
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login');
Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');

// Email verification (no auth required - signed URL validates user)
Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware(['throttle:6,1'])
        ->name('verification.send');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

// Locations (public routes)
Route::get('/provinces', [LocationController::class, 'getProvinces']);
Route::get('/provinces/{provinceId}/cities', [LocationController::class, 'getCitiesByProvince']);
Route::get('/locations', [LocationController::class, 'getAllLocations']);

//USERS
Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    $user = $request->user()->load(['profile.systemRole', 'contact']);
    
    if ($user->profile->system_role_id == 3) {  
        $user->load([
            'churchRole.permissions', 'church', 'userChurchRole'
        ]);
    }
    
    if ($user->profile->system_role_id == 2) {
        $user->load(['churches']);
    }
    
    
    return $user;
});

Route::get('/users_list', [UserController::class, 'index'])->middleware('auth:sanctum');
Route::put('/users/{id}/update-active', [UserController::class, 'updateActiveStatus'])->middleware('auth:sanctum');
Route::get('/users/{id}', [UserController::class, 'show'])->middleware('auth:sanctum');
Route::put('/users/{id}/profile', [UserController::class, 'updateProfile'])->middleware('auth:sanctum');
Route::put('/users/{id}/password', [UserController::class, 'updatePassword'])->middleware('auth:sanctum');

//NOTIFICATIONS
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unreadCount');
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.markAsRead');
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.markAllAsRead');
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
});

//DASHBOARD ANALYTICS
Route::middleware('auth:sanctum')->get('/dashboard/analytics', [DashboardController::class, 'getAnalytics'])->name('dashboard.analytics');
Route::middleware('auth:sanctum')->get('/admin/dashboard/analytics', [AdminDashboardController::class, 'getAnalytics'])->name('admin.dashboard.analytics');

//SUBSCRIPTION PLANS
Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store']);
    Route::put('/subscription-plans/{id}', [SubscriptionPlanController::class, 'update']);
    Route::delete('/subscription-plans/{id}', [SubscriptionPlanController::class, 'destroy']);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/church-subscriptions', [ChurchSubscriptionController::class, 'index']);
    Route::post('/church-subscriptions', [ChurchSubscriptionController::class, 'update']);
    
    // Payment Routes
    Route::post('/church-subscriptions/gcash-payment', [ChurchSubscriptionController::class, 'createGCashPayment']);
    Route::post('/church-subscriptions/payment', [ChurchSubscriptionController::class, 'createPayment']);
    
    // Transaction and Receipt Routes
    Route::get('/transactions/{transactionId}', [ChurchSubscriptionController::class, 'getTransactionDetails'])->name('transactions.details');
    Route::get('/transactions/{transactionId}/receipt', [ChurchSubscriptionController::class, 'downloadReceipt'])->name('transactions.receipt');
});

// Admin Transactions (auth required)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/admin/transactions', [AdminTransactionController::class, 'index']);
    Route::get('/admin/transactions/{id}', [AdminTransactionController::class, 'show']);
    // Refunds disabled for subscriptions
    Route::get('/admin/transactions/search/by-reference', [AdminTransactionController::class, 'searchByReference']);
});

// Payment callback routes (no auth required)
Route::get('/payment/success', [ChurchSubscriptionController::class, 'handlePaymentSuccess'])->name('payment.success');
Route::get('/payment/cancel', [ChurchSubscriptionController::class, 'handlePaymentCancel'])->name('payment.cancel');
Route::post('/webhooks/paymongo', [ChurchSubscriptionController::class, 'handlePayMongoWebhook'])->name('webhook.paymongo');

//CHURCHES

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/churches', [ChurchController::class, 'store'])->name('churches.store');
    Route::get('/churches/owned', [ChurchController::class, 'showOwnedChurches'])->name('churches.owned');
    
    // Church endpoints for authenticated users (including regular users)
    Route::get('/churches/public', [ChurchController::class, 'getPublicChurches'])->name('churches.public');
    Route::get('/churches/{churchId}/public', [ChurchController::class, 'getPublicChurch'])->name('churches.public.show');
    Route::get('/churches/{churchId}/sacrament-services', [SacramentServiceController::class, 'getPublicChurchServices'])->name('churches.sacraments.public');
    Route::get('/sacrament-services/{serviceId}/schedules-public', [SacramentServiceController::class, 'getPublicServiceSchedules'])->name('sacraments.schedules.public');
    Route::get('/sacrament-services/{serviceId}/form-config-public', [SacramentServiceController::class, 'getFormConfiguration'])->name('sacraments.form.public');
    Route::get('/schedule-remaining-slots', [SacramentServiceController::class, 'getScheduleRemainingSlots'])->name('schedule.remaining.slots');
    
    Route::get('/churches', [ChurchController::class, 'index'])->name('churches.index');
    Route::put('/churches/{churchId}/status', [ChurchController::class, 'updateStatus'])->name('churches.updateStatus');
    Route::put('/churches/{churchId}/publish', [ChurchController::class, 'togglePublish'])->name('churches.togglePublish');
    Route::put('/churches/{churchId}/disable', [ChurchController::class, 'disableChurch'])->name('churches.disable');
    Route::get('/churches/{churchId}/documents', [ChurchController::class, 'reviewDocuments'])->name('churches.reviewDocuments');
    Route::get('/documents/{documentId}', [ChurchController::class, 'downloadDocument'])->name('documents.download');
    Route::get('/churches/{churchId}', [ChurchController::class, 'show'])->name('churches.show');
    Route::get('/churches/{churchId}/profile-picture', [ChurchController::class, 'getProfilePicture'])->name('churches.profilePicture');
    Route::post('/churches/{churchId}/update', [ChurchController::class, 'update'])->name('churches.update');
    
    // Payment Configuration Routes
    Route::get('/churches/{churchId}/payment-config', [PaymentConfigController::class, 'show'])->name('churches.paymentConfig.show');
    Route::post('/churches/{churchId}/payment-config', [PaymentConfigController::class, 'store'])->name('churches.paymentConfig.store');
    Route::put('/churches/{churchId}/payment-config/status', [PaymentConfigController::class, 'updateStatus'])->name('churches.paymentConfig.updateStatus');
    Route::delete('/churches/{churchId}/payment-config', [PaymentConfigController::class, 'destroy'])->name('churches.paymentConfig.destroy');

});

//Staff Management
Route::middleware('auth:sanctum')->group(function () {
    // RolePermissionController Endpoints
    Route::get('/churches-and-roles/{churchName}', [RolePermissionController::class, 'getChurchAndRoles']);
    Route::post('roles', [RolePermissionController::class, 'store']);
    Route::get('roles/{roleId}', [RolePermissionController::class, 'show']);
    Route::put('roles/{roleId}', [RolePermissionController::class, 'update']);
    Route::get('permissions', [RolePermissionController::class, 'getPermissions']);

    // ChurchStaffController Endpoints
    Route::get('/church-and-staff/{churchName}', [ChurchStaffController::class, 'getChurchAndStaff']);
    Route::post('staff', [ChurchStaffController::class, 'store']);
    Route::get('staff/{staffId}', [ChurchStaffController::class, 'show']);
    Route::put('staff/{staffId}', [ChurchStaffController::class, 'update']);
    Route::patch('staff/{staffId}/toggle-status', [ChurchStaffController::class, 'toggleStatus']);
});

//Clergy Management
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/clergy', [ClergyController::class, 'index']);
    Route::post('/clergy', [ClergyController::class, 'store']);
    Route::get('/clergy/{id}', [ClergyController::class, 'show']);
    Route::put('/clergy/{id}', [ClergyController::class, 'update']);
    Route::delete('/clergy/{id}', [ClergyController::class, 'destroy']);
    Route::patch('/clergy/{id}/toggle-status', [ClergyController::class, 'toggleStatus']);
});

//Sacrament Services Management
Route::middleware('auth:sanctum')->group(function () {
    // CRUD operations for sacrament services
    Route::post('/sacrament-services', [SacramentServiceController::class, 'store']);
    Route::put('/sacrament-services/{serviceId}', [SacramentServiceController::class, 'update'])->where('serviceId', '[0-9]+');
    Route::delete('/sacrament-services/{serviceId}', [SacramentServiceController::class, 'destroy'])->where('serviceId', '[0-9]+');
    
    // Get church and its sacrament services (church name route)
    Route::get('/sacrament-services/{churchName}', [SacramentServiceController::class, 'getChurchAndSacramentServices'])->where('churchName', '[A-Za-z0-9\s\-_]+');
    
    // Get a specific sacrament service by ID (numeric route)
    Route::get('/sacrament-services/{serviceId}', [SacramentServiceController::class, 'show'])->where('serviceId', '[0-9]+');
    
    // Form configuration management
    Route::post('/sacrament-services/{serviceId}/form-config', [SacramentServiceController::class, 'saveFormConfiguration'])->where('serviceId', '[0-9]+');
    Route::get('/sacrament-services/{serviceId}/form-config', [SacramentServiceController::class, 'getFormConfiguration'])->where('serviceId', '[0-9]+');
    
    // Schedule management
    Route::get('/sacrament-services/{serviceId}/schedules', [ScheduleController::class, 'getServiceSchedules'])->where('serviceId', '[0-9]+');
    Route::post('/sacrament-services/{serviceId}/schedules', [ScheduleController::class, 'store'])->where('serviceId', '[0-9]+');
    Route::get('/sacrament-services/{serviceId}/available-times', [ScheduleController::class, 'getAvailableTimeSlots'])->where('serviceId', '[0-9]+');
    Route::get('/schedules/{scheduleId}', [ScheduleController::class, 'getSchedule'])->where('scheduleId', '[0-9]+');
    Route::put('/schedules/{scheduleId}', [ScheduleController::class, 'update'])->where('scheduleId', '[0-9]+');
    Route::delete('/schedules/{scheduleId}', [ScheduleController::class, 'destroy'])->where('scheduleId', '[0-9]+');
    
    // Sub-service management
    Route::get('/sacrament-services/{serviceId}/sub-services', [SubServiceController::class, 'index'])->where('serviceId', '[0-9]+');
    Route::post('/sub-services', [SubServiceController::class, 'store']);
    Route::get('/sub-services/{subServiceId}', [SubServiceController::class, 'show'])->where('subServiceId', '[0-9]+');
    Route::put('/sub-services/{subServiceId}', [SubServiceController::class, 'update'])->where('subServiceId', '[0-9]+');
    Route::delete('/sub-services/{subServiceId}', [SubServiceController::class, 'destroy'])->where('subServiceId', '[0-9]+');
});

//Appointments
Route::middleware('auth:sanctum')->group(function () {
    // Submit appointment application
    Route::post('/sacrament-applications', [AppointmentController::class, 'store'])->name('sacrament-applications.store');
    
    // Submit appointment with form data (for custom forms when isStaffForm=false)
    Route::post('/appointments', [AppointmentController::class, 'storeWithFormData'])->name('appointments.store');
    
    // Get user's appointments
    Route::get('/appointments', [AppointmentController::class, 'getUserAppointments'])->name('appointments.index');
    
    // Get specific appointment details
    Route::get('/appointments/{appointmentId}', [AppointmentController::class, 'show'])->where('appointmentId', '[0-9]+')->name('appointments.show');
    
    // Get church appointments (for church staff)
    Route::get('/church-appointments/{churchName}', [AppointmentController::class, 'getChurchAppointments'])->where('churchName', '[A-Za-z0-9\s\-_]+')->name('church.appointments.index');
    
    // Update appointment status
    Route::put('/appointments/{appointmentId}/status', [AppointmentController::class, 'updateStatus'])->where('appointmentId', '[0-9]+')->name('appointments.updateStatus');
    
    // Update requirement submission status
    Route::put('/appointments/{appointmentId}/requirement-submission', [AppointmentController::class, 'updateRequirementSubmission'])->where('appointmentId', '[0-9]+')->name('appointments.updateRequirementSubmission');
    
    // Update sub-service completion status
    Route::put('/appointments/{appointmentId}/sub-service-completion', [AppointmentController::class, 'updateSubServiceCompletion'])->where('appointmentId', '[0-9]+')->name('appointments.updateSubServiceCompletion');
    
    // Update sub-service requirement submission status
    Route::put('/appointments/{appointmentId}/sub-service-requirement-submission', [AppointmentController::class, 'updateSubServiceRequirementSubmission'])->where('appointmentId', '[0-9]+')->name('appointments.updateSubServiceRequirementSubmission');
    
    // Save form data for appointment
    Route::post('/appointments/{appointmentId}/staff-form-data', [AppointmentController::class, 'saveFormData'])->where('appointmentId', '[0-9]+')->name('appointments.saveFormData');
    
    // Get appointment answers for certificate generation
    Route::get('/appointments/{appointmentId}/answers', [AppointmentController::class, 'getAppointmentAnswers'])->where('appointmentId', '[0-9]+')->name('appointments.answers');
    
    // Bulk update appointment statuses
    Route::put('/appointments/bulk-status-update', [AppointmentController::class, 'bulkStatusUpdate'])->name('appointments.bulk-status-update');
    
    // Mass Intentions Report (PDF download)
    Route::post('/appointments/mass-intentions-report', [AppointmentController::class, 'generateMassIntentionsReport'])->name('appointments.mass-intentions-report');
    
    // Payment success handling (for completing paid appointments)
    Route::post('/appointments/payment/success', [AppointmentController::class, 'handlePaymentSuccess'])->name('appointments.payment.success');
    
    // Appointment transaction details and receipts
    Route::get('/appointment-transactions/{transactionId}', [AppointmentController::class, 'getAppointmentTransactionDetails'])->name('appointment.transactions.details');
    Route::get('/appointment-transactions/by-session', [AppointmentController::class, 'getAppointmentTransactionBySession'])->name('appointment.transactions.by_session');
    Route::get('/appointment-transactions/{transactionId}/receipt', [AppointmentController::class, 'downloadAppointmentReceipt'])->name('appointment.transactions.receipt');
    
    // Church transactions (income records)
    Route::get('/church-transactions/{churchName}', [AppointmentController::class, 'getChurchTransactions'])->where('churchName', '[A-Za-z0-9\s\-_]+')->name('church.transactions.index');
    Route::put('/church-transactions/{transactionId}/refund', [AppointmentController::class, 'markTransactionAsRefunded'])->where('transactionId', '[0-9]+')->name('church.transactions.refund');
    
    // Convenience fee management
    Route::get('/convenience-fees/{churchName}', [ConvenienceFeeController::class, 'getChurchConvenienceFee'])->where('churchName', '[A-Za-z0-9\s\-_]+')->name('convenience-fees.show');
    Route::post('/convenience-fees/{churchName}', [ConvenienceFeeController::class, 'storeOrUpdate'])->where('churchName', '[A-Za-z0-9\s\-_]+')->name('convenience-fees.store');
    Route::post('/convenience-fees/calculate-refund', [ConvenienceFeeController::class, 'calculateRefund'])->name('convenience-fees.calculate-refund');
});

// Church Members Management
Route::middleware('auth:sanctum')->group(function () {
    // Member CRUD operations
    Route::get('/church-members', [ChurchMemberController::class, 'index'])->name('church-members.index');
    Route::post('/church-members', [ChurchMemberController::class, 'store'])->name('church-members.store');
    Route::get('/church-members/{churchMember}', [ChurchMemberController::class, 'show'])->name('church-members.show');
    Route::put('/church-members/{churchMember}', [ChurchMemberController::class, 'update'])->name('church-members.update');
    Route::delete('/church-members/{churchMember}', [ChurchMemberController::class, 'destroy'])->name('church-members.destroy');
    
    // Get members by church
    Route::get('/churches/{churchId}/members', [ChurchMemberController::class, 'getByChurch'])->name('churches.members');
    
    // Member applications management
    Route::get('/member-applications', [ChurchMemberController::class, 'getApplications'])->name('member-applications.index');
    Route::post('/church-members/{churchMember}/approve', [ChurchMemberController::class, 'approveApplication'])->name('church-members.approve');
    Route::post('/church-members/{churchMember}/reject', [ChurchMemberController::class, 'rejectApplication'])->name('church-members.reject');
    
    // Member status management
    Route::post('/church-members/{churchMember}/set-away', [ChurchMemberController::class, 'setAway'])->name('church-members.set-away');
});

// Signatures Management
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/signatures', [SignatureController::class, 'index'])->name('signatures.index');
    Route::post('/signatures', [SignatureController::class, 'store'])->name('signatures.store');
    Route::delete('/signatures/{id}', [SignatureController::class, 'destroy'])->name('signatures.destroy');
    Route::get('/signatures/{id}/image', [SignatureController::class, 'getImage'])->name('signatures.image');
    Route::get('/my-membership-status', [ChurchMemberController::class, 'getMemberStatus'])->name('member-status.index');
    
    // Check user membership for specific church
    Route::get('/user/membership/{churchId}', [ChurchMemberController::class, 'getUserMembership'])->name('user.membership.check');
});

// Public member registration (no auth required for regular users to apply)
Route::post('/public/church-members', [ChurchMemberController::class, 'store'])->name('public.church-members.store');

// Certificate Configuration Management
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/certificate-config/{churchName}/{certificateType}', [CertificateConfigurationController::class, 'getConfiguration'])->where('churchName', '[A-Za-z0-9\\s\\-_]+')->name('certificate-config.get');
    Route::post('/certificate-config/{churchName}', [CertificateConfigurationController::class, 'saveConfiguration'])->where('churchName', '[A-Za-z0-9\\s\\-_]+')->name('certificate-config.save');
    
    // Get certificate field data auto-populated from appointment answers
    Route::get('/appointments/{appointmentId}/certificate-data/{certificateType}', [CertificateConfigurationController::class, 'getCertificateFieldData'])->where('appointmentId', '[0-9]+')->name('appointments.certificate-data');
    
    // Certificate verification
    Route::post('/certificate-verification', [CertificateConfigurationController::class, 'createCertificateVerification'])->name('certificate.verification.create');
});

// Public certificate verification (no auth required)
Route::get('/verify-certificate/{token}', [CertificateConfigurationController::class, 'verifyCertificate'])->name('certificate.verify');
