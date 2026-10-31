import type { PersonRef } from './types';

/**
 * What needs looking at in an organization (MaintenanceQuery): every list is
 * empty when all is well.
 */
export interface Maintenance {
    organization: { id: number; name: string };
    /** How long a project may go without activity before it is "quiet". */
    quiet_days: number;
    /** `manager` is the deactivated one, or null when there is none. */
    projects_without_manager: (ProjectRef & { manager: PersonRef | null })[];
    projects_without_people: ProjectRef[];
    /** Open tasks nobody holds. */
    unassigned_tasks: (ProjectRef & { count: number })[];
    /** Open tasks only deactivated people hold. */
    tasks_with_inactive_people: (ProjectRef & { count: number })[];
    quiet_projects: (ProjectRef & { last_activity_at: string })[];
    programs_without_manager: {
        slug: string;
        name: string;
        manager: PersonRef | null;
    }[];
    people_off_projects: PersonRef[];
    people_without_designation: PersonRef[];
    people_reporting_to_inactive: (PersonRef & { reports_to: PersonRef })[];
    /** Reporting to no one, and no one reporting to them. */
    people_outside_organogram: PersonRef[];
    people_without_login: PersonRef[];
    attendance_without_office: boolean;
    /** Repositories linked to its active projects; the code checks below look at them. */
    repositories: number;
    /** The main (default) branch's latest commit failed its tests. */
    repositories_failing_main: RepositoryRef[];
    /** The tracker's rule isn't on the main (default) branch yet. */
    repositories_unprotected: RepositoryRef[];
    /** No commit has run tests: nothing can merge there yet. */
    repositories_without_tests: RepositoryRef[];
}

export interface RepositoryRef {
    full_name: string;
    /** Its project's slug and label. */
    slug: string;
    label: string;
}

export interface ProjectRef {
    slug: string;
    /** The title, with its program for a program's project. */
    label: string;
}
