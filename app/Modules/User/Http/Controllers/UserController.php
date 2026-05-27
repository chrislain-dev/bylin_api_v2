<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\ApiController;
use Modules\User\Models\User;
use Modules\User\Services\UserService;

/**
 * Admin User Management Controller.
 */
class UserController extends ApiController
{
    public function __construct(
        private UserService $userService
    ) {}

    /**
     * Count active super admins.
     */
    public function countSuperAdmins(Request $request): JsonResponse
    {
        $count = User::role('super_admin')
            ->where('status', 'active')
            ->count();

        return response()->json([
            'count' => $count,
        ]);
    }

    /**
     * List users.
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with('roles')
            ->when($request->filled('search'), fn ($q) => $q->search($request->string('search')->toString()))
            ->paginate((int) $request->input('per_page', 15));

        return $this->successResponse($users);
    }

    /**
     * Create user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'phone' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended', 'banned'])],
        ]);

        if (! $this->canAssignRole($request, $validated['role'])) {
            return $this->errorResponse('Seul un super admin peut attribuer le rôle super_admin.', 403);
        }

        $user = $this->userService->createUser($validated);

        return $this->createdResponse($user->load('roles.permissions'), 'User created successfully');
    }

    /**
     * Show user.
     */
    public function show(string $id): JsonResponse
    {
        $user = User::with('roles.permissions')->findOrFail($id);

        return $this->successResponse($user);
    }

    /**
     * Update user.
     */
    public function update(string $id, Request $request): JsonResponse
    {
        $user = User::with('roles')->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
            'phone' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended', 'banned'])],
        ]);

        if (isset($validated['role']) && ! $this->canAssignRole($request, $validated['role'])) {
            return $this->errorResponse('Seul un super admin peut attribuer le rôle super_admin.', 403);
        }

        if ($user->hasRole('super_admin') && ! $request->user()?->hasRole('super_admin')) {
            return $this->errorResponse('Seul un super admin peut modifier un super admin.', 403);
        }

        if ($user->id === Auth::id() && isset($validated['status']) && $validated['status'] !== 'active') {
            return $this->errorResponse('Vous ne pouvez pas désactiver votre propre compte.', 403);
        }

        $data = $validated;
        unset($data['role']);

        if (array_key_exists('password', $data)) {
            if ($data['password'] === null || $data['password'] === '') {
                unset($data['password']);
            } else {
                $data['password'] = Hash::make($data['password']);
            }
        }

        $user->update($data);

        if (isset($validated['role'])) {
            $user->syncRoles([$validated['role']]);
        }

        return $this->successResponse($user->fresh()->load('roles.permissions'), 'User updated successfully');
    }

    /**
     * Delete user.
     */
    public function destroy(string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->id === Auth::id()) {
            return $this->errorResponse('Cannot delete yourself', 403);
        }

        if ($user->hasRole('super_admin') && ! request()->user()?->hasRole('super_admin')) {
            return $this->errorResponse('Seul un super admin peut supprimer un super admin.', 403);
        }

        if ($user->hasRole('super_admin') && User::role('super_admin')->where('status', 'active')->count() <= 1) {
            return $this->errorResponse('Impossible de supprimer le dernier super admin actif.', 403);
        }

        $user->delete();

        return $this->successResponse(null, 'User deleted successfully');
    }

    private function canAssignRole(Request $request, string $role): bool
    {
        return $role !== 'super_admin' || $request->user()?->hasRole('super_admin') === true;
    }
}
