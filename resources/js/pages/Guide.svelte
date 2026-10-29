<script lang="ts">
    import { inertia, page } from '@inertiajs/svelte';
    import {
        Building2,
        ChevronRight,
        FolderKanban,
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
            title: 'Office admin',
            steps: [
                'Add people on the People page. Each one gets a login by email.',
                'Create projects on the Projects page and choose who manages each one.',
                "Check each project's key, the letters in its task codes (SG in SG-12), in Edit project. You or a super-admin can change it; the old key keeps working, so codes already written still find their tasks.",
                'Hand a project to a new manager from its People tab with Make manager. The old manager stays on the team.',
                'Watch everything from the Projects page: projects sit under their manager with how much is done, what is overdue and the next deadline.',
            ],
        },
        {
            key: 'manager',
            title: 'Project manager',
            steps: [
                "Build your team: on your project's People tab, search for people and add them. Only people on the team can be given its tasks.",
                'Break the work into tasks with an owner and a deadline. Press N anywhere to add one.',
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
                    <li class="flex items-center gap-2 sm:flex-1">
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
                                class="hidden h-4 w-4 shrink-0 text-fg-faint sm:block"
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
                    You see the projects whose team you are on. An office admin
                    sees every project of the office.
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
                                office admin.
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
