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
}

export interface ProjectSummary {
    id: Id;
    slug: string;
    title: string;
}

export interface Project extends ProjectSummary {
    title_np?: string | null;
    team_ids?: Id[];
    description?: string | null;
    description_np?: string | null;
    is_public?: boolean;
    is_archived?: boolean;
    archived_at?: string | null;
    can_archive?: boolean;
    can_manage_access?: boolean;
    tasks_count?: number;
    /** Present on the project index only. */
    percent_complete?: number;
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
    days_relative_label?: string;
    source_url?: string | null;
    source_links?: unknown[];
    freshness?: {
        bucket: string;
        label: string | null;
        days_ago: number | null;
    };
    metadata?: Record<string, unknown>;
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
}

/** A project in the quick-add picker, carrying the ids of members assignable to it (its teams' members). */
export interface QuickAddProject extends ProjectSummary {
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
    ledTeamIds?: Id[];
    unreadNotifications?: number;
    themeCatalogue?: ThemeCatalogue;
    [key: string]: unknown;
}
