<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BatchManagementController;
use App\Http\Controllers\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Admin\BookingRequestController;
use App\Http\Controllers\Admin\CoachMatchingController;
use App\Http\Controllers\Admin\CoachRosterController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\Coach\AvailabilityController;
use App\Http\Controllers\Coach\PortalController as CoachPortalController;
use App\Http\Controllers\Coach\RequestController as CoachRequestController;
use App\Http\Controllers\Coach\ScheduleController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\ManageBookingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - Camp FreedivePH
|--------------------------------------------------------------------------
*/

// ---------------------------------------------------------------
// 1. Public pages (customers)
// ---------------------------------------------------------------
Route::get('/', [LandingController::class, 'index'])->name('landing');
Route::get('/terms-and-conditions', [LegalController::class, 'terms'])->name('legal.terms');
Route::redirect('/terms', '/terms-and-conditions', 301);

Route::get('/privacy-policy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::redirect('/privacy', '/privacy-policy', 301);

Route::get('/book', [BookingController::class, 'create'])->name('booking.create');
Route::post('/api/weather/check', [BookingController::class, 'checkWeather'])->name('api.weather.check')->middleware('throttle:booking_quote_weather');
Route::post('/api/pricing/quote', [BookingController::class, 'getPricingQuote'])->name('api.pricing.quote')->middleware('throttle:booking_quote_weather');
Route::post('/book', [BookingController::class, 'store'])->name('booking.store')->middleware('throttle:booking_create');

Route::get('/manage-booking', [ManageBookingController::class, 'index'])->name('manage.index');
Route::post('/manage-booking/search', [ManageBookingController::class, 'search'])->name('manage.search')->middleware('throttle:manage_lookup');
Route::get('/manage-booking/{booking_number}', [ManageBookingController::class, 'show'])->name('manage.show');
Route::post('/manage-booking/{booking_number}/reschedule', [ManageBookingController::class, 'reschedule'])->name('manage.reschedule')->middleware('throttle:manage_requests');
Route::post('/manage-booking/{booking_number}/cancel', [ManageBookingController::class, 'cancel'])->name('manage.cancel')->middleware('throttle:manage_requests');

// Weather preview for the date picked on the booking page
Route::get('/api/weather/preview', [\App\Http\Controllers\Api\WeatherPreviewController::class, 'preview'])->name('api.weather.preview')->middleware('throttle:booking_quote_weather');

// PayMongo checkout
Route::post('/booking/{booking}/paymongo/checkout', [\App\Http\Controllers\Payment\PayMongoController::class, 'checkout'])->name('paymongo.checkout')->middleware('throttle:paymongo_checkout');
Route::get('/booking/{booking}/paymongo/success', [\App\Http\Controllers\Payment\PayMongoController::class, 'success'])->name('paymongo.success');
Route::get('/booking/{booking}/paymongo/cancel', [\App\Http\Controllers\Payment\PayMongoController::class, 'cancel'])->name('paymongo.cancel');

// PayMongo webhook
Route::post('/api/webhooks/paymongo', [\App\Http\Controllers\Payment\PayMongoController::class, 'webhook'])->name('paymongo.webhook.api')->middleware('throttle:paymongo_webhook');
Route::post('/webhooks/paymongo', [\App\Http\Controllers\Payment\PayMongoController::class, 'webhook'])->name('paymongo.webhook')->middleware('throttle:paymongo_webhook');

// ---------------------------------------------------------------
// 2. Staff login
// ---------------------------------------------------------------
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::get('/admin/login', [LoginController::class, 'showLoginForm']);
Route::get('/staff/login', [LoginController::class, 'showLoginForm']);
Route::get('/staff', [LoginController::class, 'showLoginForm']);
Route::post('/login', [LoginController::class, 'login'])->name('login.post')->middleware('throttle:login');

// Password reset
Route::get('/forgot-password', [PasswordResetController::class, 'showForgotForm'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email')->middleware('throttle:password_reset');

Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->name('password.update')->middleware('throttle:password_reset');

// ---------------------------------------------------------------
// 3. Logged in staff (all roles)
// ---------------------------------------------------------------
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // Change password on first login
    Route::get('/force-password-change', [ForcePasswordChangeController::class, 'show'])->name('password.force_change');
    Route::post('/force-password-change', [ForcePasswordChangeController::class, 'update'])->name('password.force_change.update')->middleware('throttle:password_reset');
});

// ---------------------------------------------------------------
// 4. Admin and owner pages
// ---------------------------------------------------------------
$registerBackofficeRoutes = function (string $portalRole) {
    Route::get('', [AdminDashboardController::class, 'index'])->name('dashboard');

    // Bookings
    Route::get('/bookings', [AdminBookingController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/create', [AdminBookingController::class, 'create'])->name('bookings.create');
    Route::post('/bookings', [AdminBookingController::class, 'store'])->name('bookings.store');
    
    // Pending customer requests
    Route::get('/bookings/requests', [BookingRequestController::class, 'index'])->name('bookings.requests');
    Route::post('/bookings/requests/reschedule/{rescheduleRequest}/approve', [BookingRequestController::class, 'approveReschedule'])->name('bookings.requests.reschedule.approve');
    Route::post('/bookings/requests/reschedule/{rescheduleRequest}/reject', [BookingRequestController::class, 'rejectReschedule'])->name('bookings.requests.reschedule.reject');
    Route::post('/bookings/requests/cancellation/{cancellationRequest}/approve', [BookingRequestController::class, 'approveCancellation'])->name('bookings.requests.cancellation.approve');
    Route::post('/bookings/requests/cancellation/{cancellationRequest}/reject', [BookingRequestController::class, 'rejectCancellation'])->name('bookings.requests.cancellation.reject');

    // Booking details, status and edit
    Route::get('/bookings/{booking}', [AdminBookingController::class, 'show'])->name('bookings.show');
    Route::get('/bookings/{booking}/edit', [AdminBookingController::class, 'edit'])->name('bookings.edit');
    Route::put('/bookings/{booking}', [AdminBookingController::class, 'update'])->name('bookings.update');
    Route::patch('/bookings/{booking}/status', [AdminBookingController::class, 'updateStatus'])->name('bookings.status.update');

    // Batches
    Route::get('/batches', [BatchManagementController::class, 'index'])->name('batches.index');
    Route::get('/batches/create', [BatchManagementController::class, 'create'])->name('batches.create');
    Route::post('/batches', [BatchManagementController::class, 'store'])->name('batches.store');
    Route::get('/batches/unbatched-bookings', [BatchManagementController::class, 'unbatchedBookings'])->name('batches.unbatched_bookings');
    Route::get('/batches/{batch}', [BatchManagementController::class, 'show'])->name('batches.show');
    Route::post('/batches/{batch}/status', [BatchManagementController::class, 'updateStatus'])->name('batches.update_status');
    Route::post('/batches/{batch}/move-booking', [BatchManagementController::class, 'moveBooking'])->name('batches.move_booking');
    Route::post('/batches/{batch}/assign-participant', [BatchManagementController::class, 'assignParticipant'])->name('batches.assign_participant');

    // Payments and refunds
    Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments.index');
    Route::get('/payments/create', [AdminPaymentController::class, 'create'])->name('payments.create');
    Route::post('/payments', [AdminPaymentController::class, 'store'])->name('payments.store');
    Route::get('/payments/refunds', [AdminRefundController::class, 'index'])->name('payments.refunds');
    Route::post('/payments/refunds/{refundRequest}/approve', [AdminRefundController::class, 'approve'])->name('payments.refunds.approve');
    Route::post('/payments/refunds/{refundRequest}/reject', [AdminRefundController::class, 'reject'])->name('payments.refunds.reject');
    Route::post('/payments/refunds/{refundRequest}/forfeit', [AdminRefundController::class, 'forfeit'])->name('payments.refunds.forfeit');
    Route::get('/payments/{payment}', [AdminPaymentController::class, 'show'])->name('payments.show');
    Route::post('/payments/{payment}/settle-balance', [AdminPaymentController::class, 'settleBalance'])->name('payments.settle_balance');

    // Coaches
    Route::get('/coaches', [CoachRosterController::class, 'index'])->name('coaches.index');
    Route::get('/coaches/matching', [CoachMatchingController::class, 'matching'])->name('coaches.matching');
    Route::post('/coaches/matching/assign', [CoachMatchingController::class, 'assign'])->name('coaches.matching.assign');
    Route::post('/coaches/matching/unassign', [CoachMatchingController::class, 'unassign'])->name('coaches.matching.unassign');
    Route::post('/coaches/matching/batch-assign', [CoachMatchingController::class, 'batchAssign'])->name('coaches.matching.batch_assign');
    Route::post('/coaches/matching/broadcast', [CoachMatchingController::class, 'broadcastOpening'])->name('coaches.matching.broadcast');
    Route::get('/coaches/requests', [CoachMatchingController::class, 'requests'])->name('coaches.requests');
    Route::post('/coaches/requests/bulk-approve', [CoachMatchingController::class, 'bulkApproveRequests'])->name('coaches.requests.bulk_approve');
    Route::post('/coaches/requests/{coachRequest}/approve', [CoachMatchingController::class, 'approveRequest'])->name('coaches.requests.approve');
    Route::post('/coaches/release-requests/{releaseRequest}/approve', [CoachMatchingController::class, 'approveReleaseRequest'])->name('coaches.release_requests.approve');
    Route::post('/coaches/release-requests/{releaseRequest}/reject', [CoachMatchingController::class, 'rejectReleaseRequest'])->name('coaches.release_requests.reject');
    Route::get('/coaches/{coach}', [CoachRosterController::class, 'show'])->name('coaches.show');
    Route::post('/coaches/{coach}/reassign-student', [CoachRosterController::class, 'reassignStudent'])->name('coaches.reassign_student');

    // Weather and safety
    Route::get('/safety-monitoring', [\App\Http\Controllers\Admin\WeatherSafetyController::class, 'index'])->name('weather.index');
    Route::get('/weather', [\App\Http\Controllers\Admin\WeatherSafetyController::class, 'index']);
    Route::post('/safety-monitoring/sync-cache', [\App\Http\Controllers\Admin\WeatherSafetyController::class, 'syncCache'])->name('weather.sync_cache')->middleware('throttle:weather_sync');
    Route::post('/weather/sync-cache', [\App\Http\Controllers\Admin\WeatherSafetyController::class, 'syncCache'])->middleware('throttle:weather_sync');
    Route::get('/safety-monitoring/{batch}', [\App\Http\Controllers\Admin\WeatherSafetyController::class, 'show'])->name('weather.show');
    Route::get('/weather/{batch}', [\App\Http\Controllers\Admin\WeatherSafetyController::class, 'show']);
    Route::post('/safety-monitoring/{batch}/assess', [\App\Http\Controllers\Admin\WeatherSafetyController::class, 'assess'])->name('weather.assess');
    Route::post('/weather/{batch}/assess', [\App\Http\Controllers\Admin\WeatherSafetyController::class, 'assess']);
    Route::post('/safety-monitoring/{batch}/override', [\App\Http\Controllers\Admin\WeatherSafetyController::class, 'override'])->name('weather.override');
    Route::post('/weather/{batch}/override', [\App\Http\Controllers\Admin\WeatherSafetyController::class, 'override']);
    Route::post('/safety-monitoring/{batch}/cancel', [\App\Http\Controllers\Admin\WeatherSafetyController::class, 'cancel'])->name('weather.cancel');
    Route::post('/weather/{batch}/cancel', [\App\Http\Controllers\Admin\WeatherSafetyController::class, 'cancel']);

    // Pricing rules
    Route::get('/dynamic-pricing', [\App\Http\Controllers\Admin\PricingRuleController::class, 'index'])->name('pricing.index');
    Route::get('/pricing', [\App\Http\Controllers\Admin\PricingRuleController::class, 'index']);
    Route::get('/dynamic-pricing/create', [\App\Http\Controllers\Admin\PricingRuleController::class, 'create'])->name('pricing.create');
    Route::get('/pricing/create', [\App\Http\Controllers\Admin\PricingRuleController::class, 'create']);
    Route::post('/dynamic-pricing', [\App\Http\Controllers\Admin\PricingRuleController::class, 'store'])->name('pricing.store');
    Route::post('/pricing', [\App\Http\Controllers\Admin\PricingRuleController::class, 'store']);
    Route::get('/dynamic-pricing/{rule}/edit', [\App\Http\Controllers\Admin\PricingRuleController::class, 'edit'])->name('pricing.edit');
    Route::get('/pricing/{rule}/edit', [\App\Http\Controllers\Admin\PricingRuleController::class, 'edit']);
    Route::put('/dynamic-pricing/{rule}', [\App\Http\Controllers\Admin\PricingRuleController::class, 'update'])->name('pricing.update');
    Route::put('/pricing/{rule}', [\App\Http\Controllers\Admin\PricingRuleController::class, 'update']);
    Route::delete('/dynamic-pricing/{rule}', [\App\Http\Controllers\Admin\PricingRuleController::class, 'destroy'])->name('pricing.destroy');
    Route::delete('/pricing/{rule}', [\App\Http\Controllers\Admin\PricingRuleController::class, 'destroy']);
    Route::patch('/dynamic-pricing/{rule}/toggle-status', [\App\Http\Controllers\Admin\PricingRuleController::class, 'toggleStatus'])->name('pricing.toggle_status');
    Route::patch('/pricing/{rule}/toggle-status', [\App\Http\Controllers\Admin\PricingRuleController::class, 'toggleStatus']);
    Route::get('/dynamic-pricing/{rule}/triggered', [\App\Http\Controllers\Admin\PricingRuleController::class, 'triggered'])->name('pricing.triggered');
    Route::get('/pricing/{rule}/triggered', [\App\Http\Controllers\Admin\PricingRuleController::class, 'triggered']);

    // Demand forecast
    Route::get('/demand-forecast', [\App\Http\Controllers\Admin\DemandForecastController::class, 'index'])->name('demand.index');

    // Reports
    Route::get('/reports-and-analytics', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('/reports', [ReportsController::class, 'index']);
    Route::get('/reports-and-analytics/export', [ReportsController::class, 'export'])->name('reports.export');
    Route::get('/reports/export', [ReportsController::class, 'export']);
    Route::get('/reports-and-analytics/print-summary', [ReportsController::class, 'printSummary'])->name('reports.print');
    Route::get('/reports/print', [ReportsController::class, 'printSummary']);

    // Users
    Route::get('/users', [UserManagementController::class, 'index']);
    Route::get('/settings/users', [UserManagementController::class, 'index'])->name('users.index');
    Route::post('/settings/users', [UserManagementController::class, 'store'])->name('users.store');
    Route::get('/settings/users/{user}/edit', [UserManagementController::class, 'edit'])->name('users.edit');
    Route::get('/users/{user}/edit', [UserManagementController::class, 'edit']);
    Route::put('/settings/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
    Route::patch('/settings/users/{user}/toggle-status', [UserManagementController::class, 'toggleStatus'])->name('users.toggle_status');
    Route::delete('/settings/users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');

    // Audit logs and settings (owner only)
    if ($portalRole === 'owner') {
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/settings/audit-logs', [AuditLogController::class, 'index'])->name('audit_logs.index');
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::get('/settings/programs', [SettingsController::class, 'editPrograms'])->name('settings.programs');
        Route::put('/settings/programs', [SettingsController::class, 'updatePrograms'])->name('settings.programs.update');
        Route::get('/settings/deposits', [SettingsController::class, 'editDeposits'])->name('settings.deposits');
        Route::put('/settings/deposits', [SettingsController::class, 'updateDeposits'])->name('settings.deposits.update');
        Route::get('/settings/addons', [SettingsController::class, 'editAddons'])->name('settings.addons');
        Route::put('/settings/addons', [SettingsController::class, 'updateAddons'])->name('settings.addons.update');
        Route::get('/settings/operations', [SettingsController::class, 'editOperations'])->name('settings.operations');
        Route::put('/settings/operations', [SettingsController::class, 'updateOperations'])->name('settings.operations.update');
        Route::get('/settings/cancellation', [SettingsController::class, 'editCancellation'])->name('settings.cancellation');
        Route::put('/settings/cancellation', [SettingsController::class, 'updateCancellation'])->name('settings.cancellation.update');
    } else {
        Route::middleware('role:owner')->group(function () {
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
            Route::get('/settings/audit-logs', [AuditLogController::class, 'index'])->name('audit_logs.index');
            Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
            Route::get('/settings/programs', [SettingsController::class, 'editPrograms'])->name('settings.programs');
            Route::put('/settings/programs', [SettingsController::class, 'updatePrograms'])->name('settings.programs.update');
            Route::get('/settings/deposits', [SettingsController::class, 'editDeposits'])->name('settings.deposits');
            Route::put('/settings/deposits', [SettingsController::class, 'updateDeposits'])->name('settings.deposits.update');
            Route::get('/settings/addons', [SettingsController::class, 'editAddons'])->name('settings.addons');
            Route::put('/settings/addons', [SettingsController::class, 'updateAddons'])->name('settings.addons.update');
            Route::get('/settings/operations', [SettingsController::class, 'editOperations'])->name('settings.operations');
            Route::put('/settings/operations', [SettingsController::class, 'updateOperations'])->name('settings.operations.update');
            Route::get('/settings/cancellation', [SettingsController::class, 'editCancellation'])->name('settings.cancellation');
            Route::put('/settings/cancellation', [SettingsController::class, 'updateCancellation'])->name('settings.cancellation.update');
        });
    }
};

// Owner routes
Route::middleware(['auth', 'active', 'must_change_password', 'role:owner'])
    ->prefix('owner')
    ->name('owner.')
    ->group(function () use ($registerBackofficeRoutes) {
        $registerBackofficeRoutes('owner');
    });

// Admin routes
Route::middleware(['auth', 'active', 'must_change_password', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () use ($registerBackofficeRoutes) {
        $registerBackofficeRoutes('admin');
    });

// ---------------------------------------------------------------
// 5. Coach pages
// ---------------------------------------------------------------
Route::middleware(['auth', 'active', 'must_change_password', 'role:coach'])
    ->prefix('coach')
    ->name('coach.')
    ->group(function () {
        // Page 1: dashboard
        Route::get('/', [CoachPortalController::class, 'index'])->name('dashboard');

        // Page 2: availability calendar
        Route::get('/availability-calendar', [AvailabilityController::class, 'index'])->name('availability.index');
        Route::get('/availability', [AvailabilityController::class, 'index']);
        Route::post('/availability/toggle', [AvailabilityController::class, 'toggle'])->name('availability.toggle');
        Route::post('/availability/bulk', [AvailabilityController::class, 'bulkUpdate'])->name('availability.bulk');
        Route::post('/availability/release', [AvailabilityController::class, 'requestRelease'])->name('availability.release');

        // Page 3: my schedule
        Route::get('/my-schedule', [ScheduleController::class, 'index'])->name('schedule.index');
        Route::get('/schedule', [ScheduleController::class, 'index']);

        // Page 4: open slot requests
        Route::get('/open-slot-requests', [CoachRequestController::class, 'index'])->name('requests.index');
        Route::get('/open-requests', [CoachRequestController::class, 'index']);
        Route::post('/open-slot-requests/{opening}/apply', [CoachRequestController::class, 'store'])->name('requests.store');
        Route::post('/open-requests/{opening}/apply', [CoachRequestController::class, 'store']);
        Route::delete('/open-slot-requests/{coachRequest}/withdraw', [CoachRequestController::class, 'withdraw'])->name('requests.withdraw');
        Route::delete('/open-requests/{coachRequest}/withdraw', [CoachRequestController::class, 'withdraw']);
    });
