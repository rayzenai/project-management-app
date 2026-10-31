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
    import CommandBlock from '../components/CommandBlock.svelte';
    import type { SharedProps } from '../lib/types';

    type Forge = { organization: string; url: string; owner: string };

    let {
        forges = [],
        gitUsername = null,
    }: { forges?: Forge[]; gitUsername?: string | null } = $props();

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
                'Hand a project to a new manager from its People tab with Make manager. The old manager stays on the team. Make member there leaves the project with no manager until you choose one.',
                'Open Maintenance, under Admin, now and then (the number on Admin says how much is waiting): it lists what needs fixing in your organization, such as projects with no manager or nobody on the team, open tasks nobody holds, people on no project or with no designation, each with a link to where you fix it.',
                'If you like, group projects into programs: New program on the Projects page names one and its program manager; then choose the program when you create or edit a project.',
                'Watch everything from the Projects page: projects in a program sit under it and the rest under their manager, with how much is done, what is overdue and the next deadline.',
                "On the Organogram page, give each person a designation and the person they report to (the menu at the end of each row). That draws your office's organogram and decides who sees whose attendance and work.",
            ],
        },
        {
            key: 'program',
            title: 'Program manager',
            steps: [
                'Open your program page every week, from Programs under Work in the sidebar: how far each project has got, what is overdue and who is working on what.',
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
            "On the project's Code tab, its manager, its program's manager or an organizational admin picks Link repository and chooses one of the office's repositories on the forge. Its main branch is protected from then on, as a new repository's is. Unlink stops following it; the repository stays on the forge.",
        ],
        [
            'Repository access',
            "Access, on a repository's row, shows who can reach it. The project's manager and its program's manager maintain it for as long as they hold the role, and give people on the project's team read access (clone and pull) or write access (push branches and open pull requests). An organizational admin can give it to anyone on the team or in the office. Leaving the project's team, or being deactivated, takes it away. Organizational admins and super-admins reach every repository.",
        ],
        [
            'First sign-in',
            "Someone given access who hasn't signed in to the forge yet shows so under their name. The forge can't have them until they do, and gets them within ten minutes of their first sign-in.",
        ],
        [
            'Code activity',
            "Code activity, under Reports in the sidebar, counts each person's commits, active days, pull requests and the lines they added and removed, split into code, comments and blank lines (a best guess from each file's language). Generated files, such as lock files and build output, and merge commits are left out. Tests shows how many of their commits passed and failed their tests. You see your own commits; project managers see everyone's in their projects' repositories, program managers in their programs' and organizational admins in their whole office's.",
        ],
        [
            'Forge access',
            "Organizational admins own the office's forge organization. An organizational admin becomes an owner once they have signed in to the forge, and each night anyone who has left the office or its projects is taken off it. Give access to a repository here, not on the forge: the tracker keeps the forge in step.",
        ],
    ];

    // The walkthrough names the reader's own forge. The examples use a
    // project whose key is TP, as the rest of the guide does, and the
    // office's own address for the email.
    const forge = $derived(forges[0] ?? null);
    const forgeHost = $derived(forge ? new URL(forge.url).host : 'the forge');
    const cloneUrl = $derived(
        forge
            ? `${forge.url}/${forge.owner}/tp-api.git`
            : 'https://your-forge/your-office/tp-api.git',
    );
    const commands = $derived({
        email: 'git config --global user.email "ceo@sdoc.gov.np"',
        clone: `git clone ${cloneUrl}\ncd tp-api`,
        first: [
            'git switch -c tp-1-set-up',
            '# add the code, and .github/workflows/tests.yml',
            'git add .',
            'git commit -m "TP-1 Set up the project and its tests"',
            'git push -u origin tp-1-set-up',
        ].join('\n'),
        bring: [
            `git remote add origin ${cloneUrl}`,
            'git fetch origin',
            'git switch -c tp-1-bring-in',
            'git merge origin/main --allow-unrelated-histories',
            'git push -u origin tp-1-bring-in',
        ].join('\n'),
        change: [
            'git switch main',
            'git pull',
            'git switch -c tp-12-fix-login',
            '# change the code and its tests, run the tests',
            'git add .',
            'git commit -m "TP-12 Fix the login form"',
            'git push -u origin tp-12-fix-login',
        ].join('\n'),
    });

    const workflow = [
        'name: tests',
        'on:',
        '  push:',
        '    branches: [main]',
        '  pull_request:',
        '',
        'jobs:',
        '  pest:',
        '    runs-on: ubuntu-latest',
        '    steps:',
        '      - uses: actions/checkout@v4',
        '      - uses: shivammathur/setup-php@v2',
        '        with:',
        "          php-version: '8.5'",
        '          coverage: none',
        '      - run: composer install --no-interaction --prefer-dist',
        '      - run: cp .env.example .env && php artisan key:generate',
        '      - run: ./vendor/bin/pest',
    ].join('\n');

    const tests = [
        [
            'Where they live',
            "A workflow file in .forgejo/workflows or .github/workflows at the repository's root. The forge reads only the first of .forgejo, .gitea and .github that exists, so keep every workflow in one of them.",
        ],
        [
            'When they run',
            "When the workflow's on: says. Use pull_request, so every pull request runs them, and again on each push to it, and push to main, so main is checked after each merge. Don't limit pull_request with paths: a pull request no test ran on can't merge.",
        ],
        [
            'Where they run',
            'On Ubuntu: runs-on: ubuntu-latest, ubuntu-24.04 or ubuntu-22.04. A job asking for anything else never starts. The runners are shared by the whole office, so a run may wait its turn, and a job still running after an hour is stopped.',
        ],
        [
            'What a job can use',
            'Actions written for GitHub, such as actions/checkout, shivammathur/setup-php and actions/setup-node, and services such as postgres:17 or redis, as on GitHub. Not docker build, and no volumes. Nothing is kept between runs, so each run installs its dependencies afresh.',
        ],
        [
            'Secrets',
            "Secrets from GitHub don't come along. Add what a workflow reads from secrets on the forge, in the repository's or the organization's Settings, then Actions, then Secrets.",
        ],
        [
            'Results',
            "Beside each commit and pull request in the Code tab and the task's Code section: a green tick when the tests passed, a red cross when one failed, an amber dashed ring while they run, a faint ring when nothing tested it. Each opens its run on the forge. Code activity counts each person's commits that passed and failed, and Maintenance lists repositories whose main fails.",
        ],
        [
            'Merging',
            "Every test passed, and one approval from someone other than the author: a teammate with write access, the program's manager or an organizational admin. The author's own approval doesn't count, so a project's only developer asks one of them.",
        ],
    ];

    const practice = [
        'Add or change the tests with every change, in the same pull request.',
        'Run them on your computer before you push: the runners are shared.',
        'Keep them fast, and give slow parts a job of their own.',
        'When main fails its tests, fixing it comes before anything new.',
        'Read why a test failed before running it again. A test that fails now and then gets fixed, not re-run until it passes.',
        'The approval is what guards main. A pull request runs its own version of the workflow, so read changes to it as closely as the code, and never post a test result by hand.',
    ];

    const structure = [
        [
            'Organogram',
            'Each person with their designation and the one person they report to, their higher authority. Fold and unfold it; Chart shows the top-down picture and Print prints it on its own. Everyone in the organization sees it: names, designations and lines, never emails.',
        ],
        [
            'Who changes it',
            "Only organizational admins and super-admins, because the lines decide who sees whose attendance and work. They keep the list of designations on the same page, and set whom someone reports to by dragging them onto their new higher authority, from the row's menu, or on the person's form in People. Nobody can report to themselves or to someone below them.",
        ],
        [
            'When people leave',
            "When someone leaves the organization or is deleted, the people who reported to them move up to that person's own higher authority. Deactivating someone keeps the lines.",
        ],
    ];

    const attendance = [
        [
            'Clocking in',
            "When your organization uses attendance, a Clock in button sits in the top bar on every page. Press it when you arrive; it then offers Start lunch, End lunch and Clock out, in that order, each once a day. The time is always the server's, not your device's.",
        ],
        [
            'What is recorded',
            'Each press keeps the time, your network address and, if your browser allows it, your location at that moment, to say where it came from: the office network, near the office, outside the office, or unknown. Nothing is recorded between presses. A press outside the office or from an unknown place asks for a short reason, and the people above you see it.',
        ],
        [
            'Leave and kaaj',
            'Ask on the Leave and kaaj page: the days, a reason, and for kaaj where you are going. Anyone above you, or an organizational admin, approves or rejects it; they can also record it for you, for example when you call in sick. Cancel your own request while it waits, or after approval until it starts. Kaaj is duty and never counts as absence.',
        ],
        [
            'Who should be in',
            'On a working day that is not a holiday, everyone active in the organization is expected except people exempt from attendance and people on approved leave or kaaj. "In" is everyone who clocked in. A clock-in after the start time plus the grace minutes (10:15 on winter hours) is late. Admins see the whole organization; everyone else sees the people below them.',
        ],
        [
            'Settings',
            'A super-admin turns attendance on for an organization. Its organizational admins then set the working days and hours (with seasons such as winter hours), the grace before late, the office places (stand in the office and use "Use my location"), the office networks (the page shows your address now; never add the VPN\'s range) and the holidays.',
        ],
    ];

    const work = [
        [
            "Today's work",
            'For one day, what each person finished, started and committed, listed by name. Someone with nothing recorded shows plainly, and with attendance on you also see when they came in.',
        ],
        [
            "Team's work",
            "The same for a week or any range up to three months, one row per person, with days in and hours when attendance is on. Sort any column; a row opens that person's week.",
        ],
        [
            'My week',
            'Your own tasks finished and started, what is overdue for you now, your commits and merged pull requests, and your attendance.',
        ],
        [
            'What counts',
            "A task counts for whoever changed its status, on that day: finished means Done or Failed, started means In Progress. Commits count for the person who made them, by the email they sign in with, merges left out. Tasks in projects you can't open and code you can't see are counted but never named.",
        ],
        [
            'Who sees it',
            "You always see your own. Everyone above you in the organogram, and your organization's admins, see yours. The lists show the people below you, or the whole organization for its admins.",
        ],
    ];

    const shortcuts = [
        ['N or Q', 'Add a task, from anywhere'],
        ['Ctrl K or /', 'Search tasks and projects (Cmd K on a Mac)'],
        ['[', 'Hide the panel beside the rail, or bring it back'],
        ['Esc', "Back to your page's own area after looking at another"],
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
                    Posts such as senior developer or analyst are the
                    organization's designations, given to each person on the
                    People page or the organogram. They give no powers.
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
                A person's post in the ministry is their designation, and
                everything here is optional.
            </p>
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="border-b border-line text-left">
                        <th class="col-head py-1.5 pr-4 font-normal">
                            Designation
                        </th>
                        <th class="col-head py-1.5 font-normal">Role</th>
                    </tr>
                </thead>
                <tbody>
                    {#each ministry as [designation, roleName] (designation)}
                        <tr class="border-b border-line-soft">
                            <td class="py-2 pr-4 font-medium">{designation}</td>
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

                <h3 class="mt-5 mb-2 font-medium">Set up once</h3>
                <ol
                    class="list-decimal space-y-3 pl-5 text-[13px] text-fg-muted"
                >
                    <li>
                        Sign in at
                        {#if forge}<a
                                class="text-accent hover:underline"
                                href={forge.url}
                                target="_blank"
                                rel="noopener noreferrer">{forgeHost}</a
                            >{:else}the forge{/if} with Sign in with sdoc-tasks, then
                        set up two-factor sign-in: the forge asks every person for
                        it.
                        {#if gitUsername}
                            Your forge username is
                            <span class="font-mono text-fg">{gitUsername}</span
                            >.
                        {:else}
                            Your forge username is made at that first sign-in.
                        {/if}
                    </li>
                    <li>
                        Let git on your computer reach it. HTTPS works from
                        anywhere: on the forge, Settings, then Applications,
                        generate a token that can read and write repositories,
                        and give it when git asks for a password (the forge has
                        no passwords of its own). Or add your SSH key under
                        Settings, then SSH keys; SSH may not reach the forge
                        from outside the office network.
                    </li>
                    <li>
                        Commit with the email you sign in with, so your commits
                        count for you, in place of ceo@sdoc.gov.np here:
                        <CommandBlock code={commands.email} />
                    </li>
                    <li>
                        Ask the project's manager for write access, on the Code
                        tab's Access. It reaches the forge within ten minutes of
                        your first sign-in there.
                    </li>
                </ol>

                <h3 class="mt-6 mb-2 font-medium">Start a repository</h3>
                <ol
                    class="list-decimal space-y-3 pl-5 text-[13px] text-fg-muted"
                >
                    <li>
                        On the project's Code tab, Link repository, then New
                        repository. Its name is the project's key in lowercase
                        (tp) or starts with it and a hyphen (tp-api), in
                        lowercase letters and digits with a single ., _ or -
                        between them. The project's manager, its program's
                        manager or an organizational admin does this. It starts
                        with a README on main, and main is protected from the
                        start: nobody pushes to it, not even an admin.
                    </li>
                    <li>
                        Clone it. The Code tab shows its HTTPS and SSH
                        addresses, each with a copy button.
                        <CommandBlock code={commands.clone} />
                    </li>
                    <li>
                        The first branch brings the code and its tests, since
                        nothing merges until a test has run (Tests, below):
                        <CommandBlock code={commands.first} />
                    </li>
                    <li>
                        On the forge, open a pull request from that branch into
                        main. Its tests run; once they pass and someone else
                        approves, merge it.
                    </li>
                </ol>

                <h3 class="mt-6 mb-2 font-medium">
                    Bring code you already have
                </h3>
                <p class="text-[13px] text-fg-muted">
                    A new repository's main already has its README, and nobody
                    can push over it. Join your history to it once, on a branch,
                    then bring it in through a pull request; your history comes
                    along.
                </p>
                <CommandBlock code={commands.bring} />
                <p class="mt-1.5 text-[13px] text-fg-muted">
                    If both have a README.md, git stops there: keep yours, then
                    git add README.md and git commit before you push.
                </p>

                <h3 class="mt-6 mb-2 font-medium">Every change</h3>
                <ol
                    class="list-decimal space-y-3 pl-5 text-[13px] text-fg-muted"
                >
                    <li>
                        Start from the latest main, on a branch whose name
                        starts with the task's code: tp-12-fix-login. Copy
                        branch name in the task's Code section gives you one.
                    </li>
                    <li>
                        Commit with the task's code in capitals: TP-12 Fix the
                        login form. Each code in a commit, a branch or a pull
                        request's title links it to its task.
                    </li>
                    <li>
                        Run the tests on your computer, push the branch and open
                        a pull request into main. Its tests run on the forge,
                        and again on every push to the branch.
                    </li>
                    <li>
                        Once they pass and someone else approves, merge it on
                        the forge. A merged pull request never marks a task
                        done: move the card when the work is really finished.
                    </li>
                </ol>
                <CommandBlock code={commands.change} />
                <p class="mt-1.5 text-[13px] text-fg-muted">
                    A push straight to main is always refused, an admin's too:
                    that is the protection working.
                </p>

                <h3 class="mt-6 mb-1 font-medium">Tests</h3>
                <p class="mb-2 text-[13px] text-fg-muted">
                    Every pull request runs its repository's tests on the
                    office's runners, and merges only once they all pass.
                </p>
                <dl class="border-t border-line">
                    {#each tests as [term, meaning] (term)}
                        <div
                            class="grid gap-1 border-b border-line-soft py-2 sm:grid-cols-[9rem_1fr] sm:gap-4"
                        >
                            <dt class="font-medium">{term}</dt>
                            <dd class="text-[13px] text-fg-muted">{meaning}</dd>
                        </div>
                    {/each}
                </dl>
                <h4 class="mt-4 mb-1.5 text-[13px] font-medium">
                    Good practice
                </h4>
                <ul class="list-disc space-y-1 pl-5 text-[13px] text-fg-muted">
                    {#each practice as line (line)}
                        <li>{line}</li>
                    {/each}
                </ul>
                <p class="mt-4 text-[13px] text-fg-muted">
                    A workflow for a Laravel project, in
                    .github/workflows/tests.yml:
                </p>
                <CommandBlock code={workflow} />

                <h3 class="mt-6 mb-2 font-medium">More about code</h3>
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

        {#if shared.office?.organogram}
            <section>
                <h2 class="section-title mb-3">Your office's structure</h2>
                <dl class="border-t border-line">
                    {#each structure as [term, meaning] (term)}
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

        {#if shared.clock || shared.office?.attendance || shared.office?.attendance_settings}
            <section>
                <h2 class="section-title mb-3">Attendance</h2>
                <dl class="border-t border-line">
                    {#each attendance as [term, meaning] (term)}
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

        {#if shared.office?.my_work_member_id || shared.office?.team_work}
            <section>
                <h2 class="section-title mb-3">Work reports</h2>
                <dl class="border-t border-line">
                    {#each work as [term, meaning] (term)}
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
