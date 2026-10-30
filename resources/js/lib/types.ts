import type { FontAllowList, ThemeTokens } from './appearance';

export type Id = number;

export interface User {
    id: Id;
    name: string;
    email: string;
}

export interface Member {
    id: Id;
    name: string;
    email?: string | null;
    title?: string | null;
    user_id?: Id | null;
    is_active?: boolean;
    team_ids?: Id[];
    led_team_ids?: Id[];
    /** Null only for a super-admin's own member. */
    organization_id?: Id | null;
    organization?: string | null;
    is_office_admin?: boolean;
    /** The viewer may edit, deactivate or delete this person. */
    can_manage?: boolean;
}

/** A person as listed on one team, with what the viewer may do there. */
export interface RosterEntry {
    id: Id;
    name: string;
    email?: string | null;
    title?: string | null;
    organization?: string | null;
    is_active: boolean;
    role: 'leader' | 'member';
    can_remove: boolean;
}

export interface Team {
    id: Id;
    name: string;
    slug: string;
    description?: string | null;
    color?: string | null;
    members_count?: number;
    member_ids?: Id[];
    leader_ids?: Id[];
    organization_id?: Id;
    organization?: string | null;
    is_cross_organization?: boolean;
    can_edit?: boolean;
    can_manage_roster?: boolean;
    can_view_progress?: boolean;
    roster?: RosterEntry[];
}

/** A person on a project's team, as the project page lists them. */
export interface ProjectTeamMember {
    id: Id;
    name: string;
    email?: string | null;
    title?: string | null;
    organization?: string | null;
    is_active: boolean;
    is_manager: boolean;
    can_remove: boolean;
    /** "Make manager" on them: the viewer may make them its manager. */
    can_make_manager: boolean;
}

/** A project's own team on its page, with what the viewer may do there. */
export interface ProjectTeam {
    id: Id;
    is_cross_organization: boolean;
    members: ProjectTeamMember[];
    /** Add and remove people: its manager, office admin, super-admin. */
    can_manage: boolean;
    /** Make someone its manager: office admin, super-admin, program manager. */
    can_choose_manager: boolean;
}

/** A pull request on the forge (GitPullRequestResource). */
export interface GitPullRequest {
    id: Id;
    number: number;
    title: string;
    state: 'open' | 'merged' | 'closed';
    url: string;
    head_branch: string;
    author_username: string | null;
    /** owner/name, on a task's Code section, where several repositories meet. */
    repository?: string;
    opened_at: string;
    merged_at: string | null;
    closed_at: string | null;
}

/** A forge repository linked to a project (GitRepositoryResource). */
export interface GitRepository {
    id: Id;
    owner: string;
    name: string;
    full_name: string;
    html_url: string;
    clone_url: string;
    ssh_url: string;
    default_branch: string;
    last_pushed_at: string | null;
    created_at: string | null;
    /** The newest open ones, ten at most. */
    open_pull_requests?: GitPullRequest[];
    /** Every open one, however many the list shows. */
    open_pull_requests_count?: number;
}

/** A project's Code tab; the page gets null when its organization has no git. */
export interface ProjectGit {
    repositories: GitRepository[];
    /** Link and unlink: its manager, office admin, super-admin. */
    can_manage: boolean;
}

/** A forge repository the Link repository search offers. */
export interface RepositoryCandidate {
    full_name: string;
    name: string;
    description: string | null;
    html_url: string | null;
}

/** A task's Code section (TaskCodeQuery); null when there is no git. */
export interface TaskCode {
    /** The branch to start for it: tp-12-fix-login. */
    branch_name: string;
    pull_requests: GitPullRequest[];
    branches: { id: Id; name: string; repository: string; url: string }[];
    /** The latest ten; an unreachable one vanished from its branches. */
    commits: {
        id: Id;
        subject: string;
        short_sha: string;
        url: string;
        author: string;
        repository: string;
        committed_at: string;
        unreachable: boolean;
    }[];
}

export interface OrganizationOption {
    id: Id;
    name: string;
}

export interface Organization extends OrganizationOption {
    slug: string;
    members_count?: number;
    teams_count?: number;
    projects_count?: number;
    office_admins?: { id: Id; name: string; email?: string | null }[];
    /** The forge connection; present for super-admins, null when git is off. */
    git?: { base_url: string; owner: string; url: string } | null;
}

export interface ProjectSummary {
    id: Id;
    slug: string;
    title: string;
    /** Short prefix for task codes, e.g. DNR in DNR-106. */
    code?: string;
}

export interface Project extends ProjectSummary {
    title_np?: string | null;
    organization_id?: Id;
    organization?: string | null;
    description?: string | null;
    description_np?: string | null;
    is_public?: boolean;
    is_archived?: boolean;
    archived_at?: string | null;
    can_archive?: boolean;
    can_manage_access?: boolean;
    can_view_progress?: boolean;
    /** Choosing the manager: office admin, super-admin, its program's manager. */
    can_choose_manager?: boolean;
    /** Stored project key, the code half of KEY-12. */
    key?: string;
    /** Renaming the key is the office admin's (or a super-admin's). */
    can_change_key?: boolean;
    /** Making it public (everyone in its organization), likewise. */
    can_publish?: boolean;
    /** The program it is in, if any. */
    program?: ProgramRef | null;
    /** The project's own team. */
    team?: {
        id: Id;
        is_cross_organization: boolean;
        members_count: number;
    } | null;
    /** Its team's one leader; null until one is chosen. */
    manager?: { id: Id; name: string; title: string | null } | null;
    tasks_count?: number;
    /** Present on the project index only. */
    percent_complete?: number;
    open_tasks_count?: number;
    overdue_tasks_count?: number;
    next_deadline_at?: string | null;
    created_at?: string;
    updated_at?: string;
}

export type Priority = 'low' | 'medium' | 'high' | 'urgent';

export interface Assignment {
    id: Id;
    task_id: Id;
    member_id: Id;
    member?: Member;
    role?: string | null;
    priority: Priority;
    is_focused: boolean;
    snoozed_until?: string | null;
    is_snoozed: boolean;
    personal_progress: number;
    personal_due_at?: string | null;
    personal_status_note?: string | null;
    task?: Task;
    created_at?: string;
    updated_at?: string;
}

export interface Task {
    id: Id;
    project_id: Id;
    project?: ProjectSummary;
    slug: string;
    title: string;
    short_title?: string | null;
    description?: string | null;
    status: string;
    status_label?: string;
    status_color?: string;
    status_note?: string | null;
    status_updated_at?: string | null;
    priority: Priority;
    progress: number;
    sort_order?: number | null;
    deadline_at?: string | null;
    completed_at?: string | null;
    is_late?: boolean;
    /** Still being worked on and past its deadline: the one overdue rule. */
    is_overdue?: boolean;
    days_relative_label?: string;
    source_url?: string | null;
    source_links?: unknown[];
    freshness?: {
        bucket: string;
        label: string | null;
        days_ago: number | null;
    };
    metadata?: Record<string, unknown>;
    number: number;
    item_number?: number | null;
    category?: string | null;
    category_label?: string | null;
    category_color?: string | null;
    deadline_type?: string | null;
    deadline_label?: string | null;
    responsible_ministry?: string | null;
    title_np?: string | null;
    description_np?: string | null;
    assignments?: Assignment[];
    assignments_count?: number;
    notes_count?: number;
    contacts_count?: number;
    comments_count?: number;
    /** Tasks this one waits for, and tasks waiting for it (same project). */
    dependencies?: TaskRef[];
    dependents?: TaskRef[];
    /** On a project page with git: a pull request mentions the task. */
    has_pull_request?: boolean;
    created_at?: string;
    updated_at?: string;
}

export interface Subtask {
    id: Id;
    task_id: Id;
    user_id: Id;
    body: string;
    is_done: boolean;
    done_at?: string | null;
    due_at?: string | null;
    position: number;
    task?: {
        id: Id;
        slug: string;
        title: string;
        short_title?: string | null;
        project?: { slug: string; title: string } | null;
    };
    created_at?: string;
}

export interface CommentMention {
    member_id: Id;
    name: string;
}

export interface CommentAuthor {
    member_id: Id | null;
    name: string | null;
}

export interface Comment {
    id: Id;
    body: string;
    mentions: CommentMention[];
    author: CommentAuthor;
    can_edit: boolean;
    created_at?: string | null;
    updated_at?: string | null;
}

export interface NoteTaskRef {
    slug: string;
    title: string;
    short_title?: string | null;
    project?: { slug: string } | null;
}

export interface Note {
    id: Id;
    task_id: Id;
    user?: { id: Id; name: string };
    type: string;
    type_label: string;
    body: string;
    happened_at?: string | null;
    created_at?: string;
    task?: NoteTaskRef | null;
}

/**
 * A task-anchored note (`project_notes`) surfaced read-only in the workspace
 * notes panel / sticky board, with task + project context for deep-linking.
 * Same serialized shape as {@link Note} (from `NoteResource`).
 */
export type TaskNote = Note;

export interface Contact {
    id: Id;
    task_id: Id;
    name: string;
    role?: string | null;
    email?: string | null;
    phone?: string | null;
    organization?: string | null;
    notes?: string | null;
    task?: NoteTaskRef | null;
}

export type WorkspaceNoteColor =
    'amber' | 'rose' | 'sky' | 'emerald' | 'violet';

export interface WorkspaceNote {
    id: Id;
    title?: string | null;
    body: string;
    position_x: number;
    position_y: number;
    color: WorkspaceNoteColor;
    created_at?: string;
    updated_at?: string;
}

export interface Status {
    value: string;
    label: string;
    color: string;
    is_complete: boolean;
    /** Work has stopped, finished or not: never overdue. */
    is_closed: boolean;
}

export interface ActivityEntry {
    id: Id;
    description: string;
    user?: { id: Id; name: string } | null;
    created_at?: string | null;
}

/** Payload of GET /workspace/tasks/{id}/preview — everything the Task Peek renders. */
export interface TaskPreview {
    task: Task;
    assignments: Assignment[];
    subtasks: Subtask[];
    notes: Note[];
    contacts: Contact[];
    activity: ActivityEntry[];
    team: Member[];
    comments_count: number;
    /** The rest of the task's project, for the dependency picker. */
    dependency_candidates?: TaskRef[];
    /** The Code section; null when the organization has no git. */
    code?: TaskCode | null;
}

/** A task in a dependency list or picker (TaskResource::reference). */
export interface TaskRef {
    id: number;
    slug: string;
    title: string;
    number: number;
    status: string;
    status_label: string;
    is_complete: boolean;
    is_late: boolean;
    is_overdue: boolean;
    deadline_at: string | null;
    completed_at: string | null;
}

/** A task offered in a picker (e.g. "depends on" in the new-task form). */
export interface TaskOption {
    id: number;
    title: string;
    number: number;
    status: string;
}

/** One person's workload on a team's progress page (GET /workspace/teams/{team}/progress). */
export interface ProgressMember {
    id: number;
    name: string;
    title: string | null;
    organization: string | null;
    role: 'leader' | 'member';
    is_active: boolean;
    assigned: number;
    open: number;
    done: number;
    overdue: number;
    due_this_week: number;
    /** Average of their own progress per task, finished tasks counting 100; null with no tasks. */
    progress: number | null;
    last_active_at: string | null;
}

/** A task as the team Gantt chart lays it out. Dates are YYYY-MM-DD (start, end) or ISO. */
export interface ProgressTask {
    id: number;
    slug: string;
    title: string;
    number: number;
    project: { slug: string; title: string; code: string };
    status: string;
    priority: string;
    progress: number;
    is_complete: boolean;
    is_late: boolean;
    is_overdue: boolean;
    /** When the task was opened. */
    start: string | null;
    /** When it was finished, or its deadline while open. */
    end: string | null;
    deadline_at: string | null;
    completed_at: string | null;
    /** Ids of tasks this one cannot start before (finish-to-start). */
    depends_on: number[];
    assignees: { id: number; name: string; in_team: boolean }[];
}

export interface ProgressActivity {
    id: number;
    description: string;
    user_name: string | null;
    task_title: string | null;
    task_slug: string | null;
    project_slug: string | null;
    happened_at: string | null;
}

/** The body of a live progress page, a team's or a project's. */
export interface ProgressReport {
    members: ProgressMember[];
    tasks: ProgressTask[];
    truncated: boolean;
    activity: ProgressActivity[];
    generated_at: string;
}

export interface TeamProgress extends ProgressReport {
    team: {
        id: number;
        name: string;
        description: string | null;
        organization: string | null;
        is_cross_organization: boolean;
    };
}

export interface ProjectProgress extends ProgressReport {
    project: {
        id: number;
        slug: string;
        title: string;
        title_np: string | null;
        code: string;
        organization: string | null;
        is_archived: boolean;
    };
}

/** A program as a project or the sidebar names it. */
export interface ProgramRef {
    slug: string;
    name: string;
}

/** A program a project can be put in (Edit project's choices). */
export interface ProgramOption extends ProgramRef {
    id: number;
}

export interface Program {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    organization: { id: number; name: string };
    manager: { id: number; name: string; title: string | null } | null;
    projects_count?: number;
    can_manage: boolean;
    /** Whether the viewer may create a project in it. */
    can_create_project: boolean;
}

export interface ProgramProjectRow {
    slug: string;
    title: string;
    code: string;
    manager: { id: number; name: string } | null;
    tasks_count: number;
    done_tasks_count: number;
    overdue_tasks_count: number;
    next_deadline_at: string | null;
}

export interface ProgramProgress extends ProgressReport {
    program: Program;
    projects: ProgramProjectRow[];
}

/** A project in the quick-add picker, carrying the ids of members assignable to it (its teams' members). */
export interface QuickAddProject extends ProjectSummary {
    /** Its program's name; tells apart several projects of one title. */
    program?: string | null;
    member_ids?: Id[];
}

export interface QuickAddContext {
    projects: QuickAddProject[];
    team: Member[];
    currentMemberId: Id | null;
}

export interface Flash {
    success?: boolean;
    message?: string | null;
    undo?: { label: string; url: string };
}

export interface AuthUser extends User {
    role?: string;
}

/** The theme + font catalogue shared from `config/themes.php` (web only). */
export interface ThemeCatalogue {
    themes: Record<
        string,
        { label: string; mode: 'light' | 'dark' | null; tokens?: ThemeTokens }
    >;
    fontAllowList: FontAllowList;
}

/** Optional features each person turns on in Settings; both start off. */
export interface Features {
    task_notes: boolean;
    contacts: boolean;
}

export interface SharedProps {
    auth: { user: AuthUser | null };
    flash?: Flash;
    workspaceNotes?: WorkspaceNote[];
    /** Read-only task-anchored notes (`project_notes`) the user authored, for the notes panel/board. */
    taskNotes?: Note[];
    statuses?: Status[];
    completeStatus?: string;
    quickAddContext?: QuickAddContext | null;
    isSuperAdmin?: boolean;
    isOfficeAdmin?: boolean;
    /** The viewer's organization; null for a super-admin. */
    organization?: OrganizationOption | null;
    ledTeamIds?: Id[];
    /** The programs the user manages, for role checks and the sidebar. */
    managedProgramIds?: Id[];
    managedPrograms?: ProgramRef[];
    unreadNotifications?: number;
    themeCatalogue?: ThemeCatalogue;
    features?: Features;
    /** The user may use git somewhere (GitAccess): the Guide's Code section. */
    gitEnabled?: boolean;
    [key: string]: unknown;
}
