<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\PaymentSetupController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\PromoCodeController;
use App\Http\Controllers\Api\RecurringInvoiceController;
use App\Http\Controllers\Api\InvoiceTemplateController;
use App\Http\Controllers\Api\ExportController;

use App\Http\Controllers\Api\PublicController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// Auth
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/client/login', [AuthController::class, 'clientLogin']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
});

// Public Dynamic Content
Route::prefix('/public')->group(function () {
    Route::get('/home', [PublicController::class, 'home']);
    Route::get('/features', [PublicController::class, 'features']);
    Route::get('/about', [PublicController::class, 'about']);
    Route::get('/legal/{type}', [PublicController::class, 'legal']);
});

// Public pricing
Route::get('/pricing', [AdminController::class, 'getPricing']);

// Public blog
Route::get('/blog', function (\Illuminate\Http\Request $request) {
    $query = \App\Models\Post::published()->with('author')->latest('published_at');
    if ($request->category && $request->category !== 'all') $query->where('category', $request->category);
    if ($request->search) $query->where('title', 'like', "%{$request->search}%");
    return \App\Http\Resources\PostResource::collection($query->paginate(12));
});
Route::get('/blog/{slug}', function (string $slug) {
    $post = \App\Models\Post::published()->with('author')->where('slug', $slug)->firstOrFail();
    return new \App\Http\Resources\PostResource($post);
});

// Public config (non-sensitive keys for frontend)
Route::get('/config/paystack', function () {
    return response()->json(['public_key' => config('services.paystack.public_key', '')]);
});

// Simple ping — always returns 200, used by Railway healthcheck
Route::get('/ping', function () {
    return response()->json(['status' => 'ok'], 200);
});

// Full health check with DB status
Route::get('/health', function () {
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $dbOk = true;
    } catch (\Exception $e) {
        $dbOk = false;
    }

    return response()->json([
        'status' => $dbOk ? 'healthy' : 'unhealthy',
        'database' => $dbOk,
        'timestamp' => now()->toDateTimeString(),
        'version' => '1.0.0'
    ], $dbOk ? 200 : 503);
});

// Waitlist (public)
Route::post('/waitlist', [\App\Http\Controllers\Api\WaitlistController::class, 'store']);

// Public promo code validation (rate-limited to prevent brute-force)
Route::middleware('throttle:10,1')->post('/promo-codes/validate', [PromoCodeController::class, 'validate_code']);

// Public banks list
Route::get('/banks', [PaymentSetupController::class, 'listBanks']);
Route::middleware('throttle:10,1')->post('/resolve-account', [PaymentSetupController::class, 'resolveAccount']);

// Paystack webhook (no token validation - uses signature verification)
Route::post('/payments/webhook', [PaymentController::class, 'webhook']);

// Public signing activity tracking (from client signing page, rate-limited)
Route::middleware('throttle:30,1')->post('/contracts/{token}/signing-activity',
    [\App\Http\Controllers\Api\SigningEventController::class, 'recordActivity']);

// Public document viewing via signing token (with token validation middleware)
Route::middleware(['throttle:20,1', 'validate.public.token:invoice'])->group(function () {
    Route::get('/invoices/view/{token}', [InvoiceController::class, 'viewByToken']);
    Route::get('/invoices/pdf/{token}', [InvoiceController::class, 'downloadPdfByToken']);
    Route::post('/invoices/sign/{token}', [InvoiceController::class, 'signByToken'])->middleware('throttle:3,1');
});

Route::middleware(['throttle:20,1', 'validate.public.token:contract'])->group(function () {
    Route::get('/contracts/view/{token}', [ContractController::class, 'viewByToken']);
    Route::get('/contracts/pdf/{token}', [ContractController::class, 'downloadPdfByToken']);
    Route::post('/contracts/sign/{token}', [ContractController::class, 'signByToken'])->middleware('throttle:3,1');
});

// Public invoice viewing by ID for payment pages (with token validation)
Route::middleware(['throttle:20,1', 'validate.public.token:invoice'])
    ->get('/invoices/payment/{token}', [InvoiceController::class, 'viewForPayment']);

// Public payment initiation for client payment pages (with token validation)
Route::middleware(['throttle:10,1', 'validate.public.token:invoice'])
    ->post('/payments/initiate-public', [PaymentController::class, 'initiatePublic']);

// Payment verification
Route::post('/payments/verify', [PaymentController::class, 'verify'])->middleware('throttle:20,1');

/*
|--------------------------------------------------------------------------
| Authenticated Routes (all users)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

    // Auth
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::match(['put', 'post'], '/auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('/auth/password', [AuthController::class, 'changePassword']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::delete('/auth/delete-account', [AuthController::class, 'deleteAccount']);
    Route::get('/auth/notification-preferences', [AuthController::class, 'getNotificationPreferences']);
    Route::put('/auth/notification-preferences', [AuthController::class, 'updateNotificationPreferences']);
    Route::post('/auth/test-notification', [AuthController::class, 'testNotification']);
    Route::post('/auth/two-factor/toggle', [AuthController::class, 'toggleTwoFactor']);
    Route::post('/auth/two-factor/verify', [AuthController::class, 'verifyTwoFactor']);
    Route::post('/auth/two-factor/verify-backup', [AuthController::class, 'verifyTwoFactorBackup']);
    Route::post('/auth/two-factor/generate-backup', [AuthController::class, 'generateBackupCodes']);
    Route::get('/auth/sessions', [AuthController::class, 'activeSessions']);
    Route::delete('/auth/sessions/{tokenId}', [AuthController::class, 'revokeSession']);
    Route::delete('/auth/sessions', [AuthController::class, 'revokeAllSessions']);
    Route::middleware('throttle:5,1')->post('/auth/verify-email', [AuthController::class, 'verifyEmail']);
    Route::middleware('throttle:3,1')->post('/auth/resend-verification', [AuthController::class, 'resendVerification']);

    // Referrals
    Route::get('/referrals', [ReferralController::class, 'index']);
    Route::post('/referrals/regenerate', [ReferralController::class, 'regenerateCode']);

    // Promo code redemption (authenticated users)
    Route::post('/promo-codes/redeem', [PromoCodeController::class, 'redeem']);

    // Support
    Route::post('/support/feedback', [SupportController::class, 'feedback']);
    Route::post('/support/feature', [SupportController::class, 'featureRequest']);
    Route::post('/support/contact', [SupportController::class, 'contact']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    
    // Onboarding Checklist
    Route::get('/onboarding/checklist', [\App\Http\Controllers\Api\OnboardingController::class, 'getChecklist']);
    Route::put('/onboarding/checklist', [\App\Http\Controllers\Api\OnboardingController::class, 'updateChecklist']);
    Route::post('/onboarding/dismiss', [\App\Http\Controllers\Api\OnboardingController::class, 'dismissChecklist']);

    /*
    |----------------------------------------------------------------------
    | Business Owner Routes
    |----------------------------------------------------------------------
    */

    Route::middleware('business.owner')->group(function () {

        // Businesses
        Route::get('/businesses', [BusinessController::class, 'index']);
        Route::post('/businesses', [BusinessController::class, 'store'])->middleware('plan.limit:businesses');
        Route::get('/plan-usage', [BusinessController::class, 'planUsage']);

        // Business-scoped routes
        // withoutScopedBindings() prevents Laravel from auto-scoping {contract}/{invoice} etc.
        // to {business} via relationship — controllers handle ownership checks themselves.
        Route::prefix('/businesses/{business}')->withoutScopedBindings()->group(function () {
            Route::get('/', [BusinessController::class, 'show']);
            Route::put('/', [BusinessController::class, 'update']);
            Route::delete('/', [BusinessController::class, 'destroy']);
            Route::post('/force-delete', [BusinessController::class, 'forceDelete']);
            Route::post('/archive', [BusinessController::class, 'archive']);
            Route::get('/dashboard', [BusinessController::class, 'dashboard']);
            Route::post('/logo', [BusinessController::class, 'updateLogo'])->middleware('plan.limit:branding');
            Route::put('/branding', [BusinessController::class, 'updateBranding'])->middleware('plan.limit:branding');
            Route::put('/signature', [BusinessController::class, 'updateSignature']);
            Route::post('/upgrade', [BusinessController::class, 'upgrade']);
            Route::post('/start-trial', [BusinessController::class, 'startTrial']);
            Route::get('/billing-history', [BusinessController::class, 'billingHistory']);
            Route::get('/recommendations', [BusinessController::class, 'recommendations']);

            // Guided creation assistant
            Route::get('/guided-creation/status', [BusinessController::class, 'guidedCreationStatus']);
            Route::post('/guided-creation/track', [BusinessController::class, 'guidedCreationTrack']);

            // Search
            Route::get('/search', [SearchController::class, 'search']);

            // Invoices
            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('plan.limit:invoices');
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::put('/invoices/{invoice}', [InvoiceController::class, 'update']);
            Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy']);
            Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send']);
            Route::post('/invoices/{invoice}/reminder', [InvoiceController::class, 'sendReminder']);
            Route::post('/invoices/{invoice}/resend-signature', [InvoiceController::class, 'resendSignature']);
            Route::post('/invoices/{invoice}/duplicate', [InvoiceController::class, 'duplicate']);
            Route::post('/invoices/{invoice}/mark-paid', [InvoiceController::class, 'markAsPaid']);
            Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf']);
            Route::get('/invoices/{invoice}/receipt', [InvoiceController::class, 'downloadReceipt']);

            // Recurring Invoices
            Route::get('/recurring-invoices', [RecurringInvoiceController::class, 'index']);
            Route::post('/recurring-invoices', [RecurringInvoiceController::class, 'store'])->middleware('plan.limit:recurring_invoices');
            Route::get('/recurring-invoices/{recurringInvoice}', [RecurringInvoiceController::class, 'show']);
            Route::put('/recurring-invoices/{recurringInvoice}', [RecurringInvoiceController::class, 'update']);
            Route::delete('/recurring-invoices/{recurringInvoice}', [RecurringInvoiceController::class, 'destroy']);
            Route::post('/recurring-invoices/{recurringInvoice}/pause', [RecurringInvoiceController::class, 'pause']);
            Route::post('/recurring-invoices/{recurringInvoice}/resume', [RecurringInvoiceController::class, 'resume']);
            Route::post('/recurring-invoices/{recurringInvoice}/generate', [RecurringInvoiceController::class, 'generateInvoice']);

            // Invoice Templates
            Route::get('/invoice-templates', [InvoiceTemplateController::class, 'index']);
            Route::post('/invoice-templates', [InvoiceTemplateController::class, 'store'])->middleware('plan.limit:invoice_templates');
            Route::get('/invoice-templates/{template}', [InvoiceTemplateController::class, 'show']);
            Route::put('/invoice-templates/{template}', [InvoiceTemplateController::class, 'update']);
            Route::delete('/invoice-templates/{template}', [InvoiceTemplateController::class, 'destroy']);
            Route::post('/invoice-templates/{template}/set-default', [InvoiceTemplateController::class, 'setDefault']);
            Route::post('/invoice-templates/{template}/duplicate', [InvoiceTemplateController::class, 'duplicate']);
            Route::get('/invoice-templates/{template}/preview', [InvoiceTemplateController::class, 'preview']);

            // Contracts
            Route::get('/contracts', [ContractController::class, 'index']);
            Route::post('/contracts', [ContractController::class, 'store'])->middleware('plan.limit:contracts');
            Route::get('/contracts/{contract}', [ContractController::class, 'show']);
            Route::put('/contracts/{contract}', [ContractController::class, 'update']);
            Route::delete('/contracts/{contract}', [ContractController::class, 'destroy']);
            Route::post('/contracts/{contract}/send', [ContractController::class, 'send']);
            Route::post('/contracts/{contract}/reminder', [ContractController::class, 'sendReminder']);
            Route::post('/contracts/{contract}/resend-signature', [ContractController::class, 'resendSignature']);
            Route::post('/contracts/{contract}/request-signature', [ContractController::class, 'requestSignature']);
            Route::post('/contracts/{contract}/sign', [ContractController::class, 'sign']);
            Route::post('/contracts/{contract}/send-to-client', [ContractController::class, 'sendToClient']);
            Route::get('/contracts/{contract}/pdf', [ContractController::class, 'downloadPdf']);
            Route::get('/contracts/{contract}/signing-events', [\App\Http\Controllers\Api\SigningEventController::class, 'contractEvents']);

            // Clients
            Route::get('/clients', [ClientController::class, 'index']);
            Route::post('/clients', [ClientController::class, 'store'])->middleware('plan.limit:clients');
            Route::get('/clients/{client}', [ClientController::class, 'show']);
            Route::put('/clients/{client}', [ClientController::class, 'update']);
            Route::delete('/clients/{client}', [ClientController::class, 'destroy']);
            Route::post('/clients/{client}/invite', [ClientController::class, 'invite']);

            // Bills / Accounts Payable
            Route::get('/bills', [\App\Http\Controllers\Api\BillController::class, 'index']);
            Route::post('/bills', [\App\Http\Controllers\Api\BillController::class, 'store']);
            Route::get('/bills/{bill}', [\App\Http\Controllers\Api\BillController::class, 'show']);
            Route::put('/bills/{bill}', [\App\Http\Controllers\Api\BillController::class, 'update']);
            Route::post('/bills/{bill}/mark-paid', [\App\Http\Controllers\Api\BillController::class, 'markPaid']);
            Route::delete('/bills/{bill}', [\App\Http\Controllers\Api\BillController::class, 'destroy']);

            // Credit Notes
            Route::get('/credit-notes', [\App\Http\Controllers\Api\CreditNoteController::class, 'index']);
            Route::post('/credit-notes', [\App\Http\Controllers\Api\CreditNoteController::class, 'store']);
            Route::get('/credit-notes/{creditNote}', [\App\Http\Controllers\Api\CreditNoteController::class, 'show']);
            Route::post('/credit-notes/{creditNote}/void', [\App\Http\Controllers\Api\CreditNoteController::class, 'void']);

            // Quotes / Estimates
            Route::get('/quotes', [\App\Http\Controllers\Api\QuoteController::class, 'index']);
            Route::post('/quotes', [\App\Http\Controllers\Api\QuoteController::class, 'store']);
            Route::get('/quotes/{contract}', [\App\Http\Controllers\Api\QuoteController::class, 'show']);
            Route::put('/quotes/{contract}', [\App\Http\Controllers\Api\QuoteController::class, 'update']);
            Route::delete('/quotes/{contract}', [\App\Http\Controllers\Api\QuoteController::class, 'destroy']);
            Route::post('/quotes/{contract}/send', [\App\Http\Controllers\Api\QuoteController::class, 'send']);
            Route::post('/quotes/{contract}/respond', [\App\Http\Controllers\Api\QuoteController::class, 'respond']);
            Route::post('/quotes/{contract}/convert-to-invoice', [\App\Http\Controllers\Api\QuoteController::class, 'convertToInvoice']);

            // Product / Service Catalogue
            Route::get('/products', [\App\Http\Controllers\Api\ProductController::class, 'index']);
            Route::post('/products', [\App\Http\Controllers\Api\ProductController::class, 'store']);
            Route::get('/products/{product}', [\App\Http\Controllers\Api\ProductController::class, 'show']);
            Route::put('/products/{product}', [\App\Http\Controllers\Api\ProductController::class, 'update']);
            Route::delete('/products/{product}', [\App\Http\Controllers\Api\ProductController::class, 'destroy']);
            Route::post('/products/{product}/usage', [\App\Http\Controllers\Api\ProductController::class, 'recordUsage']);

            // Expenses
            Route::get('/expenses', [ExpenseController::class, 'index']);
            Route::post('/expenses', [ExpenseController::class, 'store'])->middleware('plan.limit:expenses');
            Route::get('/expenses/summary', [ExpenseController::class, 'summary']);
            Route::get('/expenses/{expense}', [ExpenseController::class, 'show']);
            Route::put('/expenses/{expense}', [ExpenseController::class, 'update']);
            Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);
            Route::get('/expenses/{expense}/receipt', [ExpenseController::class, 'downloadReceipt']);

            // Accounting
            Route::get('/accounting', [ExpenseController::class, 'accounting'])->middleware('plan.limit:accounting_dashboard');

            // Payments
            Route::get('/payments', [PaymentController::class, 'index']);
            Route::post('/payments/initiate', [PaymentController::class, 'initiate']);
            Route::post('/payments/manual', [PaymentController::class, 'recordManual']);
            Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt']);
            Route::get('/payments/gateway-status', [PaymentController::class, 'gatewayStatus']);
            Route::post('/payments/{payment}/retry-verify', [PaymentController::class, 'retryVerify']);

            // Payment Setup
            Route::get('/payment-setup', [PaymentSetupController::class, 'getStatus']);
            Route::post('/payment-setup', [PaymentSetupController::class, 'setupAccount']);
            Route::post('/payment-setup/initialize', [PaymentSetupController::class, 'initializePayment']);
            Route::post('/payment-setup/manual', [PaymentSetupController::class, 'recordManualPayment']);

            // Team Management
            Route::get('/team', [TeamController::class, 'index']);
            Route::post('/team/invite', [TeamController::class, 'invite'])->middleware('plan.limit:users');
            Route::put('/team/{member}/role', [TeamController::class, 'updateRole']);
            Route::delete('/team/{member}', [TeamController::class, 'remove']);

            // Data Export
            Route::get('/export/options', [ExportController::class, 'options']);
            Route::post('/export', [ExportController::class, 'export']);
            Route::get('/export/history', [ExportController::class, 'history']);
            Route::get('/export/{filename}', [ExportController::class, 'download']);
            Route::delete('/export/{filename}', [ExportController::class, 'delete']);
        });
    });

    /*
    |----------------------------------------------------------------------
    | Client Portal Routes
    |----------------------------------------------------------------------
    */

    Route::prefix('/client')->group(function () {
        Route::get('/dashboard', [ClientController::class, 'portalDashboard']);
        Route::get('/payments', [ClientController::class, 'portalPayments']);
        Route::get('/invoices', [ClientController::class, 'portalInvoices']);
        Route::get('/contracts', [ClientController::class, 'portalContracts']);
        Route::get('/invoices/{invoice}', [ClientController::class, 'showInvoice']);
        Route::get('/contracts/{contract}', [ClientController::class, 'showContract']);
        Route::get('/invoices/{invoice}/pdf', [ClientController::class, 'downloadInvoicePdf']);
        Route::get('/contracts/{contract}/pdf', [ClientController::class, 'downloadContractPdf']);
        Route::post('/contracts/{contract}/accept', [ClientController::class, 'acceptContract']);
        Route::post('/contracts/{contract}/reject', [ClientController::class, 'rejectContract']);
        Route::post('/invoices/{invoice}/pay', [ClientController::class, 'initiatePayment']);
        Route::post('/invoices/{invoice}/change-request', [ClientController::class, 'requestInvoiceChange']);
        Route::post('/invoices/{invoice}/sign', [InvoiceController::class, 'sign']);
        Route::post('/contracts/{contract}/sign', [ContractController::class, 'sign']);
        Route::get('/payments/{payment}/receipt', [PaymentController::class, 'clientReceipt']);
    });

    /*
    |----------------------------------------------------------------------
    | Admin Routes
    |----------------------------------------------------------------------
    */

    Route::middleware('admin')->prefix('/admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->middleware('admin.rate_limit:read');

        Route::get('/users', [AdminController::class, 'users'])->middleware('admin.rate_limit:read');
        Route::get('/users/{user}', [AdminController::class, 'showUser'])->middleware('admin.rate_limit:read');
        Route::post('/users/{user}/suspend', [AdminController::class, 'suspendUser'])->middleware('admin.permission:suspend_users', 'admin.rate_limit:critical');
        Route::post('/users/{user}/activate', [AdminController::class, 'activateUser'])->middleware('admin.permission:activate_users', 'admin.rate_limit:critical');
        Route::delete('/users/{user}', [AdminController::class, 'deleteUser'])->middleware('admin.permission:delete_users', 'admin.rate_limit:destructive');

        Route::get('/businesses', [AdminController::class, 'businesses'])->middleware('admin.rate_limit:read');
        Route::get('/businesses/{business}', [AdminController::class, 'showBusiness'])->middleware('admin.rate_limit:read');
        Route::post('/businesses/{business}/suspend', [AdminController::class, 'suspendBusiness'])->middleware('admin.permission:suspend_businesses', 'admin.rate_limit:critical');
        Route::post('/businesses/{business}/activate', [AdminController::class, 'activateBusiness'])->middleware('admin.permission:activate_businesses', 'admin.rate_limit:critical');
        Route::post('/businesses/{business}/change-plan', [AdminController::class, 'changePlan'])->middleware('admin.permission:change_plans', 'admin.rate_limit:critical');
        Route::delete('/businesses/{business}', [AdminController::class, 'deleteBusiness'])->middleware('admin.permission:delete_businesses', 'admin.rate_limit:destructive');

        Route::get('/demographics', [AdminController::class, 'demographics'])->middleware('admin.rate_limit:read');

        Route::get('/invoices', [AdminController::class, 'invoicesOverview'])->middleware('admin.rate_limit:read');
        Route::get('/invoices/{invoice}', [AdminController::class, 'getInvoice'])->middleware('admin.rate_limit:read');
        Route::get('/feedback', [AdminController::class, 'feedback'])->middleware('admin.permission:view_feedback', 'admin.rate_limit:read');
        Route::get('/activity-logs', [AdminController::class, 'activityLogs'])->middleware('admin.rate_limit:read');

        Route::get('/pricing', [AdminController::class, 'getPricing'])->middleware('admin.rate_limit:read');
        Route::put('/pricing', [AdminController::class, 'updatePricing'])->middleware('admin.permission:manage_pricing', 'admin.rate_limit:critical');

        Route::get('/settings', [AdminController::class, 'getSettings'])->middleware('admin.rate_limit:read');
        Route::put('/settings', [AdminController::class, 'updateSettings'])->middleware('admin.permission:manage_settings', 'admin.rate_limit:critical');
        Route::post('/clear-data', [AdminController::class, 'clearData'])->middleware('admin.permission:clear_data', 'admin.rate_limit:destructive');
        Route::post('/execute-data-clear', [AdminController::class, 'executeDataClear'])->middleware('admin.permission:clear_data', 'admin.rate_limit:destructive');

        // Database Management
        Route::get('/database/status', [AdminController::class, 'getDatabaseStatus'])->middleware('admin.permission:database_management', 'admin.rate_limit:critical');
        Route::post('/database/backup', [AdminController::class, 'backupDatabase'])->middleware('admin.permission:database_management', 'admin.rate_limit:critical');
        Route::post('/database/optimize', [AdminController::class, 'optimizeTables'])->middleware('admin.permission:database_management', 'admin.rate_limit:critical');

        Route::get('/waitlist', [\App\Http\Controllers\Api\WaitlistController::class, 'index'])->middleware('admin.rate_limit:read');
        Route::get('/waitlist/count', [\App\Http\Controllers\Api\WaitlistController::class, 'count'])->middleware('admin.rate_limit:read');

        // Promo Codes CRUD
        Route::get('/promo-codes', [PromoCodeController::class, 'index']);
        Route::post('/promo-codes', [PromoCodeController::class, 'store']);
        Route::get('/promo-codes/{promoCode}', [PromoCodeController::class, 'show']);
        Route::put('/promo-codes/{promoCode}', [PromoCodeController::class, 'update']);
        Route::delete('/promo-codes/{promoCode}', [PromoCodeController::class, 'destroy']);

        // User Security Management
        Route::post('/users/{user}/disable-2fa', [AdminController::class, 'disableUserTwoFactor']);
        Route::post('/users/{user}/reset-backup-codes', [AdminController::class, 'resetUserBackupCodes']);

        // Blog Posts CRUD
        Route::get('/posts', [AdminController::class, 'listPosts']);
        Route::post('/posts', [AdminController::class, 'createPost']);
        Route::get('/posts/{id}', [AdminController::class, 'showPost']);
        Route::put('/posts/{id}', [AdminController::class, 'updatePost']);
        Route::delete('/posts/{id}', [AdminController::class, 'deletePost']);
    });
});
require base_path('routes/test_log.php');
