<?php

declare(strict_types=1);

namespace Modules\Settings\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Core\Services\BaseService;
use Modules\Settings\Mail\MemberInvitationMail;
use Modules\Settings\Models\Invitation;
use Modules\User\Models\User;

class MemberService extends BaseService
{
    private const ASSIGNABLE_ROLES = ['admin', 'manager', 'super_admin'];
    private const INVITABLE_ROLES = ['admin', 'manager'];
    private const SORTABLE_COLUMNS = ['name', 'email', 'status', 'created_at', 'last_login_at'];

    /**
     * Récupérer la liste des membres avec filtres.
     */
    public function getMembers(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));

        $query = User::query()
            ->with(['invited_by', 'roles', 'permissions']);

        if (! empty($filters['search'])) {
            $search = mb_strtolower((string) $filters['search']);

            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"])
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        if (! empty($filters['role'])) {
            $query->whereHas('roles', function ($q) use ($filters) {
                $q->where('name', $filters['role']);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $sortBy = in_array(($filters['sort_by'] ?? null), self::SORTABLE_COLUMNS, true)
            ? $filters['sort_by']
            : 'created_at';

        $sortDirection = ($filters['sort_direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortBy, $sortDirection)->paginate($perPage);
    }

    /**
     * Récupérer un membre par ID.
     */
    public function getMember(string $id): User
    {
        return User::with(['invited_by', 'roles', 'permissions'])->findOrFail($id);
    }

    /**
     * Créer un nouveau membre.
     */
    public function createMember(array $data): User
    {
        return $this->transaction(function () use ($data) {
            $role = $data['role'];
            $this->assertAssignableRole($role);

            $member = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password'] ?? Str::password(24)),
                'status' => 'active',
                'invited_by_id' => Auth::id(),
                'invited_at' => now(),
            ]);

            $member->assignRole($role);

            if ($data['send_invitation'] ?? false) {
                $this->sendWelcomeEmail($member);
            }

            $this->logInfo('Membre créé', [
                'member_id' => $member->id,
                'created_by' => Auth::id(),
            ]);

            return $member->fresh(['roles', 'permissions']);
        });
    }

    /**
     * Mettre à jour un membre.
     */
    public function updateMember(string $id, array $data): User
    {
        return $this->transaction(function () use ($id, $data) {
            $member = User::findOrFail($id);
            $this->assertCanMutateMember($member);

            $payload = [];

            foreach (['name', 'phone', 'status'] as $field) {
                if (array_key_exists($field, $data)) {
                    $payload[$field] = $data[$field];
                }
            }

            if (array_key_exists('email', $data)) {
                $payload['email'] = Str::lower($data['email']);
            }

            if (($payload['status'] ?? null) !== null && $member->hasRole('super_admin')) {
                $this->assertNotLastActiveSuperAdmin($member, $payload['status']);
            }

            if ($payload !== []) {
                $member->update($payload);
            }

            $this->logInfo('Membre mis à jour', [
                'member_id' => $member->id,
                'updated_by' => Auth::id(),
            ]);

            return $member->fresh(['roles', 'permissions']);
        });
    }

    /**
     * Supprimer un membre.
     */
    public function deleteMember(string $id): void
    {
        $this->transaction(function () use ($id) {
            $member = User::findOrFail($id);
            $this->assertCanMutateMember($member);

            if ($member->hasRole('super_admin')) {
                $this->assertNotLastActiveSuperAdmin($member, 'deleted');
            }

            $member->delete();

            $this->logInfo('Membre supprimé', [
                'member_id' => $member->id,
                'deleted_by' => Auth::id(),
            ]);
        });
    }

    /**
     * Mettre à jour le rôle d'un membre.
     */
    public function updateMemberRole(string $id, array $data): User
    {
        return $this->transaction(function () use ($id, $data) {
            $member = User::findOrFail($id);
            $this->assertCanMutateMember($member);
            $this->assertAssignableRole($data['role']);

            if ($member->hasRole('super_admin') && $data['role'] !== 'super_admin') {
                $this->assertNotLastActiveSuperAdmin($member, 'role_changed');
            }

            $member->syncRoles([$data['role']]);

            $this->logInfo('Rôle du membre mis à jour', [
                'member_id' => $member->id,
                'new_role' => $data['role'],
                'updated_by' => Auth::id(),
            ]);

            return $member->fresh(['roles', 'permissions']);
        });
    }

    /**
     * Mettre à jour le statut d'un membre.
     */
    public function updateMemberStatus(string $id, array $data): User
    {
        return $this->transaction(function () use ($id, $data) {
            $member = User::findOrFail($id);
            $this->assertCanMutateMember($member);

            if ($member->hasRole('super_admin')) {
                $this->assertNotLastActiveSuperAdmin($member, $data['status']);
            }

            $member->update([
                'status' => $data['status'],
            ]);

            $this->logInfo('Statut du membre mis à jour', [
                'member_id' => $member->id,
                'new_status' => $data['status'],
                'reason' => $data['reason'] ?? null,
                'updated_by' => Auth::id(),
            ]);

            return $member->fresh(['roles', 'permissions']);
        });
    }

    /**
     * Statistiques des membres.
     */
    public function getStatistics(): array
    {
        $total = User::count();
        $active = User::where('status', 'active')->count();
        $inactive = User::where('status', 'inactive')->count();
        $suspended = User::where('status', 'suspended')->count();

        $admins = $this->countUsersWithRole('admin');
        $superAdmins = $this->countUsersWithRole('super_admin');
        $managers = $this->countUsersWithRole('manager');

        $loggedInToday = User::whereDate('last_login_at', today())->count();
        $loggedInThisWeek = User::whereBetween('last_login_at', [now()->startOfWeek(), now()->endOfWeek()])->count();
        $loggedInThisMonth = User::whereBetween('last_login_at', [now()->startOfMonth(), now()->endOfMonth()])->count();

        $newThisWeek = User::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count();
        $newThisMonth = User::whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();

        $pendingInvitations = Invitation::pending()->count();
        $acceptedInvitations = Invitation::accepted()->count();
        $expiredInvitations = Invitation::expired()->count();

        return [
            'total_members' => $total,
            'active_members' => $active,
            'inactive_members' => $inactive,
            'suspended_members' => $suspended,
            'admins_count' => $admins,
            'super_admins_count' => $superAdmins,
            'managers_count' => $managers,
            'online_now' => 0,
            'logged_in_today' => $loggedInToday,
            'logged_in_this_week' => $loggedInThisWeek,
            'logged_in_this_month' => $loggedInThisMonth,
            'pending_invitations' => $pendingInvitations,
            'accepted_invitations' => $acceptedInvitations,
            'expired_invitations' => $expiredInvitations,
            'new_this_week' => $newThisWeek,
            'new_this_month' => $newThisMonth,
        ];
    }

    /**
     * Inviter un membre.
     */
    public function inviteMember(array $data): Invitation
    {
        return $this->transaction(function () use ($data) {
            $role = $data['role'];

            if (! in_array($role, self::INVITABLE_ROLES, true)) {
                throw new AuthorizationException('Ce rôle ne peut pas être attribué par invitation.');
            }

            $email = Str::lower($data['email']);

            if (User::where('email', $email)->exists()) {
                throw new \InvalidArgumentException('Un utilisateur existe déjà avec cet email.');
            }

            if (Invitation::pending()->where('email', $email)->exists()) {
                throw new \InvalidArgumentException('Une invitation active existe déjà pour cet email.');
            }

            $invitation = Invitation::create([
                'email' => $email,
                'name' => $data['name'] ?? null,
                'role' => $role,
                'token' => $this->generateUniqueInvitationToken(),
                'message' => $data['message'] ?? null,
                'invited_by_id' => Auth::id(),
                'expires_at' => now()->addDays(7),
            ]);

            Mail::to($invitation->email)->send(new MemberInvitationMail($invitation));

            $this->logInfo('Invitation envoyée', [
                'invitation_id' => $invitation->id,
                'invited_by' => Auth::id(),
            ]);

            return $invitation;
        });
    }

    /**
     * Inviter plusieurs membres.
     */
    public function bulkInviteMembers(array $data): array
    {
        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($data['invitations'] as $invitationData) {
            try {
                $this->inviteMember($invitationData);
                $successCount++;
            } catch (\Throwable $e) {
                $errorCount++;
                $errors[] = [
                    'email' => $invitationData['email'],
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors,
        ];
    }

    /**
     * Récupérer les invitations.
     */
    public function getInvitations(int $perPage = 15): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));

        return Invitation::with(['invited_by'])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Renvoyer une invitation.
     */
    public function resendInvitation(string $id): void
    {
        $invitation = Invitation::findOrFail($id);

        if ($invitation->isAccepted()) {
            throw new \InvalidArgumentException('Cette invitation a déjà été acceptée.');
        }

        if (User::where('email', $invitation->email)->exists()) {
            throw new \InvalidArgumentException('Un utilisateur existe déjà avec cet email.');
        }

        $invitation->update([
            'token' => $this->generateUniqueInvitationToken(),
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($invitation->email)->send(new MemberInvitationMail($invitation));

        $this->logInfo('Invitation renvoyée', [
            'invitation_id' => $invitation->id,
        ]);
    }

    /**
     * Annuler une invitation.
     */
    public function cancelInvitation(string $id): void
    {
        $invitation = Invitation::findOrFail($id);

        if ($invitation->isAccepted()) {
            throw new \InvalidArgumentException('Cette invitation a déjà été acceptée et ne peut pas être annulée.');
        }

        $invitation->delete();

        $this->logInfo('Invitation annulée', [
            'invitation_id' => $invitation->id,
        ]);
    }

    /**
     * Envoyer un email de bienvenue.
     */
    private function sendWelcomeEmail(User $member): void
    {
        // L'invitation email dédiée est gérée par inviteMember().
        // Gardé volontairement sans effet pour éviter l'envoi d'un email incomplet.
    }

    private function assertAssignableRole(string $role): void
    {
        if (! in_array($role, self::ASSIGNABLE_ROLES, true)) {
            throw new AuthorizationException('Ce rôle ne peut pas être attribué.');
        }

        if ($role === 'super_admin' && ! Auth::user()?->hasRole('super_admin')) {
            throw new AuthorizationException('Seul un super admin peut attribuer le rôle super admin.');
        }
    }

    private function assertCanMutateMember(User $member): void
    {
        if ($member->id === Auth::id()) {
            throw new AuthorizationException('Vous ne pouvez pas modifier ou supprimer votre propre compte depuis cet écran.');
        }

        if ($member->hasRole('super_admin') && ! Auth::user()?->hasRole('super_admin')) {
            throw new AuthorizationException('Seul un super admin peut modifier un autre super admin.');
        }
    }

    private function assertNotLastActiveSuperAdmin(User $member, string $nextState): void
    {
        if (in_array($nextState, ['active', 'super_admin'], true)) {
            return;
        }

        $activeSuperAdmins = User::role('super_admin')
            ->where('status', 'active')
            ->whereKeyNot($member->getKey())
            ->count();

        if ($activeSuperAdmins < 1) {
            throw new AuthorizationException('Impossible de désactiver, rétrograder ou supprimer le dernier super admin actif.');
        }
    }

    private function generateUniqueInvitationToken(): string
    {
        do {
            $token = Str::random(80);
        } while (Invitation::where('token', $token)->exists());

        return $token;
    }
}
