<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Business;
use App\Models\Setting;
use App\Models\ActivityLog;
use App\Mail\PasswordResetMail;
use App\Services\SecurityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\PromoCodeController;
use App\Services\ActivityService;
use App\Services\AdminNotificationService;
use App\Services\SessionService;
use App\Services\TwoFactorService;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Password::min((int) Setting::get('password_min_length', 8))],
            'phone' => 'nullable|string|max:20',
            'business_name' => 'required|string|max:255',
            'business_email' => 'nullable|email',
            'business_phone' => 'nullable|string|max:20',
            'business_address' => 'nullable|string',
            'business_city' => 'nullable|string|max:100',
            'business_country' => 'required|string|in:Ghana',
            'business_tin' => 'nullable|string|max:50',
            'business_registration_number' => 'nullable|string|max:100',
            'business_industry' => 'nullable|string|max:100',
            'is_registered' => 'boolean',
            'referral_code' => 'nullable|string|max:20',
            'promo_code' => 'nullable|string|max:30',
            'start_trial' => 'nullable|boolean',
        ]);

        $verificationCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $notificationDefaults = Setting::get('notification_defaults');
        $defaultPrefs = [
            'invoice_paid' => true,
            'invoice_viewed' => true,
            'payment_overdue' => true,
            'new_client' => false,
            'weekly_summary' => true,
            'monthly_report' => true,
        ];
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'phone' => $request->phone,
            'role' => 'business_owner',
            'status' => 'active',
            'email_verification_code' => $verificationCode,
            'notification_preferences' => is_array($notificationDefaults) ? array_merge($defaultPrefs, $notificationDefaults) : $defaultPrefs,
        ]);

        // Send verification email
        try {
            Mail::to($user->email)->send(new \App\Mail\VerificationMail($verificationCode, $user->name));
        } catch (\Throwable $e) {
            \Log::warning('Failed to send verification email: ' . $e->getMessage());
        }

        // Send welcome email
        $frontend = rtrim(config('app.frontend_url'), '/');
        $loginUrl = $frontend ? $frontend . '/login' : '/login';
        try {
            Mail::to($user->email)->send(new \App\Mail\WelcomeMail($user->name, $request->business_name, $loginUrl));
        } catch (\Throwable $e) {
            \Log::warning('Failed to send welcome email: ' . $e->getMessage());
        }

        // Read admin-configurable trial days (default 14) from centralized settings
        $trialDays = (int) (Setting::get('trial_days', 14) ?? 14);
        $startTrial = (bool) $request->input('start_trial', false);

        $business = Business::create([
            'user_id' => $user->id,
            'name' => $request->business_name,
            'email' => $request->business_email ?? $request->email,
            'phone' => $request->business_phone ?? $request->phone,
            'address' => $request->business_address,
            'city' => $request->business_city,
            'country' => $request->business_country ?? 'Ghana',
            'tin' => $request->business_tin,
            'registration_number' => $request->business_registration_number,
            'industry' => $request->business_industry,
            'is_registered' => $request->is_registered ?? false,
            'status' => 'active',
            'plan' => 'free',
            'trial_ends_at' => $startTrial ? now()->addDays($trialDays) : null,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        // Apply referral if provided
        if ($request->referral_code) {
            ReferralController::applyReferral($user, $request->referral_code);
        }

        // Apply promo code if provided
        $promoResult = null;
        if ($request->promo_code) {
            $promoResult = PromoCodeController::applyAtRegistration($request->promo_code, $user, $business);
            if ($promoResult) {
                $business->refresh();
            }
        }

        // Log activity and create notifications
        ActivityService::logUserRegistered($user);
        ActivityService::logBusinessCreated($business);
        AdminNotificationService::notifyNewUser($user);
        AdminNotificationService::notifyNewBusiness($business);

        // Track device on registration
        try { DeviceTracker::track($user, $request); } catch (\Throwable $e) {}

        return response()->json([
            'message' => 'Registration successful',
            'user' => $user->load('businesses'),
            'business' => $business,
            'token' => $token,
            'email_verified' => !is_null($user->email_verified_at),
            'promo_applied' => $promoResult,
        ], 201);
    }

    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $first = $errors ? (reset($errors)[0] ?? null) : null;
            return response()->json([
                'message' => is_string($first) ? $first : 'Invalid input. Please check your email and password.',
                'errors' => $errors,
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        // Check if user is locked
        if ($user && $user->isLocked()) {
            $msg = 'Account temporarily locked due to too many failed attempts. Please try again later.';
            return response()->json([
                'message' => $msg,
                'errors' => ['email' => [$msg]],
            ], 422);
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
            // Increment login attempts for existing user
            if ($user) {
                $user->incrementLoginAttempts();
                ActivityService::log('auth.failed_login', "Failed login attempt for {$user->email}", $user);
                SecurityLogger::logFailedLogin($user->email, $request);

                if ($user->isLocked()) {
                    SecurityLogger::logAccountLocked($user, 'Too many failed login attempts', $request);
                }
            } else {
                SecurityLogger::logFailedLogin($request->email, $request);
            }

            $msg = 'The provided credentials are incorrect.';
            return response()->json([
                'message' => $msg,
                'errors' => ['email' => [$msg]],
            ], 422);
        }

        if ($user->status === 'suspended') {
            $msg = 'Your account has been suspended. Please contact support.';
            return response()->json([
                'message' => $msg,
                'errors' => ['email' => [$msg]],
            ], 403);
        }

        try {
            $user->resetLoginAttempts();
            $user->update(['last_login_at' => now()]);

            ActivityService::logUserLogin($user);

            // Create managed session
            $session = SessionService::createSession($user, 'Web Login');
            
            // Check for suspicious activity (temporarily disabled for testing)
            // $suspicious = SessionService::detectSuspiciousActivity($user);
            $suspicious = [];

            $response = [
                'message' => 'Login successful',
                'user' => $user,
                'token' => $session['token'],
                'email_verified' => !is_null($user->email_verified_at),
            ];

            if (!empty($suspicious)) {
                $response['security_warning'] = $suspicious;
            }

            if ($user->isBusinessOwner() || $user->isTeamMember()) {
                $owned = $user->businesses;
                $team = $user->teamBusinesses()->wherePivot('status', 'active')->get();
                $response['businesses'] = $owned->merge($team)->unique('id')->values();
            }

            if ($user->must_change_password) {
                $response['must_change_password'] = true;
            }

            return response()->json($response);
        } catch (\Throwable $e) {
            \Log::error('Login success path failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Unable to sign in. Please try again.',
                'errors' => ['email' => ['A temporary error occurred. Please try again.']],
            ], 500);
        }
    }

    public function clientLogin(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->where('role', 'client')->first();

        // Check if user is locked
        if ($user && $user->isLocked()) {
            throw ValidationException::withMessages([
                'email' => ['Account temporarily locked due to too many failed attempts. Please try again later.'],
            ]);
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
            if ($user) {
                $user->incrementLoginAttempts();
                SecurityLogger::logFailedLogin($user->email, $request);
            }
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials. Please use the login details sent to you.'],
            ]);
        }

        if ($user->status === 'suspended') {
            throw ValidationException::withMessages([
                'email' => ['Your portal access has been suspended.'],
            ]);
        }

        // Reset login attempts on successful login
        $user->resetLoginAttempts();

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('client-token', ['client'])->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'client_profiles' => $user->clientProfiles()->with('business:id,name,logo')->get(),
            'token' => $token,
            'must_change_password' => $user->must_change_password,
        ]);
    }

    /**
     * Current authenticated user and role-specific data.
     * Response shape: { user: User, businesses?: Business[], client_profiles?: ClientProfile[] }
     * - Always: user
     * - Business owners: businesses
     * - Clients: client_profiles
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $data = ['user' => $user];

        if ($user->isBusinessOwner() || $user->isTeamMember()) {
            $owned = $user->businesses;
            $team = $user->teamBusinesses()->wherePivot('status', 'active')->get();
            $data['businesses'] = $owned->merge($team)->unique('id')->values();
        } elseif ($user->isClient()) {
            $data['client_profiles'] = $user->clientProfiles()->with('business:id,name,logo')->get();
        }

        return response()->json($data);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        if ($request->hasFile('avatar')) {
            // Delete old avatar to prevent storage bloat
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar = $path;
        }

        $user->fill($request->only(['name', 'phone']));
        $user->save();

        return response()->json([
            'message' => 'Profile updated',
            'user' => $user,
            'avatar_url' => $user->avatar ? Storage::disk('public')->url($user->avatar) : null,
        ]);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required_without:force|string',
            'password' => ['required', 'confirmed', Password::min((int) Setting::get('password_min_length', 8))],
        ]);

        $user = $request->user();

        if (!$user->must_change_password && !Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update([
            'password' => $request->password,
            'must_change_password' => false,
        ]);

        return response()->json(['message' => 'Password changed successfully']);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Return success even if user not found (security best practice)
            return response()->json(['message' => 'If that email exists, a reset link has been sent.']);
        }

        // Generate token
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $resetUrl = config('app.frontend_url', 'http://localhost:3000') . '/reset-password?token=' . $token . '&email=' . urlencode($request->email);

        try {
            Mail::to($request->email)->send(new PasswordResetMail($resetUrl, $user->name));
        } catch (\Throwable $e) {
            \Log::warning('Failed to send password reset email: ' . $e->getMessage());
        }

        return response()->json(['message' => 'If that email exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => ['required', 'confirmed', Password::min((int) Setting::get('password_min_length', 8))],
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$record || !Hash::check($request->token, $record->token)) {
            throw ValidationException::withMessages([
                'token' => ['Invalid or expired reset token.'],
            ]);
        }

        // Check if token is older than 60 minutes
        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            throw ValidationException::withMessages([
                'token' => ['This reset link has expired. Please request a new one.'],
            ]);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['User not found.'],
            ]);
        }

        $user->update(['password' => $request->password]);

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        ActivityLog::log('user.password_reset', "User {$user->name} reset their password", $user);

        return response()->json(['message' => 'Password has been reset successfully. You can now log in.']);
    }

    public function verifyEmail(Request $request)
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email already verified.']);
        }

        if ($user->email_verification_code !== $request->code) {
            throw ValidationException::withMessages([
                'code' => ['Invalid verification code.'],
            ]);
        }

        $user->update([
            'email_verified_at' => now(),
            'email_verification_code' => null,
        ]);

        return response()->json(['message' => 'Email verified successfully.']);
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->update(['email_verification_code' => $code]);

        try {
            Mail::to($user->email)->send(new \App\Mail\VerificationMail($code, $user->name));
        } catch (\Throwable $e) {
            \Log::warning('Failed to send verification email: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Verification code sent.']);
    }

    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        DB::beginTransaction();
        try {
            // Delete all businesses owned by this user and their data
            $businesses = Business::where('user_id', $user->id)->get();
            foreach ($businesses as $business) {
                $business->invoices()->each(function ($invoice) {
                    $invoice->items()->delete();
                    $invoice->payments()->delete();
                    $invoice->delete();
                });
                $business->contracts()->delete();
                $business->clients()->delete();
                $business->expenses()->delete();
                $business->payments()->delete();

                // Delete related models that may not have been cascaded
                \App\Models\TeamMember::where('business_id', $business->id)->delete();
                \App\Models\ActivityLog::where('business_id', $business->id)->delete();
                \App\Models\Notification::where('business_id', $business->id)->delete();
                \App\Models\Subscription::where('business_id', $business->id)->delete();
                \App\Models\PromoCodeRedemption::where('business_id', $business->id)->delete();

                $business->delete();
            }

            // Delete user tokens and the user
            $user->tokens()->delete();
            $user->delete();

            DB::commit();
            return response()->json(['message' => 'Account and all data deleted successfully.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to delete account.'], 500);
        }
    }

    public function getNotificationPreferences(Request $request)
    {
        $defaults = [
            'invoice_paid' => true,
            'invoice_viewed' => true,
            'payment_overdue' => true,
            'new_client' => false,
            'weekly_summary' => true,
            'monthly_report' => true,
        ];

        $prefs = $request->user()->notification_preferences ?? $defaults;

        return response()->json(['preferences' => array_merge($defaults, $prefs)]);
    }

    public function updateNotificationPreferences(Request $request)
    {
        $request->validate([
            'preferences' => 'required|array',
        ]);

        $request->user()->update(['notification_preferences' => $request->preferences]);

        return response()->json(['message' => 'Notification preferences updated.', 'preferences' => $request->preferences]);
    }

    public function testNotification(Request $request)
    {
        $user = $request->user();

        try {
            $success = \App\Services\NotificationService::testEmail($user);

            if ($success) {
                return response()->json(['message' => 'Test notification sent successfully.']);
            } else {
                return response()->json(['message' => 'Failed to send test notification.'], 500);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to send test notification: ' . $e->getMessage()], 500);
        }
    }

    public function toggleTwoFactor(Request $request)
    {
        $user = $request->user();
        
        // If enabling 2FA, start verification process
        if (!$user->two_factor_enabled) {
            try {
                TwoFactorService::generateCode($user);
                return response()->json([
                    'message' => 'Verification code sent to your email. Please verify to enable 2FA.',
                    'status' => 'verification_required'
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Failed to send verification code. Please try again.'
                ], 500);
            }
        }
        
        // If disabling 2FA, require password confirmation
        $request->validate([
            'password' => 'required|string'
        ]);
        
        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid password'], 422);
        }
        
        TwoFactorService::disable($user);
        
        return response()->json([
            'message' => 'Two-factor authentication disabled.',
            'two_factor_enabled' => false,
        ]);
    }

    public function verifyTwoFactor(Request $request)
    {
        $request->validate([
            'code' => 'required|string|digits:6'
        ]);
        
        $user = $request->user();
        
        if (TwoFactorService::verifyCode($user, $request->code)) {
            TwoFactorService::enable($user);
            TwoFactorService::generateBackupCodes($user);
            
            return response()->json([
                'message' => 'Two-factor authentication enabled successfully.',
                'two_factor_enabled' => true,
                'has_backup_codes' => TwoFactorService::hasBackupCodes($user)
            ]);
        }
        
        return response()->json(['message' => 'Invalid verification code'], 422);
    }

    public function verifyTwoFactorBackup(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);
        
        $user = $request->user();
        
        if (TwoFactorService::verifyBackupCode($user, $request->code)) {
            return response()->json([
                'message' => 'Backup code verified successfully.',
                'has_backup_codes' => TwoFactorService::hasBackupCodes($user)
            ]);
        }
        
        return response()->json(['message' => 'Invalid backup code'], 422);
    }

    public function regenerateBackupCodes(Request $request)
    {
        $request->validate([
            'password' => 'required|string'
        ]);
        
        $user = $request->user();
        
        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid password'], 422);
        }
        
        TwoFactorService::generateBackupCodes($user);
        
        return response()->json([
            'message' => 'New backup codes have been sent to your email.',
            'has_backup_codes' => TwoFactorService::hasBackupCodes($user)
        ]);
    }

    public function activeSessions(Request $request)
    {
        $sessions = SessionService::getActiveSessions($request->user()->id);
        
        $currentTokenId = $request->user()->currentAccessToken()->id;
        
        $formattedSessions = array_map(function ($session) use ($currentTokenId) {
            return [
                'id' => $session['token_id'],
                'name' => $session['token_name'],
                'ip_address' => $session['ip_address'],
                'user_agent' => $session['user_agent'],
                'created_at' => $session['created_at'],
                'last_activity' => $session['last_activity'],
                'is_current' => $session['token_id'] === $currentTokenId,
            ];
        }, $sessions);

        return response()->json(['sessions' => $formattedSessions]);
    }

    public function revokeSession(Request $request, $tokenId)
    {
        $success = SessionService::revokeSession($request->user(), $tokenId);
        
        if (!$success) {
            return response()->json(['message' => 'Cannot revoke session'], 400);
        }

        return response()->json(['message' => 'Session revoked successfully']);
    }

    public function revokeAllOtherSessions(Request $request)
    {
        $revokedCount = SessionService::revokeAllOtherSessions($request->user());
        
        return response()->json([
            'message' => "Revoked {$revokedCount} other sessions",
            'revoked_count' => $revokedCount
        ]);
    }

    public function revokeAllSessions(Request $request)
    {
        $revokedCount = SessionService::revokeAllSessions($request->user());
        
        return response()->json([
            'message' => "Revoked {$revokedCount} sessions",
            'revoked_count' => $revokedCount
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
