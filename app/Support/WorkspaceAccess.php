<?php

namespace App\Support;

use App\Models\AttendanceSetting;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\LeaveRequest;
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
 * Inside an organization, reporting lines (`members.reports_to_id`, see
 * OrgChart) decide who sees whose attendance and work: the person,
 * everyone above them, its admins and super-admins. They give no power
 * over projects.
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

    /** @var array<int, bool> organization id => whether its attendance is on */
    private static array $attendanceMemo = [];

    public static function flush(): void
    {
        self::$ledTeamIdsMemo = null;
        self::$managedProgramIdsMemo = null;
        self::$memberMemo = null;
        self::$gitMemo = [];
        self::$attendanceMemo = [];
        OrgChart::flush();
    }

    public static function isSuperAdmin(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows('manage-workspace');
    }

    /**
     * Whether the user may sign in and stay signed in: not while their member
     * is deactivated. A super-admin always may (their powers come from
     * config, never their member), and so may a login with no member yet.
     */
    public static function canSignIn(Authenticatable $user): bool
    {
        $member = self::memberFor($user);

        return self::isSuperAdmin($user) || $member === null || $member->is_active;
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
     * not its manager: only whoever may take the manager off
     * (canRemoveManager) does.
     */
    public static function canRemoveFromTeam(?Authenticatable $user, Team $team, Member $member): bool
    {
        if ($team->leaders()->whereKey($member->getKey())->exists()) {
            return self::canRemoveManager($user, $team);
        }

        return self::canEditTeam($user, $team) || self::leadsTeam($user, $team) || self::managesProgramOfTeam($user, $team);
    }

    /**
     * Taking a project's manager off (off the team, or back to a member),
     * which leaves the project with none until someone is chosen: whoever
     * administers the team's organization. Never its program's manager,
     * who only chooses or replaces one, nor the manager themselves.
     */
    public static function canRemoveManager(?Authenticatable $user, Team $team): bool
    {
        return self::canEditTeam($user, $team);
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
     * Whether the person form offers role fields (program manager, project
     * manager, member of) for the organization's people: whoever
     * administers it. A super-admin sets them anywhere, so null (no one
     * organization) answers for them alone. Which programs and teams a
     * save may touch is ChecksMemberRoles' check.
     */
    public static function canSetRolesIn(?Authenticatable $user, int|string|null $organizationId): bool
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

    /**
     * Give people access to a linked repository, change it and take it
     * away: whoever may link the project's repositories.
     */
    public static function canManageRepositoryAccess(?Authenticatable $user, GitRepository $repository): bool
    {
        return self::canManageProjectRepositories($user, $repository->project);
    }

    /**
     * Whether the user may give this person access to the project's
     * repositories: only whoever passes canManageProjectRepositories, and
     * never to someone inactive or deleted, someone with no organization
     * (a super-admin's own member may have none) or a maintainer, whose access follows the role.
     * Whoever administers the project's organization gives it to its people
     * and to anyone on the project's team; its manager and its program's
     * manager only to people on its team (a cross-organization team's
     * outsiders included). The predicate twin of
     * RepositoryAccessCandidatesQuery; keep them in step.
     */
    public static function canGrantRepositoryAccessTo(?Authenticatable $user, Project $project, Member $member): bool
    {
        if (! self::canManageProjectRepositories($user, $project)
            || ! $member->is_active
            || $member->trashed()
            || $member->organization_id === null
            || in_array((int) $member->getKey(), self::repositoryMaintainerIds($project), true)) {
            return false;
        }

        if (self::administers($user, $project->organization_id) && (int) $member->organization_id === (int) $project->organization_id) {
            return true;
        }

        return $project->teams()->whereHas('members', fn ($m) => $m->where('members.id', $member->getKey()))->exists();
    }

    /**
     * The members who maintain the project's repositories (admin on the
     * forge, their access not editable): its manager and its program's
     * manager, while active, the latter only as managesProgramOf would count
     * them (the program and its manager both in the project's
     * organization).
     *
     * @return list<int>
     */
    public static function repositoryMaintainerIds(Project $project): array
    {
        $ids = [];
        $manager = $project->manager();

        if ($manager !== null && $manager->is_active) {
            $ids[] = (int) $manager->getKey();
        }

        $program = $project->program;
        $programManager = $program?->manager;

        if ($program !== null && $programManager !== null && $programManager->is_active
            && (int) $program->organization_id === (int) $project->organization_id
            && (int) $programManager->organization_id === (int) $project->organization_id) {
            $ids[] = (int) $programManager->getKey();
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whose commits the code activity report shows the user. Whole
     * repositories: every one for a super-admin (`all`); for an active
     * member, those of the projects of the organization they administer,
     * of the programs they manage (their own organization's, as
     * managesProgramOf) and of the projects they manage. And everyone's own
     * commits wherever they are (`user_id`). CodeActivityQuery applies it.
     *
     * @return array{all: bool, organization_ids: list<int>, program_ids: list<int>, project_ids: list<int>, user_id: ?int}
     */
    public static function codeReportScope(?Authenticatable $user): array
    {
        $scope = ['all' => false, 'organization_ids' => [], 'program_ids' => [], 'project_ids' => [], 'user_id' => null];

        if ($user === null) {
            return $scope;
        }

        $scope['user_id'] = (int) $user->getAuthIdentifier();

        if (self::isSuperAdmin($user)) {
            $scope['all'] = true;

            return $scope;
        }

        $member = self::memberFor($user);

        // A deactivated or missing member sees only their own commits.
        if ($member === null || ! $member->is_active) {
            return $scope;
        }

        $organizationId = self::officeAdminOrganizationId($user);
        $ledTeamIds = self::ledTeamIds($user);

        $scope['organization_ids'] = $organizationId === null ? [] : [$organizationId];
        $scope['program_ids'] = self::managedProgramIds($user);
        $scope['project_ids'] = $ledTeamIds === [] ? [] : array_values(Project::query()
            ->whereHas('teams', fn ($teams) => $teams->whereIn('teams.id', $ledTeamIds))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());

        return $scope;
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
     * The team ids the user's linked member leads, while they are active (a
     * deactivated manager keeps the row but loses the power, as a program
     * manager does). Memoized per user instance for the request.
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

        $ids = $member === null || ! $member->is_active
            ? []
            : array_values($member->ledTeams()->pluck('teams.id')->map(fn ($id): int => (int) $id)->all());

        return self::$ledTeamIdsMemo[$user] = $ids;
    }

    // ---- Structure ----

    /**
     * Change an organization's designations and reporting lines: whoever
     * administers it, nobody else, since the lines decide who sees whose
     * attendance, location and work.
     */
    public static function canManageStructure(?Authenticatable $user, int|string|null $organizationId): bool
    {
        return $organizationId !== null && self::administers($user, $organizationId);
    }

    /**
     * See what needs fixing in an organization (MaintenanceQuery): whoever
     * administers it.
     */
    public static function canViewMaintenance(?Authenticatable $user, int|string|null $organizationId): bool
    {
        return $organizationId !== null && self::administers($user, $organizationId);
    }

    /**
     * See an organization's organogram (names, designations and lines,
     * never emails): super-admins and its active members.
     */
    public static function canViewOrganogram(?Authenticatable $user, int|string|null $organizationId): bool
    {
        if ($organizationId === null) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        $member = self::memberFor($user);

        return $member !== null && $member->is_active && (int) $member->organization_id === (int) $organizationId;
    }

    /**
     * Whether the user stands over the member: a super-admin, an admin of
     * the member's organization, or an active member of the same
     * organization the member reports to, directly or through others.
     * Never over themselves.
     */
    public static function oversees(?Authenticatable $user, Member $member): bool
    {
        $self = self::memberFor($user);

        if ($self !== null && $self->is($member)) {
            return false;
        }

        if (self::administers($user, $member->organization_id)) {
            return true;
        }

        return $self !== null
            && $self->is_active
            && $self->organization_id !== null
            && (int) $self->organization_id === (int) $member->organization_id
            && in_array((int) $self->getKey(), OrgChart::ancestorIds($member), true);
    }

    /**
     * See a person's attendance and work: themselves, or whoever
     * `oversees` them.
     */
    public static function canViewPersonOf(?Authenticatable $user, Member $member): bool
    {
        $self = self::memberFor($user);

        return ($self !== null && $self->is($member)) || self::oversees($user, $member);
    }

    /**
     * The people of an organization whose attendance and work the user
     * sees in lists: null for all of them (its admins and super-admins),
     * otherwise the people below the user there, possibly none. The twin of
     * `oversees` for queries; keep them in step.
     *
     * @return list<int>|null
     */
    public static function overseenMemberIds(?Authenticatable $user, int|string $organizationId): ?array
    {
        if (self::administers($user, $organizationId)) {
            return null;
        }

        $self = self::memberFor($user);

        if ($self === null || ! $self->is_active || (int) $self->organization_id !== (int) $organizationId) {
            return [];
        }

        $below = OrgChart::descendantIds($self);

        if ($below === []) {
            return [];
        }

        return array_values(array_map('intval', Member::query()
            ->whereIn('id', $below)
            ->where('organization_id', $organizationId)
            ->pluck('id')
            ->all()));
    }

    /**
     * See the work reports of an organization's people (today's work, a
     * team's week): its admins, super-admins, and anyone with people below
     * them there (who see only those people).
     */
    public static function canViewTeamWork(?Authenticatable $user, int|string|null $organizationId): bool
    {
        return $organizationId !== null
            && (self::administers($user, $organizationId) || self::overseenMemberIds($user, $organizationId) !== []);
    }

    // ---- Attendance ----

    public static function attendanceEnabled(int|string|null $organizationId): bool
    {
        if ($organizationId === null) {
            return false;
        }

        return self::$attendanceMemo[(int) $organizationId] ??= AttendanceSetting::query()
            ->where('organization_id', $organizationId)
            ->enabled()
            ->exists();
    }

    /** Whether any organization has attendance on (a super-admin's menu). */
    public static function attendanceEnabledAnywhere(): bool
    {
        return AttendanceSetting::query()->enabled()->exists();
    }

    /** Turn an organization's attendance on or off: super-admins only. */
    public static function canEnableAttendance(?Authenticatable $user): bool
    {
        return self::isSuperAdmin($user);
    }

    /**
     * Change an organization's hours, office places, office networks and
     * holidays: whoever administers it.
     */
    public static function canManageAttendance(?Authenticatable $user, int|string|null $organizationId): bool
    {
        return $organizationId !== null && self::administers($user, $organizationId);
    }

    /**
     * Clock in and out, and ask for leave or kaaj: an active member of an
     * organization whose attendance is on.
     */
    public static function canPunch(?Authenticatable $user): bool
    {
        $member = self::memberFor($user);

        return $member !== null && $member->is_active && self::attendanceEnabled($member->organization_id);
    }

    /**
     * Open an organization's attendance register: attendance on, and the
     * user administers it or has people below them there (who see only
     * those people).
     */
    public static function canViewAttendanceRegister(?Authenticatable $user, int|string|null $organizationId): bool
    {
        return $organizationId !== null
            && self::attendanceEnabled($organizationId)
            && (self::administers($user, $organizationId) || self::overseenMemberIds($user, $organizationId) !== []);
    }

    /**
     * Approve or reject someone's leave or kaaj, or record it for them:
     * whoever `oversees` them (never the person themselves).
     */
    public static function canDecideLeave(?Authenticatable $user, LeaveRequest $leave): bool
    {
        $member = $leave->member;

        return $member !== null && self::oversees($user, $member);
    }

    /**
     * Record approved leave or kaaj for someone directly: whoever
     * `oversees` them, for an active person of an organization whose
     * attendance is on (never oneself; ask instead).
     */
    public static function canRecordLeaveFor(?Authenticatable $user, Member $member): bool
    {
        return $member->is_active
            && self::attendanceEnabled($member->organization_id)
            && self::oversees($user, $member);
    }
}
