<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Models\TeamMember;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Services\TeamInviteService;
use App\Services\TeamRoleService;

class TeamController extends Controller
{
    public function index(Business $business)
    {
        $this->authorize('view', $business);

        $members = TeamMember::where('business_id', $business->id)
            ->with('user:id,name,email,avatar,status')
            ->get()
            ->map(function ($tm) {
                return [
                    'id' => $tm->id,
                    'user_id' => $tm->user_id,
                    'name' => $tm->user->name,
                    'email' => $tm->user->email,
                    'avatar' => $tm->user->avatar,
                    'role' => $tm->role,
                    'status' => $tm->status,
                    'joined_at' => $tm->created_at->toDateTimeString(),
                ];
            });

        return response()->json(['members' => $members]);
    }

    public function invite(Request $request, Business $business)
    {
        $this->authorize('update', $business);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'role' => 'required|in:admin,accountant,staff,viewer',
        ]);

        // Validate role assignment
        $errors = TeamRoleService::validateRoleAssignment(auth()->user(), $request->role, $business);
        if (!empty($errors)) {
            return response()->json([
                'message' => 'Role validation failed',
                'errors' => $errors
            ], 422);
        }

        // Check if user already exists and is already a team member
        $user = User::where('email', $request->email)->first();
        if ($user) {
            $existing = TeamMember::where('business_id', $business->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                return response()->json(['message' => 'User is already a team member'], 422);
            }

            if ($user->isAdmin()) {
                return response()->json(['message' => 'Admin users cannot be invited as team members'], 422);
            }
        }

        try {
            $invitation = TeamInviteService::createInvitation(
                $business,
                $request->email,
                $request->name,
                $request->role
            );

            return response()->json([
                'message' => 'Invitation sent successfully',
                'token' => $invitation['token'],
                'expires_at' => $invitation['expires_at']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to send invitation: ' . $e->getMessage()
            ], 500);
        }
    }

    public function acceptInvitation(Request $request)
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        try {
            $result = TeamInviteService::acceptInvitation($request->token, $request->user());
            
            return response()->json([
                'message' => 'Invitation accepted successfully',
                'user' => $result['user'],
                'business' => $result['business']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function getInvitation(Request $request)
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        $invitation = TeamInviteService::getInvitation($request->token);
        
        if (!$invitation) {
            return response()->json(['message' => 'Invalid or expired invitation'], 422);
        }

        return response()->json($invitation);
    }

    public function updateRole(Request $request, Business $business, TeamMember $member)
    {
        $this->authorize('update', $business);

        $request->validate([
            'role' => 'required|in:admin,accountant,staff,viewer',
        ]);

        // Ensure member belongs to this business
        if ($member->business_id !== $business->id) {
            return response()->json(['message' => 'Team member not found'], 404);
        }

        // Validate role modification
        if (!TeamRoleService::canModifyRole(auth()->user(), $member, $request->role)) {
            return response()->json(['message' => 'You do not have permission to modify this role'], 403);
        }

        $oldRole = $member->role;
        $member->update(['role' => $request->role]);

        // Log the role change
        TeamRoleService::logRoleChange(auth()->user(), $member, $oldRole, $request->role);

        return response()->json([
            'message' => 'Role updated successfully',
            'member' => [
                'id' => $member->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
                'role' => $member->role,
                'status' => $member->status,
            ],
        ]);
    }

    public function remove(Business $business, TeamMember $member)
    {
        $this->authorize('update', $business);

        if ($member->business_id !== $business->id) {
            return response()->json(['message' => 'Member not found'], 404);
        }

        // Cannot remove business owner
        if ($member->user->id === $business->user_id) {
            return response()->json(['message' => 'Cannot remove business owner'], 403);
        }

        // Cannot remove yourself
        if ($member->user_id === auth()->id()) {
            return response()->json(['message' => 'Cannot remove yourself from the team'], 403);
        }

        // Revoke all sessions for the removed user
        $revokedSessions = SessionService::revokeAllSessions($member->user);

        // Remove team membership
        $member->delete();

        // Log the removal
        SecurityLogger::logSecurityEvent(
            'team.member.removed',
            "Team member {$member->user->email} removed from {$business->name}. Revoked {$revokedSessions} sessions.",
            auth()->user()
        );

        ActivityLog::log(
            'team.removed',
            auth()->user()->name . " removed {$member->user->name} from the team",
            $business
        );

        return response()->json([
            'message' => 'Team member removed successfully',
            'revoked_sessions' => $revokedSessions
        ]);
    }
}
