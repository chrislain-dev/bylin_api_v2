<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Modules\User\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\User\Services\UserService;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Security\Services\LoginHistoryService;

/**
 * Authentication Controller for Admin Users (SPA - HTTP-only cookies)
 */
class AuthController extends ApiController
{
    public function __construct(
        private UserService $userService,
        private LoginHistoryService $loginHistoryService
    ) {}

    /**
     * Admin login (cookie-based authentication)
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            if ($this->loginHistoryService->isLockedOut($request->ip())) {
                return $this->errorResponse(
                    'Trop de tentatives de connexion échouées. Veuillez réessayer plus tard.',
                    429
                );
            }

            $email = mb_strtolower($validated['email']);
            $user = User::where('email', $email)->first();

            // Vérification des identifiants
            if (!$user || !Hash::check($validated['password'], $user->password)) {
                $this->loginHistoryService->recordFailedLogin($request->ip(), $email);

                throw ValidationException::withMessages([
                    'email' => ['Les identifiants fournis sont incorrects.'],
                ]);
            }

            // Vérification du statut du compte
            if ($user->status !== 'active') {
                $this->loginHistoryService->recordFailedLogin($request->ip(), $email, 'Compte inactif');

                return $this->errorResponse(
                    'Votre compte n\'est pas actif. Veuillez contacter l\'administrateur.',
                    403
                );
            }

            // Vérification si le compte est verrouillé (optionnel)
            if (method_exists($user, 'isLocked') && $user->isLocked()) {
                $this->loginHistoryService->recordFailedLogin($request->ip(), $email, 'Compte verrouillé');

                return $this->errorResponse(
                    'Votre compte a été temporairement verrouillé en raison de tentatives de connexion échouées.',
                    423
                );
            }

            // Connexion avec le guard web (crée une session)
            Auth::guard('web')->login($user);

            // Régénération de la session pour prévenir la fixation
            $request->session()->regenerate();

            // Enregistrement de la connexion réussie
            $user->forceFill(['last_login_at' => now()])->save();

            $this->loginHistoryService->recordSuccessfulLogin($user, $request->ip(), $request->userAgent());

            // Réponse sans wrapper successResponse
            return response()->json(
                $user->load('roles.permissions')
            );
        } catch (ValidationException $e) {
            // Les erreurs de validation sont déjà gérées
            throw $e;
        } catch (Exception $e) {
            Log::error('Erreur lors de la connexion admin', [
                'email_hash' => isset($validated['email']) ? hash('sha256', mb_strtolower($validated['email'])) : null,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Une erreur est survenue lors de la connexion. Veuillez réessayer.',
                500
            );
        }
    }

    /**
     * Envoyer un lien de réinitialisation par email
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
            ], [
                'email.required' => 'L\'adresse e-mail est obligatoire.',
                'email.email' => 'L\'adresse e-mail doit être valide.',
            ]);

            $credentials = ['email' => mb_strtolower($validated['email'])];
            $status = Password::sendResetLink($credentials);

            Log::info('Demande de réinitialisation de mot de passe admin', [
                'email_hash' => hash('sha256', $credentials['email']),
                'ip' => $request->ip(),
                'status' => $status,
            ]);

            return $this->successResponse(
                null,
                'Si ce compte existe, un lien de réinitialisation a été envoyé à votre adresse e-mail.'
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Erreur lors de l\'envoi du lien de réinitialisation', [
                'email_hash' => isset($validated['email']) ? hash('sha256', mb_strtolower($validated['email'])) : null,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Une erreur est survenue lors de l\'envoi du lien.',
                500
            );
        }
    }

    /**
     * Réinitialiser le mot de passe avec le token
     */
    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'token' => 'required|string',
                'email' => 'required|email',
                'password' => 'required|string|min:8|confirmed',
            ], [
                'token.required' => 'Le token est obligatoire.',
                'email.required' => 'L\'adresse e-mail est obligatoire.',
                'email.email' => 'L\'adresse e-mail doit être valide.',
                'password.required' => 'Le mot de passe est obligatoire.',
                'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
                'password.confirmed' => 'Les mots de passe ne correspondent pas.',
            ]);

            // Réinitialiser le mot de passe
            $status = Password::reset(
                $validated,
                function (User $user, string $password) use ($request) {
                    $user->forceFill([
                        'password' => Hash::make($password)
                    ])->save();

                    // Révoquer toutes les sessions existantes
                    $user->tokens()->delete();

                    Log::info('Mot de passe admin réinitialisé avec succès', [
                        'user_id' => $user->id,
                        'ip' => $request->ip(),
                    ]);
                }
            );

            // Gérer les différents statuts
            return match ($status) {
                Password::PASSWORD_RESET => $this->successResponse(
                    null,
                    'Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.'
                ),
                Password::INVALID_TOKEN => $this->errorResponse(
                    'Le lien de réinitialisation est invalide ou a expiré. Veuillez demander un nouveau lien.',
                    422
                ),
                Password::INVALID_USER => $this->errorResponse(
                    'Aucun utilisateur trouvé avec cette adresse e-mail.',
                    404
                ),
                default => $this->errorResponse(
                    'Une erreur est survenue lors de la réinitialisation du mot de passe.',
                    500
                ),
            };
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Erreur lors de la réinitialisation du mot de passe', [
                'email_hash' => isset($validated['email']) ? hash('sha256', mb_strtolower($validated['email'])) : null,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Une erreur est survenue lors de la réinitialisation.',
                500
            );
        }
    }

    /**
     * Logout (destroy session)
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Déconnexion du guard web
            Auth::guard('web')->logout();

            // Invalidation et régénération de la session
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Log optionnel de la déconnexion
            if ($user) {
                $this->loginHistoryService->recordLogout($user);

                Log::info('Déconnexion admin réussie', [
                    'user_id' => $user->id,
                    'ip' => $request->ip(),
                ]);
            }

            return $this->successResponse(null, 'Déconnexion réussie.');
        } catch (Exception $e) {
            Log::error('Erreur lors de la déconnexion', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Une erreur est survenue lors de la déconnexion.',
                500
            );
        }
    }

    /**
     * Get authenticated user (for nuxt-auth-sanctum)
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->errorResponse(
                    'Non authentifié.',
                    401
                );
            }

            return response()->json(
                $user->load('roles.permissions')
            );
        } catch (Exception $e) {
            Log::error('Erreur lors de la récupération de l\'utilisateur', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse(
                'Une erreur est survenue lors de la récupération de vos informations.',
                500
            );
        }
    }

    /**
     * Refresh user data (alternative endpoint avec successResponse)
     * Ce endpoint peut être utilisé par d'autres parties de votre app
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->errorResponse(
                    'Non authentifié.',
                    401
                );
            }

            $freshUser = $user->fresh()->load('roles.permissions');

            if (!$freshUser) {
                return $this->errorResponse(
                    'Utilisateur introuvable.',
                    404
                );
            }

            return $this->successResponse(
                $freshUser,
                'Données utilisateur actualisées.'
            );
        } catch (Exception $e) {
            Log::error('Erreur lors du rafraîchissement des données utilisateur', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Une erreur est survenue lors de l\'actualisation de vos données.',
                500
            );
        }
    }

    /**
     * Vérifier si l'email existe (pour le formulaire de connexion)
     * Endpoint optionnel pour améliorer l'UX
     */
    public function checkEmail(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
            ], [
                'email.required' => 'L\'adresse e-mail est obligatoire.',
                'email.email' => 'L\'adresse e-mail doit être valide.',
            ]);

            $exists = User::where('email', $validated['email'])->exists();

            return response()->json([
                'exists' => $exists
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Erreur lors de la vérification de l\'email', [
                'email' => $validated['email'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse(
                'Une erreur est survenue lors de la vérification.',
                500
            );
        }
    }
}
