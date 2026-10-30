<script lang="ts">
    import { inertia, page } from '@inertiajs/svelte';
    import {
        Building2,
        ChevronRight,
        FolderKanban,
        FolderTree,
        ListChecks,
        UsersRound,
    } from '@lucide/svelte';
    import AppShell from '../components/AppShell.svelte';
    import type { SharedProps } from '../lib/types';

    const shared = $derived(page.props as unknown as SharedProps);

    // The section for the reader's own role is marked, so they start there.
    const role = $derived(
        shared.isSuperAdmin
            ? 'super'
            : shared.isOfficeAdmin
              ? 'admin'
              : (shared.managedProgramIds ?? []).length > 0
                ? 'program'
                : (shared.ledTeamIds ?? []).length > 0
                  ? 'manager'
                  : 'member',
    );

    const model = [
        {
            icon: Building2,
            title: 'Organization',
            text: 'Your office: its people and its projects.',
        },
        {
            icon: FolderTree,
            title: 'Program',
            text: 'Optional. A group of projects with one program manager.',
        },
        {
            icon: FolderKanban,
            title: 'Project',
            text: 'A piece of work the office has taken on.',
        },
        {
            icon: UsersRound,
            title: 'Team',
            text: 'Exactly one per project, led by its manager.',
        },
        {
            icon: ListChecks,
            title: 'Tasks',
            text: 'The work, each with an owner and a deadline.',
        },
    ];

    const roles = [
        {
            key: 'admin',
            title: 'Organizational admin',
            steps: [
                'Add people on the People page. Each one gets a login by email.',
                "Set a person's roles when you add or edit them there: the programs and projects they manage, and the project teams they are on. Choosing someone as a manager hands that program or project over to them; a project's old manager stays on its team.",
                'Create projects on the Projects page and choose who manages each one.',
                "Give a task to anyone in your organization from the task form (press N): if they are not on the project's team, they join it. With no project yet, choose General there and it is made for you to manage.",
                "Check each project's key, the letters in its task codes (SG in SG-12), in Edit project. You or a super-admin can change it; the old key keeps working, so codes already written still find their tasks.",
                'Hand a project to a new manager from its People tab with Make manager. The old manager stays on the team.',
                'If you like, group projects into programs: New program on the Projects page names one and its program manager; then choose the program when you create or edit a project.',
                'Watch everything from the Projects page: projects in a program sit under it and the rest under their manager, with how much is done, what is overdue and the next deadline.',
            ],
        },
        {
            key: 'program',
            title: 'Program manager',
            steps: [
                'Open your program page every week, from Programs in the sidebar: how far each project has got, what is overdue and who is working on what.',
                "Manage each project's team and tasks as its manager would: add people on its People tab, break the work into tasks and keep them moving.",
                "Create projects in your program with New project on its page, and choose or replace each project's manager. A new program comes with a General project to start from: add a task and assign someone straight away.",
                'Ask an organizational admin to move a project into your program or take one out.',
            ],
        },
        {
            key: 'manager',
            title: 'Project manager',
            steps: [
                "Build your team: on your project's People tab, search for people and add them. Anyone on the team can be given its tasks.",
                'Break the work into tasks with an owner and a deadline. Press N anywhere to add one.',
                "From the task form you can give a task to anyone in your organization: if they are not on your project's team yet, they join it.",
                'When a task cannot start until another is done, drag its card onto that task on the board.',
                "Open Progress every week: everyone's workload, a timeline of every task and what is running late.",
            ],
        },
        {
            key: 'member',
            title: 'Team member',
            steps: [
                'My Workspace lists everything given to you, soonest first.',
                'Keep your tasks current: move the card on the board, set your progress and write a status note when something changes. A task saves itself as you leave each field.',
                'Comment on a task and type @ and a name to bring someone in.',
                'Notifications tell you about new tasks, mentions, deadlines and teams you join.',
                'Want a log of meetings and decisions, or outside contacts, on your tasks? Turn on Task notes or Contacts in Settings.',
            ],
        },
    ];

    // How a ministry's ranks map onto the workspace's roles.
    const ministry = [
        ['Minister, Secretary, Secretariat', 'Organizational admin'],
        ['Joint Secretary', 'Program manager'],
        ['Under Secretary', 'Project manager'],
        ['Officer', 'Team member'],
    ];

    const reading = [
        [
            'Columns',
            'The board has one column per status. Drag a card to change it.',
        ],
        [
            'Waits for',
            'The task cannot start until the named task is done. It always sits below that task, with a dotted line between them. Hover a card to see its links.',
        ],
        [
            'Holds up',
            'Other tasks are waiting for this one, so finishing it frees them.',
        ],
        [
            'Overdue',
            'Past its deadline and still being worked on. Done and failed tasks are closed, so they are never overdue.',
        ],
        ['Progress ring', "The share of a project's tasks that are done."],
    ];

    const code = [
        [
            'Link a repository',
            "On the project's Code tab, its manager, its program's manager or an organizational admin picks Link repository and chooses one of the office's repositories on the forge. Unlink stops following it; the repository stays on the forge.",
        ],
        [
            'New repository',
            "Link repository, then New repository, makes a private repository on the forge and links it. Its name is the project's key in lowercase (tp) or starts with it and a hyphen (tp-api), in lowercase letters and digits with a single ., _ or - between them. Its main branch is protected: changes reach it through pull requests with one approval, and only the project's manager, its program's manager and the organizational admins push to it directly.",
        ],
        [
            'Repository access',
            "Access, on a repository's row, shows who can reach it. The project's manager and its program's manager maintain it for as long as they hold the role, and give people on the project's team read access (clone and pull) or write access (push branches and open pull requests). An organizational admin can give it to anyone on the team or in the office. Leaving the project's team, or being deactivated, takes it away. Organizational admins and super-admins reach every repository.",
        ],
        [
            'First sign-in',
            "Someone given access who hasn't signed in to SDOC Git yet shows so under their name. The forge can't have them until they do, and gets them within ten minutes of their first sign-in.",
        ],
        [
            'Name a branch',
            "Start the name with the task's code: tp-12-fix-login. Copy branch name in the task's Code section gives you one.",
        ],
        [
            'Write a commit',
            'Put the code in capitals in the commit message or the pull request title, such as TP-12 Fix the login form. Each code you name links to its task.',
        ],
        [
            'Merging',
            'A merged pull request never marks a task done. Move the card when the work is really finished.',
        ],
        [
            'Code activity',
            "Code activity, in the sidebar, counts each person's commits, active days, pull requests and the lines they added and removed, split into code, comments and blank lines (a best guess from each file's language). Generated files, such as lock files and build output, and merge commits are left out. You see your own commits; project managers see everyone's in their projects' repositories, program managers in their programs' and organizational admins in their whole office's.",
        ],
        [
            'Your git email',
            'A commit counts for you only when the email git signs it with is on your SDOC Git account. Add it there under Settings, Emails.',
        ],
        [
            'Forge access',
            "Organizational admins own the office's forge organization. An organizational admin becomes an owner once they have signed in to the forge, and each night anyone who has left the office or its projects is taken off it. Give access to a repository here, not on the forge: the tracker keeps the forge in step.",
        ],
    ];

    const shortcuts = [
        ['N or Q', 'Add a task, from anywhere'],
        ['Ctrl K or /', 'Search tasks and projects (Cmd K on a Mac)'],
        ['[', 'Fold the sidebar away'],
    ];

    const tokens = [
        ['#project', 'which project it belongs to'],
        ['@name', 'who does it'],
        ['!high', 'how urgent: !low, !medium, !high or !urgent'],
        ['today, fri, jun 20', 'when it is due'],
    ];
</script>

<svelte:head><title>Guide · Workspace</title></svelte:head>

<AppShell>
    {#snippet bar()}
        <span class="truncate font-medium">Guide</span>
    {/snippet}

    <div class="max-w-3xl space-y-10">
        <section>
            <h1 class="text-[17px] font-semibold tracking-[-0.02em]">
                How this workspace works
            </h1>
            <p class="mt-1 text-fg-muted">
                A short tour of how the office runs its projects here, and what
                each person does.
            </p>
        </section>

        <section>
            <h2 class="section-title mb-3">The idea</h2>
            <ol class="flex flex-col gap-2 sm:flex-row sm:items-stretch">
                {#each model as step, i (step.title)}
                    <li
                        class="flex items-center gap-2 sm:flex-1 sm:items-stretch"
                    >
                        <div class="panel flex-1 px-3 py-2.5">
                            <div class="flex items-center gap-1.5 font-medium">
                                <step.icon class="h-4 w-4 text-accent" />
                                {step.title}
                            </div>
                            <p class="mt-1 text-xs text-fg-muted">
                                {step.text}
                            </p>
                        </div>
                        {#if i < model.length - 1}
                            <ChevronRight
                                class="hidden h-4 w-4 shrink-0 self-center text-fg-faint sm:block"
                            />
                        {/if}
                    </li>
                {/each}
            </ol>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-[13px] text-fg-muted">
                <li>
                    A person can be on several project teams; a project has one
                    team and one manager.
                </li>
                <li>
                    Office titles such as senior developer or analyst are
                    written on each person. Nothing in the workspace depends on
                    them.
                </li>
                <li>
                    You see the projects whose team you are on. A program
                    manager sees every project in their programs, and an
                    organizational admin every project of the office.
                </li>
            </ul>
        </section>

        <section>
            <h2 class="section-title mb-3">Who does what</h2>
            <div class="grid gap-3">
                {#each roles as item (item.key)}
                    {@const mine =
                        item.key === role ||
                        (role === 'super' && item.key === 'admin')}
                    <div
                        class={`panel px-4 py-3 ${mine ? 'border-accent' : ''}`}
                    >
                        <h3 class="flex items-center gap-2 font-medium">
                            {item.title}
                            {#if mine}
                                <span class="chip chip-accent">You</span>
                            {/if}
                        </h3>
                        <ol
                            class="mt-2 list-decimal space-y-1.5 pl-5 text-[13px] text-fg-muted"
                        >
                            {#each item.steps as step (step)}
                                <li>{step}</li>
                            {/each}
                        </ol>
                    </div>
                {/each}
                {#if role === 'super'}
                    <div class="panel border-accent px-4 py-3">
                        <h3 class="flex items-center gap-2 font-medium">
                            Super-admin <span class="chip chip-accent">You</span
                            >
                        </h3>
                        <ul
                            class="mt-2 list-disc space-y-1.5 pl-5 text-[13px] text-fg-muted"
                        >
                            <li>
                                Create organizations and make someone their
                                organizational admin.
                            </li>
                            <li>
                                Let a project's team take people from other
                                organizations, in Edit project.
                            </li>
                        </ul>
                    </div>
                {/if}
            </div>
        </section>

        <section>
            <h2 class="section-title mb-1">In a ministry</h2>
            <p class="mb-3 text-[13px] text-fg-muted">
                Titles stay free text on each person, and everything here is
                optional.
            </p>
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="border-b border-line text-left">
                        <th class="col-head py-1.5 pr-4 font-normal">Title</th>
                        <th class="col-head py-1.5 font-normal">Role</th>
                    </tr>
                </thead>
                <tbody>
                    {#each ministry as [title, roleName] (title)}
                        <tr class="border-b border-line-soft">
                            <td class="py-2 pr-4 font-medium">{title}</td>
                            <td class="py-2 text-fg-muted">{roleName}</td>
                        </tr>
                    {/each}
                </tbody>
            </table>
        </section>

        <section>
            <h2 class="section-title mb-3">Reading a project</h2>
            <dl class="border-t border-line">
                {#each reading as [term, meaning] (term)}
                    <div
                        class="grid gap-1 border-b border-line-soft py-2 sm:grid-cols-[9rem_1fr] sm:gap-4"
                    >
                        <dt class="font-medium">{term}</dt>
                        <dd class="text-[13px] text-fg-muted">{meaning}</dd>
                    </div>
                {/each}
            </dl>
        </section>

        {#if shared.gitEnabled}
            <section>
                <h2 class="section-title mb-1">Code</h2>
                <p class="mb-3 text-[13px] text-fg-muted">
                    When your office keeps its code on the forge, each project
                    has a Code tab with its repositories, and each task a Code
                    section with the pull requests, branches and commits that
                    name its code. Both are for the project's team, its
                    program's manager and the organizational admins.
                </p>
                <dl class="border-t border-line">
                    {#each code as [term, meaning] (term)}
                        <div
                            class="grid gap-1 border-b border-line-soft py-2 sm:grid-cols-[9rem_1fr] sm:gap-4"
                        >
                            <dt class="font-medium">{term}</dt>
                            <dd class="text-[13px] text-fg-muted">{meaning}</dd>
                        </div>
                    {/each}
                </dl>
            </section>
        {/if}

        <section>
            <h2 class="section-title mb-3">Shortcuts</h2>
            <dl class="border-t border-line">
                {#each shortcuts as [keys, meaning] (keys)}
                    <div
                        class="grid gap-1 border-b border-line-soft py-2 sm:grid-cols-[9rem_1fr] sm:gap-4"
                    >
                        <dt><span class="kbd">{keys}</span></dt>
                        <dd class="text-[13px] text-fg-muted">{meaning}</dd>
                    </div>
                {/each}
            </dl>
            <p class="mt-4 text-[13px] text-fg-muted">
                When adding a task, the title can carry the details:
            </p>
            <dl class="mt-2 border-t border-line">
                {#each tokens as [token, meaning] (token)}
                    <div
                        class="grid gap-1 border-b border-line-soft py-2 sm:grid-cols-[9rem_1fr] sm:gap-4"
                    >
                        <dt class="font-mono text-[12.5px]">{token}</dt>
                        <dd class="text-[13px] text-fg-muted">{meaning}</dd>
                    </div>
                {/each}
            </dl>
        </section>

        <section class="text-[13px] text-fg-muted">
            Start from
            <a href="/workspace/my" use:inertia class="text-accent"
                >My Workspace</a
            >,
            <a href="/workspace/projects" use:inertia class="text-accent"
                >Projects</a
            >
            or
            <a href="/workspace/team" use:inertia class="text-accent">People</a
            >.
        </section>
    </div>
</AppShell>
