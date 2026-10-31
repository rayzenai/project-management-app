<script lang="ts">
    import { inertia, router } from '@inertiajs/svelte';
    import { Check, ChevronRight } from '@lucide/svelte';
    import { SvelteSet } from 'svelte/reactivity';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import OrganizationSelect from '../../components/filters/OrganizationSelect.svelte';
    import { fullDay } from '../../lib/dates';
    import { plural } from '../../lib/format';
    import type {
        Maintenance,
        ProjectRef,
        RepositoryRef,
    } from '../../lib/maintenance';
    import type { Id, OrganizationOption, PersonRef } from '../../lib/types';

    /**
     * What needs looking at in an organization: one row per project,
     * program or person with everything wrong with it, linked to where it is
     * fixed. The checks that found nothing are listed at the end, so the
     * page says what it looked at.
     */
    let {
        maintenance,
        organizations = [],
    }: {
        maintenance: Maintenance | null;
        /** A super-admin's choice of organization; empty for anyone else. */
        organizations?: OrganizationOption[];
    } = $props();

    interface Row {
        key: string;
        label: string;
        /** A person's designation, beside their name. */
        designation?: string | null;
        issues: string[];
        href: string;
        person?: boolean;
    }

    interface Section {
        key: 'projects' | 'programs' | 'people' | 'attendance' | 'code';
        title: string;
        /** The summary's word for one row, and for several. */
        noun: [string, string];
        rows: Row[];
    }

    /** Rows shown before "Show all". */
    const FIRST_ROWS = 10;

    const expanded = new SvelteSet<string>();

    const report = $derived.by(() => {
        const sections: Section[] = [];
        const passed: string[] = [];

        if (!maintenance) {
            return { sections, passed };
        }

        const m = maintenance;
        const id = m.organization.id;
        const peoplePage = `/workspace/team?organization=${id}`;
        const organogram = `/workspace/organogram?organization=${id}`;

        // Each check adds its issue to the rows it names (the first check
        // to name a row decides its link); a check that names nothing is
        // listed as passed.
        function collect<T>(
            rows: Map<string, Row>,
            items: T[],
            check: string,
            row: (item: T) => Omit<Row, 'issues'>,
            issue: (item: T) => string,
        ) {
            if (items.length === 0) {
                passed.push(check);
            }

            for (const item of items) {
                const made = row(item);
                const existing = rows.get(made.key) ?? { ...made, issues: [] };
                existing.issues.push(issue(item));
                rows.set(made.key, existing);
            }
        }

        function section(
            key: Section['key'],
            title: string,
            noun: [string, string],
            rows: Map<string, Row>,
        ) {
            if (rows.size > 0) {
                sections.push({
                    key,
                    title,
                    noun,
                    rows: [...rows.values()].sort((a, b) =>
                        a.label.localeCompare(b.label),
                    ),
                });
            }
        }

        // A project's team problems send you to its People tab, the rest
        // to its tasks.
        const project =
            (view: 'people' | 'list') =>
            (p: ProjectRef): Omit<Row, 'issues'> => ({
                key: p.slug,
                label: p.label,
                href: `/workspace/projects/${p.slug}?view=${view}`,
            });
        const projects = new Map<string, Row>();
        collect(
            projects,
            m.projects_without_manager,
            'Projects with no manager',
            project('people'),
            (p) =>
                p.manager
                    ? `No manager: ${p.manager.name} is deactivated`
                    : 'No manager',
        );
        collect(
            projects,
            m.projects_without_people,
            'Projects with nobody on the team',
            project('people'),
            () => 'Nobody on the team',
        );
        collect(
            projects,
            m.unassigned_tasks,
            'Open tasks nobody holds',
            project('list'),
            (p) => `${plural(p.count, 'open task')} nobody holds`,
        );
        collect(
            projects,
            m.tasks_with_inactive_people,
            'Open tasks left with deactivated people',
            project('list'),
            (p) =>
                `${plural(p.count, 'open task')} left with deactivated people`,
        );
        collect(
            projects,
            m.quiet_projects,
            `Projects quiet for ${m.quiet_days} days`,
            project('list'),
            (p) => `Quiet since ${fullDay(p.last_activity_at)}`,
        );
        section('projects', 'Projects', ['project', 'projects'], projects);

        const programs = new Map<string, Row>();
        collect(
            programs,
            m.programs_without_manager,
            'Programs with no manager',
            (p) => ({
                key: p.slug,
                label: p.name,
                href: `/workspace/programs/${p.slug}`,
            }),
            (p) =>
                p.manager
                    ? `No manager: ${p.manager.name} is deactivated`
                    : 'No manager',
        );
        section('programs', 'Programs', ['program', 'programs'], programs);

        // A person's place in the structure is fixed on the organogram,
        // the rest on the People page.
        const person =
            (href: string) =>
            (p: PersonRef): Omit<Row, 'issues'> => ({
                key: String(p.id),
                label: p.name,
                designation: p.designation,
                href,
                person: true,
            });
        const people = new Map<string, Row>();
        collect(
            people,
            m.people_without_designation,
            'People with no designation',
            person(organogram),
            () => 'No designation',
        );
        collect(
            people,
            m.people_reporting_to_inactive,
            'People reporting to someone deactivated',
            person(organogram),
            (p) => `Reports to ${p.reports_to.name}, who is deactivated`,
        );
        collect(
            people,
            m.people_outside_organogram,
            'People outside the organogram',
            person(organogram),
            () => 'Outside the organogram',
        );
        collect(
            people,
            m.people_off_projects,
            'People on no project',
            person(peoplePage),
            () => 'On no project',
        );
        collect(
            people,
            m.people_without_login,
            "People who can't sign in",
            person(peoplePage),
            () => "Can't sign in",
        );
        section('people', 'People', ['person', 'people'], people);

        const attendance = new Map<string, Row>();
        collect(
            attendance,
            m.attendance_without_office ? [true] : [],
            'Attendance has an office to check punches against',
            () => ({
                key: 'settings',
                label: 'Attendance settings',
                href: `/workspace/attendance/settings?organization=${id}`,
            }),
            () =>
                'No office network or office place, so every punch reads as outside or unknown',
        );
        section(
            'attendance',
            'Attendance',
            ['setting', 'settings'],
            attendance,
        );

        // Only an organization with repositories has code to check.
        if (m.repositories > 0) {
            const repository = (r: RepositoryRef): Omit<Row, 'issues'> => ({
                key: r.full_name,
                label: r.full_name,
                designation: r.label,
                href: `/workspace/projects/${r.slug}?view=code`,
            });
            const code = new Map<string, Row>();
            collect(
                code,
                m.repositories_failing_main,
                'Repositories whose main branch fails its tests',
                repository,
                () => 'Main branch fails its tests',
            );
            collect(
                code,
                m.repositories_unprotected,
                'Repositories whose main branch is not protected',
                repository,
                () => 'Main branch not protected yet',
            );
            collect(
                code,
                m.repositories_without_tests,
                'Repositories with no tests',
                repository,
                () => 'No tests yet, so nothing can merge',
            );
            collect(
                code,
                m.repositories_default_branch_changed,
                'Repositories whose default branch was changed on the forge',
                repository,
                (r) =>
                    `Default branch is now ${r.default_branch}; only ${r.protected_branch} is protected`,
            );
            section('code', 'Code', ['repository', 'repositories'], code);
        }

        return { sections, passed };
    });

    const summary = $derived(
        report.sections
            .map((s) => plural(s.rows.length, s.noun[0], s.noun[1]))
            .join(', '),
    );

    function pickOrganization(organizationId: Id) {
        router.get('/workspace/maintenance', { organization: organizationId });
    }

    function shown(section: Section): Row[] {
        return expanded.has(section.key)
            ? section.rows
            : section.rows.slice(0, FIRST_ROWS);
    }
</script>

<svelte:head>
    <title
        >Maintenance{maintenance ? ` · ${maintenance.organization.name}` : ''} · Workspace</title
    >
</svelte:head>

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            {#if maintenance}
                <span class="hidden truncate text-fg-muted sm:inline"
                    >{maintenance.organization.name}</span
                >
                <span class="hidden text-fg-faint sm:inline">/</span>
            {/if}
            <span class="truncate font-medium">Maintenance</span>
        </div>
        {#if maintenance}
            <OrganizationSelect
                options={organizations}
                value={maintenance.organization.id}
                onchange={pickOrganization}
            />
        {/if}
    {/snippet}

    {#if !maintenance}
        <p class="text-xs text-fg-muted">There are no organizations yet.</p>
    {:else}
        <div class="max-w-3xl space-y-8">
            <p class="text-[13px] text-fg-muted">
                {#if report.sections.length === 0}
                    Nothing to look at in {maintenance.organization.name}.
                {:else}
                    To look at in {maintenance.organization.name}: {summary}.
                {/if}
            </p>

            {#each report.sections as section (section.key)}
                <section>
                    <h2 class="section-title mb-2">
                        {section.title}
                        <span class="section-count">{section.rows.length}</span>
                    </h2>
                    <ul class="border-t border-line">
                        {#each shown(section) as row (row.key)}
                            <li>
                                <a
                                    href={row.href}
                                    use:inertia
                                    class="row min-h-11 gap-2.5 px-1 py-1.5 hover:bg-hover"
                                >
                                    {#if row.person}
                                        <Avatar name={row.label} size="md" />
                                    {/if}
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate font-medium text-fg"
                                            >{row.label}{#if row.designation}
                                                <span
                                                    class="ml-1 font-normal text-fg-muted"
                                                    >{row.designation}</span
                                                >{/if}</span
                                        >
                                        <span
                                            class="block text-xs text-fg-muted"
                                            >{row.issues.join(' · ')}</span
                                        >
                                    </span>
                                    <ChevronRight
                                        class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                                    />
                                </a>
                            </li>
                        {/each}
                    </ul>
                    {#if section.rows.length > FIRST_ROWS}
                        <button
                            type="button"
                            class="btn-ghost mt-1"
                            onclick={() =>
                                expanded.has(section.key)
                                    ? expanded.delete(section.key)
                                    : expanded.add(section.key)}
                        >
                            {expanded.has(section.key)
                                ? 'Show fewer'
                                : `Show all ${section.rows.length}`}
                        </button>
                    {/if}
                </section>
            {/each}

            {#if report.passed.length > 0}
                <section>
                    <h2 class="section-title mb-2">Checked, nothing found</h2>
                    <ul class="space-y-1">
                        {#each report.passed as check (check)}
                            <li
                                class="flex items-center gap-2 text-[13px] text-fg-muted"
                            >
                                <Check class="h-3.5 w-3.5 text-success" />
                                {check}
                            </li>
                        {/each}
                    </ul>
                </section>
            {/if}
        </div>
    {/if}
</AppShell>
