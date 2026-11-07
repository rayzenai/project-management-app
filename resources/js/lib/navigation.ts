import {
    Bell,
    BookOpen,
    Building2,
    CalendarDays,
    CalendarRange,
    CircleAlert,
    ClipboardList,
    Clock,
    FileText,
    FolderKanban,
    GitCommitHorizontal,
    ListChecks,
    Mail,
    Network,
    SlidersHorizontal,
    Table2,
    Users,
} from '@lucide/svelte';
import { msg } from './i18n';
import type { OfficeLinks } from './types';

/**
 * Where the menu goes. The sidebar (and the phone bar) carry the few pages
 * everyone uses, mainLinks; everything else sits in the account menu,
 * accountGroups, each office page only when OfficeLinks allows it. A new
 * page is one entry here. Labels stay English (msg() marks them for the
 * translation check) and are drawn with t(link.label).
 */

export interface NavLink {
    label: string;
    href: string;
    icon: typeof Bell;
    /** Shown as a number when above zero. */
    count?: number;
}

export interface NavGroup {
    label: string;
    links: NavLink[];
}

/**
 * The sidebar's pages, under Search (a button, not a link). Documents
 * joins them while documents are on; Code activity for anyone who uses git,
 * and stays in the account menu too. On a phone, those without a tab lead
 * the Me sheet.
 */
export function mainLinks(options: {
    gitEnabled: boolean;
    isSuperAdmin: boolean;
    documentsEnabled?: boolean;
}): NavLink[] {
    // Phase 2 adds Overview here, for whoever runs something.
    return [
        { label: msg('My work'), href: '/workspace/my', icon: ListChecks },
        {
            label: msg('Projects'),
            href: '/workspace/projects',
            icon: FolderKanban,
        },
        ...(options.documentsEnabled
            ? [
                  {
                      label: msg('Documents'),
                      href: '/workspace/documents',
                      icon: FileText,
                  },
              ]
            : []),
        { label: msg('People'), href: '/workspace/team', icon: Users },
        ...(options.gitEnabled
            ? [
                  {
                      label: msg('Code activity'),
                      href: '/workspace/reports/code',
                      icon: GitCommitHorizontal,
                  },
              ]
            : []),
    ];
}

/** The account menu's groups; a group with nothing to show is left out. */
export function accountGroups(
    office: OfficeLinks,
    options: { gitEnabled: boolean; isSuperAdmin: boolean; unread: number },
): NavGroup[] {
    const when = (show: unknown, link: NavLink): NavLink[] =>
        show ? [link] : [];

    return [
        {
            label: msg('You'),
            links: [
                {
                    label: msg('Notifications'),
                    href: '/workspace/notifications',
                    icon: Bell,
                    count: options.unread,
                },
                {
                    label: msg('Emails'),
                    href: '/workspace/settings/emails',
                    icon: Mail,
                },
                {
                    label: msg('Guide'),
                    href: '/workspace/guide',
                    icon: BookOpen,
                },
            ],
        },
        {
            label: msg('Office'),
            links: [
                ...when(office.maintenance, {
                    label: msg('Needs attention'),
                    href: '/workspace/maintenance',
                    icon: CircleAlert,
                    count: office.maintenance_count,
                }),
                ...when(office.organogram, {
                    label: msg('Organogram'),
                    href: '/workspace/organogram',
                    icon: Network,
                }),
                ...when(office.attendance, {
                    label: msg('Attendance'),
                    href: '/workspace/attendance',
                    icon: Clock,
                }),
                ...when(office.leave, {
                    label: msg('Leave and kaaj'),
                    href: '/workspace/leave',
                    icon: CalendarDays,
                }),
                ...when(office.attendance_settings, {
                    label: msg('Attendance settings'),
                    href: '/workspace/attendance/settings',
                    icon: SlidersHorizontal,
                }),
            ],
        },
        {
            label: msg('Reports'),
            links: [
                ...when(office.team_work, {
                    label: msg("Today's work"),
                    href: '/workspace/work',
                    icon: ClipboardList,
                }),
                ...when(office.team_work, {
                    label: msg("Team's work"),
                    href: '/workspace/work/team',
                    icon: Table2,
                }),
                ...when(office.my_work_member_id, {
                    label: msg('My week'),
                    href: `/workspace/people/${office.my_work_member_id}/work`,
                    icon: CalendarRange,
                }),
                ...when(options.gitEnabled, {
                    label: msg('Code activity'),
                    href: '/workspace/reports/code',
                    icon: GitCommitHorizontal,
                }),
            ],
        },
        {
            label: msg('Admin'),
            links: when(options.isSuperAdmin, {
                label: msg('Organizations'),
                href: '/workspace/organizations',
                icon: Building2,
            }),
        },
    ].filter((group) => group.links.length > 0);
}

/** A page with a query string (?from=...) is still that page. */
function bare(path: string): string {
    return path.split('?')[0];
}

/** The page is the link's, or one of the pages under it. */
export function under(path: string, href: string): boolean {
    const p = bare(path);

    return p === href || p.startsWith(href + '/');
}

/**
 * The link of the page the user is on: the longest that matches, so Team's
 * work (/workspace/work/team) doesn't also light up Today's work.
 */
export function currentHref(path: string, links: NavLink[]): string | null {
    return (
        links
            .map((l) => l.href)
            .filter((href) => under(path, href))
            .sort((a, b) => b.length - a.length)[0] ?? null
    );
}
