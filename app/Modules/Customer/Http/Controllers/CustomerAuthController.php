<?php

declare(strict_types=1);

namespace Modules\Customer\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Modules\Customer\Models\Customer;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Validation\ValidationException;
use Modules\Customer\Services\CustomerService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Security\Services\LoginHistoryService;

/**
 * Customer Authentication Controller (SPA - HTTP-only cookies)
 */
class CustomerAuthController extends ApiController
{
    public function __construct(
        private CustomerService $customerService,
        private LoginHistoryService $loginHistoryService
    ) {}

    /**
     * Customer registration
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:customers,email',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $customer = $this->customerService->register($validated);

        // Auto-login after registration using customer guard
        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        // Merge guest cart if exists
        $this->mergeGuestCart($request, $customer->id);

        // Send welcome email
        \Modules\Notification\Jobs\SendWelcomeEmail::dispatch($customer);

        return $this->createdResponse([
            'customer' => $customer->fresh(),
        ], 'Registration successful');
    }

    /**
     * Customer login (cookie-based authentication)
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $customer = Customer::where('email', $validated['email'])->first();

        if (!$customer || !Hash::check($validated['password'], $customer->password)) {
            $this->loginHistoryService->recordFailedLogin($request->ip(), $validated['email']);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check customer status
        if ($customer->status !== 'active') {
            return $this->errorResponse('Account is not active', 403);
        }

        // Login using customer guard (creates session)
        Auth::guard('customer')->login($customer);

        // Regenerate session to prevent fixation
        $request->session()->regenerate();

        // Merge guest cart with customer cart if session_id is provided
        $this->mergeGuestCart($request, $customer->id);

        return response()->json([
            'customer' => $customer->fresh()->load(['addresses', 'roles.permissions'])
        ]);
    }

    /**
     * Merge guest cart into customer cart after login
     */
    private function mergeGuestCart(Request $request, string $customerId): void
    {
        $sessionId = $request->header('X-Session-ID');

        if (!$sessionId) {
            return; // No guest cart to merge
        }

        try {
            $cartService = app(\Modules\Cart\Services\CartService::class);

            // Get guest cart
            $guestCart = \Modules\Cart\Models\Cart::forSession($sessionId)->active()->first();

            if (!$guestCart || $guestCart->items->isEmpty()) {
                return; // No guest cart or empty
            }

            // Get or create customer cart
            $customerCart = $cartService->getCart($customerId);

            // Merge carts
            $cartService->mergeCarts($guestCart, $customerCart);

            Log::info('Guest cart merged into customer cart', [
                'customer_id' => $customerId,
                'session_id' => $sessionId,
                'items_merged' => $guestCart->items->count(),
            ]);
        } catch (\Exception $e) {
            // Don't fail login if cart merge fails
            Log::error('Failed to merge guest cart', [
                'customer_id' => $customerId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Logout (destroy session)
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('customer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->successResponse(null, 'Logged out successfully');
    }

    /**
     * Get authenticated customer
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->load(['addresses', 'roles.permissions']));
    }

    /**
     * Redirect to Google OAuth consent screen
     */
    public function googleRedirect(Request $request): JsonResponse
    {
        try {
            // Generate state parameter for CSRF protection
            $state = Str::random(40);
            $request->session()->put('oauth_state', $state);

            // Get Google authorization URL
            $authUrl = Socialite::driver('google')
                ->stateless()
                ->with(['state' => $state])
                ->redirect()
                ->getTargetUrl();

            return $this->successResponse([
                'auth_url' => $authUrl,
                'state' => $state,
            ]);
        } catch (\Exception $e) {
            Log::error('Google OAuth redirect failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Failed to initialize Google authentication', 500);
        }
    }

    /**
     * Handle Google OAuth callback
     */
    public function googleCallback(Request $request): JsonResponse
    {
        try {
            // Verify state parameter to prevent CSRF attacks
            $sessionState = $request->session()->get('oauth_state');
            $requestState = $request->query('state');

            if (!$sessionState || $sessionState !== $requestState) {
                Log::warning('OAuth state mismatch detected', [
                    'session_state' => $sessionState,
                    'request_state' => $requestState,
                    'ip' => $request->ip(),
                ]);

                return $this->errorResponse('Invalid OAuth state parameter. Possible CSRF attack.', 400);
            }

            // Clear state from session
            $request->session()->forget('oauth_state');

            // Get user info from Google
            $googleUser = Socialite::driver('google')->stateless()->user();

            if (!$googleUser->getEmail()) {
                return $this->errorResponse('Google account does not have an email address', 400);
            }

            // Check if customer exists by OAuth provider ID
            $customer = Customer::where('oauth_provider', 'google')
                ->where('oauth_provider_id', $googleUser->getId())
                ->first();

            if ($customer) {
                // Existing OAuth customer - just login
                return $this->loginOAuthCustomer($request, $customer, 'existing_oauth');
            }

            // Check if customer exists by email (account linking scenario)
            $customer = Customer::where('email', $googleUser->getEmail())->first();

            if ($customer) {
                // Account linking: Link Google OAuth to existing account
                $customer->linkOAuthProvider(
                    'google',
                    $googleUser->getId(),
                    $googleUser->getAvatar()
                );

                Log::info('Google account linked to existing customer', [
                    'customer_id' => $customer->id,
                    'email' => $customer->email,
                    'ip' => $request->ip(),
                ]);

                return $this->loginOAuthCustomer($request, $customer, 'account_linked');
            }

            // New customer registration via Google OAuth
            $names = $this->parseGoogleName($googleUser->getName());

            $customer = Customer::create([
                'first_name' => $names['first_name'],
                'last_name' => $names['last_name'],
                'email' => $googleUser->getEmail(),
                'email_verified_at' => now(), // Google emails are verified
                'password' => null, // OAuth-only account
                'oauth_provider' => 'google',
                'oauth_provider_id' => $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar(),
                'status' => 'active',
            ]);

            Log::info('New customer registered via Google OAuth', [
                'customer_id' => $customer->id,
                'email' => $customer->email,
                'ip' => $request->ip(),
            ]);

            // Send welcome email
            \Modules\Notification\Jobs\SendWelcomeEmail::dispatch($customer);

            return $this->loginOAuthCustomer($request, $customer, 'new_registration');

        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            Log::error('Google OAuth invalid state exception', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return $this->errorResponse('OAuth session expired. Please try again.', 400);
        } catch (\Exception $e) {
            Log::error('Google OAuth callback failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip(),
            ]);

            return $this->errorResponse('Google authentication failed. Please try again.', 500);
        }
    }

    /**
     * Login OAuth customer and setup session
     */
    private function loginOAuthCustomer(Request $request, Customer $customer, string $loginType): JsonResponse
    {
        // Check customer status
        if ($customer->status !== 'active') {
            return $this->errorResponse('Account is not active', 403);
        }

        // Login using customer guard (creates session)
        Auth::guard('customer')->login($customer);

        // Regenerate session to prevent fixation
        $request->session()->regenerate();

        // Merge guest cart with customer cart if session_id is provided
        $this->mergeGuestCart($request, $customer->id);

        // Log activity
        Log::info('Customer logged in via Google OAuth', [
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'login_type' => $loginType,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $message = match ($loginType) {
            'new_registration' => 'Account created and logged in successfully',
            'account_linked' => 'Google account linked and logged in successfully',
            default => 'Logged in successfully',
        };

        return $this->successResponse([
            'customer' => $customer->fresh()->load(['addresses']),
            'login_type' => $loginType,
        ], $message);
    }

    /**
     * Handle Google ID Token verification (from frontend Google Sign-In button)
     * This is the preferred method for SPA applications using Google Identity Services
     */
    public function googleIdToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential' => 'required|string',
        ]);

        try {
            $idToken = $validated['credential'];

            // Verify the ID token with Google's tokeninfo endpoint using Laravel HTTP
            $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken,
            ]);

            if (!$response->successful()) {
                Log::warning('Google ID token verification failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'ip' => $request->ip(),
                ]);
                return $this->errorResponse('Invalid Google token', 401);
            }

            $googleData = $response->json();

            // Verify the token is for our app
            $expectedClientId = config('services.google.client_id');
            if (($googleData['aud'] ?? null) !== $expectedClientId) {
                Log::warning('Google ID token audience mismatch', [
                    'expected' => $expectedClientId,
                    'received' => $googleData['aud'] ?? 'null',
                    'ip' => $request->ip(),
                ]);
                return $this->errorResponse('Token not issued for this application', 401);
            }

            // Extract user information from the verified token
            $googleId = $googleData['sub'] ?? null;
            $email = $googleData['email'] ?? null;
            $emailVerified = ($googleData['email_verified'] ?? 'false') === 'true';
            $name = $googleData['name'] ?? null;
            $picture = $googleData['picture'] ?? null;

            if (!$email || !$googleId) {
                return $this->errorResponse('Invalid token data', 400);
            }

            // Check if customer exists by OAuth provider ID
            $customer = Customer::where('oauth_provider', 'google')
                ->where('oauth_provider_id', $googleId)
                ->first();

            if ($customer) {
                // Existing OAuth customer - just login
                return $this->loginOAuthCustomer($request, $customer, 'existing_oauth');
            }

            // Check if customer exists by email (account linking scenario)
            $customer = Customer::where('email', $email)->first();

            if ($customer) {
                // Account linking: Link Google OAuth to existing account
                $customer->linkOAuthProvider('google', $googleId, $picture);

                Log::info('Google account linked to existing customer via ID Token', [
                    'customer_id' => $customer->id,
                    'email' => $customer->email,
                    'ip' => $request->ip(),
                ]);

                return $this->loginOAuthCustomer($request, $customer, 'account_linked');
            }

            // New customer registration via Google OAuth
            $names = $this->parseGoogleName($name);

            $customer = Customer::create([
                'first_name' => $names['first_name'],
                'last_name' => $names['last_name'],
                'email' => $email,
                'email_verified_at' => $emailVerified ? now() : null,
                'password' => null, // OAuth-only account
                'oauth_provider' => 'google',
                'oauth_provider_id' => $googleId,
                'avatar_url' => $picture,
                'status' => 'active',
            ]);

            Log::info('New customer registered via Google ID Token', [
                'customer_id' => $customer->id,
                'email' => $customer->email,
                'ip' => $request->ip(),
            ]);

            // Send welcome email
            \Modules\Notification\Jobs\SendWelcomeEmail::dispatch($customer);

            return $this->loginOAuthCustomer($request, $customer, 'new_registration');

        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error('Google ID token verification request failed', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return $this->errorResponse('Failed to verify Google token. Please try again.', 500);
        } catch (\Exception $e) {
            Log::error('Google ID Token authentication failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip(),
            ]);

            return $this->errorResponse('Google authentication failed. Please try again.', 500);
        }
    }

    /**
     * Parse Google full name into first and last name
     */
    private function parseGoogleName(?string $fullName): array
    {
        if (!$fullName) {
            return ['first_name' => 'User', 'last_name' => 'Google'];
        }

        $parts = explode(' ', trim($fullName), 2);

        return [
            'first_name' => $parts[0] ?? 'User',
            'last_name' => $parts[1] ?? 'Google',
        ];
    }
}