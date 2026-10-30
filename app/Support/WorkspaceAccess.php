<?php

namespace App\Support;

use App\Models\GitConnection;
use App\Models\Member;
use App\Models\Program;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * The single source of truth for workspace authorization. Every controller,
 * form request, query and resource that makes a role decision goes through
 * here. Five tiers:
 *
 * - Super-admin: the config-driven `manage-workspace` Gate. Belongs to no
 *   organization; sees and manages every organization.
 * - Office admin: a member with `is_office_admin` (and active). Full control
 *   inside their own organization: its teams, people, projects, programs and
 *   leaders.
 * - Program manager: `programs.manager_id` (while active, programs of their
 *   own organization only). On every project in the program, what its own
 *   manager can do, without sitting on its team; also creates projects in
 *   the program and chooses or replaces their managers, but never removes
 *   one and has no power over people.
 * - Team leader: the `role` column on the member_team pivot. Manages the
 *   roster of the teams they lead and sees their progress.
 * - Member: sees their teams' projects and teammates; manages own subtasks.
 *
 * Organizations are the tenant boundary. The only crossing is a team a
 * super-admin marked cross-organization: anyone may join it, and its members
 * see its projects (which stay in the team's home organization).
 */
class WorkspaceAccess
{
    /**
     * Memoized per user instance so list serialization asking per row (e.g.
     * `canArchiveProject` per project) does not re-query. Flushed after every
     * request (AppServiceProvider), so a user instance reused across requests,
     * as in tests, never sees stale roles.
     *
     * @var \WeakMap<Authenticatable, list<int>>|null
     */
    private static ?\WeakMap $ledTeamIdsMemo = null;

    /** @var \WeakMap<Authenticatable, list<int>>|null */
    private static ?\WeakMap $managedProgramIdsMemo = null;

    /** @var \WeakMap<Authenticatable, Member|false>|null false: the user has no member */
    private static ?\WeakMap $memberMemo = null;

    /** @var array<int, bool> organization id => whether it has a git connection */
    private static array $gitMemo = [];

    public static function flush(): void
    {
        self::$ledTeamIdsMemo = null;
        self::$managedProgramIdsMemo = null;
        self::$memberMemo = null;
        self::$gitMemo = [];
    }

    public static function isSuperAdmin(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows('manage-workspace');
    }

    /**
     * Whether an email is one PM_SUPER_ADMINS grants super-admin to. Only a
     * super-admin may give a login such an email: the Gate matches on email,
     * so anyone else doing it would be minting a super-admin.
     */
    public static function isSuperAdminEmail(?string $email): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        $needle = mb_strtolower(trim($email));

        foreach ((array) config('project-management.super_admins', []) as $superAdmin) {
            if (mb_strtolower(trim((string) $superAdmin)) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * The member linked to the user, or null. Never creates one (unlike
     * Member::forUser), so it is safe in authorization.
     */
    public static function memberFor(?Authenticatable $user): ?Member
    {
        if ($user === null) {
            return null;
        }

        self::$memberMemo ??= new \WeakMap;

        if (! isset(self::$memberMemo[$user])) {
            self::$memberMemo[$user] = Member::query()->where('user_id', $user->getAuthIdentifier())->first() ?? false;
        }

        $member = self::$memberMemo[$user];

        return $member === false ? null : $member;
    }

    /**
     * The organization the user administers, or null. A deactivated office
     * admin keeps the flag but loses the power.
     */
    public static function officeAdminOrganizationId(?Authenticatable $user): ?int
    {
        $member = self::memberFor($user);

        if ($member === null || ! $member->is_office_admin || ! $member->is_active || $member->organization_id === null) {
            return null;
        }

        return (int) $member->organization_id;
    }

    public static function isOfficeAdminOf(?Authenticatable $user, int|string|null $organizationId): bool
    {
        return $organizationId !== null && self::officeAdminOrganizationId($user) === (int) $organizationId;
    }

    /**
     * Full control inside an organization: a super-admin, or its office admin.
     */
    public static function administers(?Authenticatable $user, int|string|null $organizationId): bool
    {
        return self::isSuperAdmin($user) || self::isOfficeAdminOf($user, $organizationId);
    }

    public static function canManageOrganizations(?Authenticatable $user): bool
    {
        return self::isSuperAdmin($user);
    }

    /**
     * Connect an organization to a forge. Super-admin only.
     */
    public static function canManageGit(?Authenticatable $user): bool
    {
        return self::isSuperAdmin($user);
    }

    // ---- Project teams ----
    // A team is a project's own (one each); its one leader is the project's
    // manager. Teams are made with their project, never on their own.

    /**
     * Choose a project team's manager. Whether the team takes people from
     * other organizations is a super-admin decision alone.
     */
    public static function canEditTeam(?Authenticatable $user, Team $team): bool
    {
        return self::administers($user, $team->organization_id);
    }

    /**
     * Make someone a project team's manager (replacing the one it has):
     * whoever may edit the team, or the manager of the program its project
     * is in. Never removes a manager: see canRemoveFromTeam.
     */
    public static function canAppointProjectManager(?Authenticatable $user, Team $team): bool
    {
        return self::canEditTeam($user, $team) || self::managesProgramOfTeam($user, $team);
    }

    /**
     * Whether this person may be made the team's manager by the user (who
     * passes canAppointProjectManager): anyone who can join it for whoever
     * may edit the team; for a program manager, only people of the team's
     * own organization, even on a cross-organization team.
     */
    public static function canAppointAsProjectManager(?Authenticatable $user, Team $team, Member $member): bool
    {
        return self::canJoinTeam($member, $team)
            && (self::canEditTeam($user, $team) || (int) $member->organization_id === (int) $team->organization_id);
    }

    public static function leadsTeam(?Authenticatable $user, Team $team): bool
    {
        return in_array($team->getKey(), self::ledTeamIds($user), true);
    }

    /**
     * Add people to a team (by searching) and take them off it: the
     * project's manager, its program's manager, or whoever administers its
     * organization.
     */
    public static function canManageRosterOf(?Authenticatable $user, Team $team): bool
    {
        return self::canEditTeam($user, $team) || self::leadsTeam($user, $team) || self::managesProgramOfTeam($user, $team);
    }

    /**
     * canManageRosterOf for the project's own team, answered from the project
     * (its loaded team, program and organization) without a query per call:
     * the project twin; keep them in step. The program check is
     * managesProgramOf, which for a project's one team is what
     * managesProgramOfTeam asks of the database.
     */
    public static function canManageRosterOfProject(?Authenticatable $user, Project $project): bool
    {
        $team = $project->team();

        return $team !== null
            && (self::canEditTeam($user, $team) || self::leadsTeam($user, $team) || self::managesProgramOf($user, $project));
    }

    /**
     * A manager (or the program's manager) may take members off the team but
     * not its manager, who is replaced (canAppointProjectManager), never
     * removed.
     */
    public static function canRemoveFromTeam(?Authenticatable $user, Team $team, Member $member): bool
    {
        if (self::canEditTeam($user, $team)) {
            return true;
        }

        if (! self::leadsTeam($user, $team) && ! self::managesProgramOfTeam($user, $team)) {
            return false;
        }

        return ! $team->leaders()->whereKey($member->getKey())->exists();
    }

    public static function canViewTeamProgress(?Authenticatable $user, Team $team): bool
    {
        return self::canManageRosterOf($user, $team);
    }

    /**
     * Whether a person may sit on a team: anyone on a cross-organization team,
     * otherwise only people of the team's organization (and super-admins,
     * who belong to none).
     */
    public static function canJoinTeam(Member $member, Team $team): bool
    {
        if ($team->is_cross_organization) {
            return true;
        }

        if ($member->organization_id !== null) {
            return (int) $member->organization_id === (int) $team->organization_id;
        }

        return $member->user !== null && self::isSuperAdmin($member->user);
    }

    /**
     * Give a task of the project to someone not yet on its team, who joins
     * it as a member first: whoever manages the team's roster, for an active
     * person of the project's own organization (never an outsider, even on a
     * cross-organization team). Everyone already assignable is assigned as
     * always (Member::scopeAssignableFor). The predicate twin of
     * App\Support\Assignees::joinCandidates; keep them in step.
     */
    public static function canAssignOutsideTeam(?Authenticatable $user, Project $project, Member $member): bool
    {
        $team = $project->team();

        return $team !== null
            && $member->is_active
            && $member->organization_id !== null
            && (int) $member->organization_id === (int) $project->organization_id
            && self::canJoinTeam($member, $team)
            && self::canManageRosterOfProject($user, $project);
    }

    // ---- Members ----

    public static function canCreateMember(?Authenticatable $user, int|string|null $organizationId): bool
    {
        return self::administers($user, $organizationId);
    }

    /**
     * True when the user may edit this member's attributes (name, email,
     * password, active flag) or delete them: a super-admin anyone; an office
     * admin the people of their own organization except other office admins.
     * Team leaders manage rosters, never people, so a cross-organization
     * leader can never reset an outsider's password.
     *
     * A non-super-admin may never manage a member whose linked login is
     * itself a super-admin; this blocks the attach-then-password-reset
     * takeover.
     */
    public static function canManageMember(?Authenticatable $user, Member $member): bool
    {
        if (self::isSuperAdmin($user)) {
            return true;
        }

        if ($member->user_id !== null && $member->user !== null && self::isSuperAdmin($member->user)) {
            return false;
        }

        // Nor a person carrying a super-admin's email without a login yet:
        // giving them one would mint a super-admin.
        if (self::isSuperAdminEmail($member->email)) {
            return false;
        }

        $organizationId = self::officeAdminOrganizationId($user);

        if ($organizationId === null || (int) $member->organization_id !== $organizationId) {
            return false;
        }

        return ! $member->is_office_admin || $member->getKey() === self::memberFor($user)?->getKey();
    }

    // ---- Projects ----

    /**
     * The predicate twin of Project::scopeVisibleTo; keep them in step.
     */
    public static function canViewProject(?Authenticatable $user, Project $project): bool
    {
        if (self::isSuperAdmin($user)) {
            return true;
        }

        $member = self::memberFor($user);

        if ($member === null) {
            return false;
        }

        if (self::isOfficeAdminOf($user, $project->organization_id)) {
            return true;
        }

        if ($project->is_public && $member->organization_id !== null && (int) $member->organization_id === (int) $project->organization_id) {
            return true;
        }

        if (self::managesProgramOf($user, $project)) {
            return true;
        }

        return $project->teams()->whereHas('members', fn ($m) => $m->where('members.id', $member->getKey()))->exists();
    }

    /**
     * Creating a project means choosing its manager: the office admin's (or
     * a super-admin's) call, or a program manager's in their own programs
     * (see canCreateProjectInProgram).
     */
    public static function canCreateProject(?Authenticatable $user): bool
    {
        return self::isSuperAdmin($user) || self::officeAdminOrganizationId($user) !== null || self::managedProgramIds($user) !== [];
    }

    /**
     * Start the organization's first project, "General", with a task from
     * the task form: its organizational admin, while it has no active
     * project. A super-admin belongs to no organization and never does.
     */
    public static function canStartGeneralProject(?Authenticatable $user): bool
    {
        $organizationId = self::officeAdminOrganizationId($user);

        return $organizationId !== null
            && ! Project::query()->where('organization_id', $organizationId)->active()->exists();
    }

    /**
     * Whether the user creates projects only in programs they manage: a
     * program manager who administers no organization.
     */
    public static function createsProjectsOnlyInPrograms(?Authenticatable $user): bool
    {
        return ! self::isSuperAdmin($user) && self::officeAdminOrganizationId($user) === null && self::managedProgramIds($user) !== [];
    }

    /**
     * Create a project in this program: whoever administers its
     * organization, or its manager.
     */
    public static function canCreateProjectInProgram(?Authenticatable $user, Program $program): bool
    {
        return self::administers($user, $program->organization_id)
            || in_array((int) $program->getKey(), self::managedProgramIds($user), true);
    }

    /**
     * Make someone the project's manager: the project twin of
     * canAppointProjectManager (whose team shares the project's
     * organization), answered without loading the team; keep them in step.
     */
    public static function canChooseManagerOf(?Authenticatable $user, Project $project): bool
    {
        return self::administers($user, $project->organization_id) || self::managesProgramOf($user, $project);
    }

    public static function canManageProjectAccess(?Authenticatable $user, Project $project): bool
    {
        if (self::administers($user, $project->organization_id) || self::managesProgramOf($user, $project)) {
            return true;
        }

        $ledTeamIds = self::ledTeamIds($user);

        return $ledTeamIds !== []
            && $project->teams()->whereIn('teams.id', $ledTeamIds)->exists();
    }

    /**
     * The project's progress report (everyone's workload and activity on it)
     * is for the people who run the project, as a team's is for its leaders.
     */
    public static function canViewProjectProgress(?Authenticatable $user, Project $project): bool
    {
        return self::canManageProjectAccess($user, $project);
    }

    public static function canArchiveProject(?Authenticatable $user, Project $project): bool
    {
        return self::canManageProjectAccess($user, $project);
    }

    /**
     * Making a project public opens it to its whole organization, so only
     * the people who administer that organization may.
     */
    public static function canPublishProject(?Authenticatable $user, int|string|null $organizationId): bool
    {
        return self::administers($user, $organizationId);
    }

    /**
     * Renaming a project's key rewrites how its tasks are quoted in commits,
     * so only the people who administer its organization may.
     */
    public static function canChangeProjectKey(?Authenticatable $user, Project $project): bool
    {
        return self::administers($user, $project->organization_id);
    }

    // ---- Code (git) ----
    // Only where the project's organization has a git connection.

    /**
     * The project's linked repositories and its tasks' code: its team (any
     * role, while active), its program's manager, its organization's office
     * admins and super-admins. Not other viewers of a public project: code
     * is for the people doing the work. The predicate twin of
     * Project::scopeCodeVisibleTo; keep them in step.
     */
    public static function canViewProjectCode(?Authenticatable $user, Project $project): bool
    {
        if (! self::hasGit($project)) {
            return false;
        }

        if (self::administers($user, $project->organization_id)) {
            return true;
        }

        $member = self::memberFor($user);

        if ($member === null || ! $member->is_active) {
            return false;
        }

        if (self::managesProgramOf($user, $project)) {
            return true;
        }

        return $project->teams()->whereHas('members', fn ($m) => $m->where('members.id', $member->getKey()))->exists();
    }

    /**
     * Link and unlink repositories: whoever runs the project (its manager,
     * its program's manager, its office admin, a super-admin).
     */
    public static function canManageProjectRepositories(?Authenticatable $user, Project $project): bool
    {
        return self::hasGit($project) && self::canManageProjectAccess($user, $project);
    }

    private static function hasGit(Project $project): bool
    {
        $organizationId = (int) $project->organization_id;

        return self::$gitMemo[$organizationId] ??= GitConnection::query()->where('organization_id', $organizationId)->exists();
    }

    // ---- Programs ----
    // A named collection of projects in one organization with one manager,
    // who runs every project in it as its own manager does, from above:
    // without joining its team, choosing (never removing) its manager, no
    // power over people.

    /**
     * Create, edit and delete an organization's programs, and choose their
     * managers: whoever administers it.
     */
    public static function canManagePrograms(?Authenticatable $user, int|string|null $organizationId): bool
    {
        return self::administers($user, $organizationId);
    }

    /**
     * The predicate twin of Program::scopeVisibleTo; keep them in step.
     */
    public static function canViewProgram(?Authenticatable $user, Program $program): bool
    {
        return self::administers($user, $program->organization_id)
            || in_array((int) $program->getKey(), self::managedProgramIds($user), true);
    }

    /**
     * Whether the project is in a program the user manages. A project of
     * another organization never is, whatever its program_id says.
     */
    public static function managesProgramOf(?Authenticatable $user, Project $project): bool
    {
        return $project->program_id !== null
            && in_array((int) $project->program_id, self::managedProgramIds($user), true)
            && (int) $project->organization_id === (int) self::memberFor($user)?->organization_id;
    }

    /**
     * Whether the team works on a project in a program the user manages.
     */
    private static function managesProgramOfTeam(?Authenticatable $user, Team $team): bool
    {
        $programIds = self::managedProgramIds($user);
        $member = self::memberFor($user);

        return $programIds !== [] && $member !== null
            && Project::inPrograms($team->projects()->getQuery(), $programIds, $member)->exists();
    }

    /**
     * The ids of the programs the user's linked member manages, in their own
     * organization, while they are active (a deactivated or moved manager
     * keeps the row but loses the power). Memoized per user instance for the
     * request.
     *
     * @return list<int>
     */
    public static function managedProgramIds(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        self::$managedProgramIdsMemo ??= new \WeakMap;

        if (isset(self::$managedProgramIdsMemo[$user])) {
            return self::$managedProgramIdsMemo[$user];
        }

        $member = self::memberFor($user);

        $ids = $member === null || ! $member->is_active || $member->organization_id === null
            ? []
            : array_values(Program::query()
                ->where('manager_id', $member->getKey())
                ->where('organization_id', $member->organization_id)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all());

        return self::$managedProgramIdsMemo[$user] = $ids;
    }

    /**
     * The team ids the user's linked member leads. Memoized per user instance
     * for the request.
     *
     * @return list<int>
     */
    public static function ledTeamIds(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        self::$ledTeamIdsMemo ??= new \WeakMap;

        if (isset(self::$ledTeamIdsMemo[$user])) {
            return self::$ledTeamIdsMemo[$user];
        }

        $member = self::memberFor($user);

        $ids = $member === null
            ? []
            : array_values($member->ledTeams()->pluck('teams.id')->map(fn ($id): int => (int) $id)->all());

        return self::$ledTeamIdsMemo[$user] = $ids;
    }
}
