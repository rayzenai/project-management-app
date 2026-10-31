import {
    BriefcaseBusiness,
    Building2,
    CalendarDays,
    CalendarRange,
    ChartColumnBig,
    Clock,
    FolderKanban,
    GitCommitHorizontal,
    LayoutGrid,
    ListChecks,
    Network,
    ShieldCheck,
    SlidersHorizontal,
    Stethoscope,
    Table2,
    UserRound,
    Users,
} from '@lucide/svelte';
import type { OfficeLinks } from './types';

/**
 * The sidebar's areas: the rail switches between them and the panel lists
 * the one shown. Work holds your own pages and the projects (drawn by the
 * panel), the others the office pages the user may open (OfficeLinks).
 */

export type AreaKey = 'work' | 'office' | 'reports' | 'admin';

export interface NavLink {
    label: string;
    href: string;
    icon: typeof LayoutGrid;
    /** One line under the label, in the Office, Reports and Admin panels. */
    hint?: string;
    /** Shown as a badge when above zero. */
    count?: number;
}

export interface NavArea {
    key: AreaKey;
    label: string;
    icon: typeof LayoutGrid;
    links: NavLink[];
    /** Its links' counts together, for the rail. */
    count: number;
    /** Pages that belong here without a link of their own. */
    prefixes: string[];
}

export function navAreas(
    office: OfficeLinks,
    options: { gitEnabled: boolean; isSuperAdmin: boolean },
): NavArea[] {
    const when = (show: unknown, link: NavLink): NavLink[] =>
        show ? [link] : [];
    const area = (
        key: AreaKey,
        label: string,
        icon: typeof LayoutGrid,
        links: NavLink[],
        prefixes: string[] = [],
    ): NavArea => ({
        key,
        label,
        icon,
        links,
        count: links.reduce((sum, link) => sum + (link.count ?? 0), 0),
        prefixes,
    });

    return [
        area(
            'work',
            'Work',
            FolderKanban,
            [
                { label: 'Home', href: '/workspace', icon: LayoutGrid },
                {
                    label: 'My Workspace',
                    href: '/workspace/my',
                    icon: UserRound,
                },
            ],
            ['/workspace/projects', '/workspace/programs', '/workspace/tasks'],
        ),
        area('office', 'Office', BriefcaseBusiness, [
            {
                label: 'People',
                href: '/workspace/team',
                icon: Users,
                hint: 'Everyone and their teams',
            },
            ...when(office.organogram, {
                label: 'Organogram',
                href: '/workspace/organogram',
                icon: Network,
                hint: 'Who reports to whom',
            }),
            ...when(office.attendance, {
                label: 'Attendance',
                href: '/workspace/attendance',
                icon: Clock,
                hint: 'Who is in, day by day',
            }),
            ...when(office.leave, {
                label: 'Leave and kaaj',
                href: '/workspace/leave',
                icon: CalendarDays,
                hint: 'Requests and approvals',
            }),
        ]),
        area(
            'reports',
            'Reports',
            ChartColumnBig,
            [
                ...when(office.team_work, {
                    label: "Today's work",
                    href: '/workspace/work',
                    icon: ListChecks,
                    hint: 'What everyone did today',
                }),
                ...when(office.team_work, {
                    label: "Team's work",
                    href: '/workspace/work/team',
                    icon: Table2,
                    hint: 'A week, person by person',
                }),
                ...when(office.my_work_member_id, {
                    label: 'My week',
                    href: `/workspace/people/${office.my_work_member_id}/work`,
                    icon: CalendarRange,
                    hint: 'Your work and attendance',
                }),
                ...when(options.gitEnabled, {
                    label: 'Code activity',
                    href: '/workspace/reports/code',
                    icon: GitCommitHorizontal,
                    hint: 'Commits, person by person',
                }),
            ],
            ['/workspace/people/'],
        ),
        area('admin', 'Admin', ShieldCheck, [
            ...when(office.maintenance, {
                label: 'Maintenance',
                href: '/workspace/maintenance',
                icon: Stethoscope,
                hint: 'What needs fixing',
                count: office.maintenance_count,
            }),
            ...when(office.attendance_settings, {
                label: 'Attendance settings',
                href: '/workspace/attendance/settings',
                icon: SlidersHorizontal,
                hint: 'Hours, places and holidays',
            }),
            ...when(options.isSuperAdmin, {
                label: 'Organizations',
                href: '/workspace/organizations',
                icon: Building2,
                hint: 'Offices and ministries',
            }),
        ]),
    ].filter((a) => a.key === 'work' || a.links.length > 0);
}

/** A page with a query string (?from=...) is still that page. */
function bare(path: string): string {
    return path.split('?')[0];
}

function under(path: string, href: string): boolean {
    const p = bare(path);

    return p === href || (href !== '/workspace' && p.startsWith(href + '/'));
}

/**
 * The link of the page the user is on: the longest that matches, so Team's
 * work (/workspace/work/team) doesn't also light up Today's work.
 */
export function currentHref(path: string, areas: NavArea[]): string | null {
    return (
        areas
            .flatMap((a) => a.links.map((l) => l.href))
            .filter((href) => under(path, href))
            .sort((a, b) => b.length - a.length)[0] ?? null
    );
}

/** The area the page belongs to; Work for anything else. */
export function areaOf(path: string, areas: NavArea[]): AreaKey {
    const href = currentHref(path, areas);
    const p = bare(path);

    return (
        areas.find((a) => a.links.some((l) => l.href === href))?.key ??
        areas.find((a) => a.prefixes.some((prefix) => p.startsWith(prefix)))
            ?.key ??
        'work'
    );
}
