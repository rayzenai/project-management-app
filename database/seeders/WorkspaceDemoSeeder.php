<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectContact;
use App\Models\ProjectDigestSubscriber;
use App\Models\ProjectNote;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkspaceNote;
use App\Notifications\MentionedInComment;
use App\Notifications\TaskDeadlineDue;
use App\Notifications\TaskStatusChanged;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A full demo workspace: six logins across the three authorization tiers, three
 * teams, four projects and a task set that covers every status in
 * config('project-management.statuses') plus the states the UI derives rather
 * than stores — overdue, completed-late, rolling, no deadline, and each of the
 * four freshness buckets (moved / fresh / stalled / cold).
 *
 * Deliberately NOT wired into DatabaseSeeder: that one stays production-safe and
 * seeds only the super-admin. Run this one explicitly:
 *
 *     php artisan db:seed --class=WorkspaceDemoSeeder
 *
 * Re-running is safe. People, teams and projects are matched on their natural
 * key and updated in place; a project's tasks (and everything hanging off them)
 * are force-deleted and rebuilt so counts never drift.
 *
 * Model events stay ON, so the observers write the activity log and the
 * assignment notifications exactly as they would in the app.
 */
class WorkspaceDemoSeeder extends Seeder
{
    /** Every seeded login shares this password. */
    private const PASSWORD = 'password';

    /**
     * Checklist lines the seeded subtasks rotate through, so each task's todos
     * read like that task's own work rather than a copied template.
     *
     * @var list<string>
     */
    private const TODO_POOL = [
        'Pull the current numbers from the district returns',
        'Circulate the draft note to the secretariat',
        'Book the follow-up review with the ministry',
        'Chase the pending sign-off',
        'Reconcile the figures with the treasury export',
        'Confirm the focal person in each province',
        'Draft the cabinet note for the next sitting',
        'Publish the revised checklist to the portal',
        'Close out the outstanding audit query',
        'Agree the handover date with the contractor',
        'Verify the vendor invoice against the contract',
    ];

    /** @var array<string, User> handle => user (only people with a login) */
    private array $users = [];

    /** @var array<string, Member> handle => member (everyone) */
    private array $members = [];

    /** @var array<string, Team> slug => team */
    private array $teams = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('WorkspaceDemoSeeder refuses to run in production.');

            return;
        }

        fake()->seed(20260904);

        $this->seedPeople();

        // After seedPeople, so the logins it provisions keep their real names
        // rather than the one WorkspaceSuperadminSeeder derives from the email.
        // Still called, to cover any PM_SUPER_ADMINS entry not seeded above.
        $this->call(WorkspaceSuperadminSeeder::class);

        $this->seedTeams();

        // Assignment and status notifications are re-emitted by the observers as
        // the tasks are rebuilt below, so clear the previous run's first. Unlike
        // the activity log they hang off the user, not the task, so nothing
        // cascades them away.
        DB::table('notifications')
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', collect($this->users)->map->getKey()->all())
            ->delete();

        $this->seedProjects();
        $this->seedStickies();
        $this->seedDigestSubscribers();
        $this->seedNotifications();

        Auth::logout();

        $this->summarise();
    }

    /**
     * Six people. Four of the three tiers are represented: one super-admin (the
     * email in PM_SUPER_ADMINS), two team leaders, three regular members, plus
     * two members with no login at all — one active, one deactivated — because
     * assignment and roster code has to cope with both.
     */
    private function seedPeople(): void
    {
        /** @var list<array{handle: string, name: string, email: string, title: string, login: bool, active?: bool, categories?: list<string>, coordinator_role?: string}> $people */
        $people = [
            [
                'handle' => 'admin',
                'name' => 'Workspace Admin',
                'email' => (string) (config('project-management.super_admins')[0] ?? 'pmopm@example.com'),
                'title' => 'Chief Secretary',
                'login' => true,
            ],
            [
                // Super-admin too: the `manage-workspace` Gate reads the email
                // list from PM_SUPER_ADMINS, so this seeder only provisions the
                // login — the .env entry is what grants the ability.
                'handle' => 'namrata',
                'name' => 'Namrata Lohani',
                'email' => 'namru.mail@gmail.com',
                'title' => 'Workspace Owner',
                'login' => true,
            ],
            [
                'handle' => 'asha',
                'name' => 'Asha Gurung',
                'email' => 'asha@pmopm.test',
                'title' => 'Delivery Unit Chief',
                'login' => true,
                'categories' => ['digital_governance', 'service_delivery'],
                'coordinator_role' => 'Delivery coordinator',
            ],
            [
                'handle' => 'bikash',
                'name' => 'Bikash Thapa',
                'email' => 'bikash@pmopm.test',
                'title' => 'Reform Desk Lead',
                'login' => true,
                'categories' => ['admin_reform', 'anti_corruption'],
                'coordinator_role' => 'Reform coordinator',
            ],
            [
                'handle' => 'chhaya',
                'name' => 'Chhaya Rai',
                'email' => 'chhaya@pmopm.test',
                'title' => 'Programme Officer',
                'login' => true,
            ],
            [
                'handle' => 'deepak',
                'name' => 'Deepak Shrestha',
                'email' => 'deepak@pmopm.test',
                'title' => 'Field Operations Lead',
                'login' => true,
            ],
            [
                'handle' => 'eliza',
                'name' => 'Eliza Magar',
                'email' => 'eliza@pmopm.test',
                'title' => 'Data Analyst',
                'login' => true,
            ],
            [
                'handle' => 'nirmala',
                'name' => 'Nirmala Bhandari',
                'email' => 'nirmala@pmopm.test',
                'title' => 'District Liaison',
                'login' => false,
            ],
            [
                'handle' => 'sujan',
                'name' => 'Sujan Karki',
                'email' => 'sujan@pmopm.test',
                'title' => 'Records Officer (on leave)',
                'login' => false,
                'active' => false,
            ],
        ];

        foreach ($people as $spec) {
            $user = null;

            if ($spec['login']) {
                $user = User::query()->firstOrCreate(
                    ['email' => $spec['email']],
                    [
                        'name' => $spec['name'],
                        'password' => Hash::make(self::PASSWORD),
                        'email_verified_at' => now(),
                    ],
                );

                // Correct a name seeded from an earlier run (or derived from the
                // email by WorkspaceSuperadminSeeder) without touching the
                // password, which stays whatever the account already set.
                if ($user->name !== $spec['name']) {
                    $user->forceFill(['name' => $spec['name']])->save();
                }

                $this->users[$spec['handle']] = $user;
            }

            $this->members[$spec['handle']] = Member::query()->updateOrCreate(
                ['email' => $spec['email']],
                [
                    'user_id' => $user?->getKey(),
                    'name' => $spec['name'],
                    'title' => $spec['title'],
                    'is_active' => $spec['active'] ?? true,
                    'coordinator_categories' => $spec['categories'] ?? null,
                    'coordinator_role' => $spec['coordinator_role'] ?? null,
                ],
            );
        }
    }

    /**
     * Three teams. Deepak leads Field Ops while sitting as a plain member on
     * Reform Desk, which is the shape every `leadsTeam` / `canManageRosterOf`
     * decision has to get right.
     */
    private function seedTeams(): void
    {
        /** @var list<array{slug: string, name: string, description: string, color: string, roster: array<string, string>}> $teams */
        $teams = [
            [
                'slug' => 'delivery-unit',
                'name' => 'Delivery Unit',
                'description' => 'Tracks the flagship commitments end to end.',
                'color' => '#3B82F6',
                'roster' => ['asha' => 'leader', 'chhaya' => 'member', 'admin' => 'member', 'namrata' => 'member'],
            ],
            [
                'slug' => 'reform-desk',
                'name' => 'Reform Desk',
                'description' => 'Legal and administrative reform workstream.',
                'color' => '#8B5CF6',
                'roster' => ['bikash' => 'leader', 'deepak' => 'member', 'eliza' => 'member', 'namrata' => 'member'],
            ],
            [
                'slug' => 'field-ops',
                'name' => 'Field Ops',
                'description' => 'District-level verification and follow-up.',
                'color' => '#059669',
                'roster' => ['deepak' => 'leader', 'nirmala' => 'member', 'sujan' => 'member', 'namrata' => 'member'],
            ],
        ];

        foreach ($teams as $spec) {
            $team = Team::query()->updateOrCreate(
                ['slug' => $spec['slug']],
                [
                    'name' => $spec['name'],
                    'description' => $spec['description'],
                    'color' => $spec['color'],
                ],
            );

            $roster = [];

            foreach ($spec['roster'] as $handle => $role) {
                $roster[$this->members[$handle]->getKey()] = ['role' => $role];
            }

            $team->members()->sync($roster);

            $this->teams[$spec['slug']] = $team;
        }
    }

    /**
     * Four projects: the flagship plan, a private in-flight programme, a public
     * one owned by a single team, and an archived one so the archive filters
     * have something to show.
     */
    private function seedProjects(): void
    {
        /** @var list<array{slug: string, title: string, title_np: ?string, description: string, is_public: bool, archived: bool, starts_in: int, ends_in: int, teams: list<string>, tasks: list<array<string, mixed>>}> $projects */
        $projects = [
            [
                'slug' => '100-day-plan',
                'title' => 'Government 100-Day Plan',
                'title_np' => 'सरकारको १०० दिने योजना',
                'description' => 'The '.config('government.plan_short_name').' tracked item by item.',
                'is_public' => true,
                'archived' => false,
                'starts_in' => -160,
                'ends_in' => -60,
                'teams' => ['delivery-unit', 'reform-desk'],
                'tasks' => $this->flagshipTasks(),
            ],
            [
                'slug' => 'digital-nepal-rollout',
                'title' => 'Digital Nepal Rollout',
                'title_np' => null,
                'description' => 'Phase two of the national digital service platform.',
                'is_public' => false,
                'archived' => false,
                'starts_in' => -40,
                'ends_in' => 140,
                'teams' => ['reform-desk'],
                'tasks' => $this->secondaryTasks(100, 'digital_governance', 'Ministry of Communication and IT'),
            ],
            [
                'slug' => 'ward-service-upgrade',
                'title' => 'Ward Service Upgrade',
                'title_np' => null,
                'description' => 'Front-desk service standards across all 753 local units.',
                'is_public' => true,
                'archived' => false,
                'starts_in' => -20,
                'ends_in' => 90,
                'teams' => ['field-ops'],
                'tasks' => $this->secondaryTasks(200, 'service_delivery', 'Ministry of Federal Affairs'),
            ],
            [
                'slug' => 'legacy-records-cleanup',
                'title' => 'Legacy Records Cleanup',
                'title_np' => null,
                'description' => 'Closed out in the previous cycle; kept for reference.',
                'is_public' => false,
                'archived' => true,
                'starts_in' => -300,
                'ends_in' => -120,
                'teams' => ['delivery-unit'],
                'tasks' => $this->secondaryTasks(300, 'admin_reform', 'National Archives'),
            ],
        ];

        $today = CarbonImmutable::today();

        foreach ($projects as $spec) {
            $project = Project::query()->updateOrCreate(
                ['slug' => $spec['slug']],
                [
                    'title' => $spec['title'],
                    'title_np' => $spec['title_np'],
                    'description' => $spec['description'],
                    'is_public' => $spec['is_public'],
                    'starts_at' => $today->addDays($spec['starts_in']),
                    'ends_at' => $today->addDays($spec['ends_in']),
                ],
            );

            $project->archived_at = $spec['archived'] ? $today->subDays(30)->setTime(9, 0) : null;
            $project->save();

            $project->teams()->sync(
                collect($spec['teams'])->map(fn (string $slug): int => $this->teams[$slug]->getKey())->all(),
            );

            $this->resetTasks($project);

            foreach ($spec['tasks'] as $index => $taskSpec) {
                $this->seedTask($project, $taskSpec, $index);
            }
        }
    }

    /**
     * Drop the project's existing tasks for real (children cascade in the
     * database) so a re-run rebuilds rather than duplicates.
     */
    private function resetTasks(Project $project): void
    {
        Task::withTrashed()
            ->where('project_id', $project->getKey())
            ->get()
            ->each(fn (Task $task) => $task->forceDelete());
    }

    /**
     * Fifteen tasks — three in each of the five statuses — spread across the
     * derived states the UI treats differently.
     *
     * @return list<array<string, mixed>>
     */
    private function flagshipTasks(): array
    {
        return [
            // ---- not_started -------------------------------------------------
            [
                'item_number' => 1,
                'title' => 'Publish the cabinet asset declaration portal',
                'title_np' => 'मन्त्रिपरिषद् सम्पत्ति विवरण पोर्टल सार्वजनिक गर्ने',
                'description' => 'Every minister files a machine-readable declaration before taking charge of a portfolio.',
                'category' => 'anti_corruption',
                'deadline_type' => '30d',
                'status' => 'not_started',
                'priority' => 'urgent',
                'progress' => 0,
                'deadline_in' => 12,
                'updated_hours_ago' => 3,          // moved
                'ministry' => 'Office of the Prime Minister',
                'assign' => ['bikash', 'chhaya', 'namrata'],
                'focus' => ['namrata'],
            ],
            [
                'item_number' => 2,
                'title' => 'Draft the federal civil service amendment bill',
                'description' => 'Second amendment covering lateral entry, performance review and transfer rules.',
                'category' => 'admin_reform',
                'deadline_type' => '90d',
                'status' => 'not_started',
                'priority' => 'high',
                'progress' => 0,
                'deadline_in' => 54,
                'updated_hours_ago' => 20 * 24,    // stalled
                'ministry' => 'Ministry of Federal Affairs',
                'assign' => ['bikash', 'namrata'],
            ],
            [
                'item_number' => 3,
                'title' => 'Map every rolling public grievance channel',
                'description' => 'No fixed date: an always-on inventory of hotlines, desks and portals citizens actually use.',
                'category' => 'service_delivery',
                'deadline_type' => 'rolling',      // no deadline_at at all
                'status' => 'not_started',
                'priority' => 'low',
                'progress' => 0,
                'deadline_in' => null,
                'updated_hours_ago' => 41 * 24,    // cold
                'assign' => ['nirmala', 'namrata'],
            ],

            // ---- unclear -----------------------------------------------------
            [
                'item_number' => 4,
                'title' => 'Clarify scope of the provincial data-sharing MoU',
                'description' => 'Three provinces read the sharing clause differently; the scope has to be settled before build.',
                'category' => 'digital_governance',
                'deadline_type' => 'unspecified',  // no deadline_at
                'status' => 'unclear',
                'priority' => 'medium',
                'progress' => 10,
                'deadline_in' => null,
                'updated_hours_ago' => 5 * 24,     // fresh
                'status_note' => 'Waiting on a written opinion from the Attorney General.',
                'assign' => ['asha', 'eliza', 'namrata'],
            ],
            [
                'item_number' => 5,
                'title' => 'Confirm ownership of the stalled Budhi Gandaki file',
                'description' => 'Two ministries each believe the other is the lead agency.',
                'category' => 'energy_water',
                'deadline_type' => '60d',
                'status' => 'unclear',
                'priority' => 'high',
                'progress' => 15,
                'deadline_in' => -6,               // overdue and not done -> is_late
                'updated_hours_ago' => 33 * 24,    // cold
                'ministry' => 'Ministry of Energy',
                'assign' => ['namrata', 'deepak'],
                'snooze' => ['deepak'],
            ],
            [
                'item_number' => 6,
                'title' => 'Decide whether ward offices keep paper registries',
                'description' => 'Parallel paper and digital registries are producing two versions of the truth.',
                'category' => 'service_delivery',
                'deadline_type' => '45d',
                'status' => 'unclear',
                'priority' => 'medium',
                'progress' => 5,
                'deadline_in' => 3,
                'updated_hours_ago' => 26,         // moved
                'assign' => ['chhaya', 'nirmala', 'namrata'],
            ],

            // ---- in_progress -------------------------------------------------
            [
                'item_number' => 7,
                'title' => 'Move all land revenue payments to the national gateway',
                'title_np' => 'मालपोत राजस्व भुक्तानी राष्ट्रिय गेटवेमा सार्ने',
                'description' => 'Cash counters close once all 134 offices settle through the gateway.',
                'category' => 'revenue_reform',
                'deadline_type' => '100d',
                'status' => 'in_progress',
                'priority' => 'urgent',
                'progress' => 55,
                'deadline_in' => 31,
                'updated_hours_ago' => 6,          // moved
                'ministry' => 'Ministry of Finance',
                'assign' => ['asha', 'deepak', 'chhaya', 'namrata'],
                'focus' => ['asha', 'namrata'],
            ],
            [
                'item_number' => 8,
                'title' => 'Open a one-window investment approval desk',
                'description' => 'Single intake for every approval an investor needs below the board threshold.',
                'category' => 'investment_industry',
                'deadline_type' => '90d',
                'status' => 'in_progress',
                'priority' => 'high',
                'progress' => 40,
                'deadline_in' => 19,
                'updated_hours_ago' => 4 * 24,     // fresh
                'ministry' => 'Ministry of Industry',
                'assign' => ['bikash', 'eliza', 'namrata'],
                'snooze' => ['namrata'],
            ],
            [
                'item_number' => 9,
                'title' => 'Digitise twenty years of court case records',
                'description' => 'Scanning is ahead of schedule; indexing and redaction are not.',
                'category' => 'digital_governance',
                'deadline_type' => '180d',
                'status' => 'in_progress',
                'priority' => 'medium',
                'progress' => 70,
                'deadline_in' => -2,               // overdue while still in flight
                'updated_hours_ago' => 17 * 24,    // stalled
                'status_note' => 'Redaction queue is the bottleneck; two more operators requested.',
                'assign' => ['namrata', 'eliza'],
            ],

            // ---- done --------------------------------------------------------
            [
                'item_number' => 10,
                'title' => 'Publish the public plan tracker',
                'title_np' => 'सार्वजनिक योजना ट्र्याकर प्रकाशन',
                'description' => 'The tracker itself, shipped on day seven and open to anyone.',
                'category' => 'digital_governance',
                'deadline_type' => '7d',
                'status' => 'done',
                'priority' => 'urgent',
                'progress' => 100,
                'deadline_in' => -85,
                'completed_days_ago' => 88,        // finished before the deadline
                'updated_hours_ago' => 88 * 24,    // cold
                'ministry' => 'Office of the Prime Minister',
                'assign' => ['asha', 'eliza', 'admin'],
            ],
            [
                'item_number' => 11,
                'title' => 'Cut forty-two redundant licence renewals',
                'description' => 'Annual renewals folded into a single five-year registration.',
                'category' => 'admin_reform',
                'deadline_type' => '45d',
                'status' => 'done',
                'priority' => 'high',
                'progress' => 100,
                'deadline_in' => -20,
                'completed_days_ago' => 3,         // completed AFTER the deadline -> is_late
                'updated_hours_ago' => 3 * 24,     // fresh
                'ministry' => 'Ministry of Industry',
                'assign' => ['bikash', 'chhaya', 'namrata'],
            ],
            [
                'item_number' => 12,
                'title' => 'Restore the Melamchi supply line to full flow',
                'description' => 'Headworks repaired and the intake back at design capacity.',
                'category' => 'energy_water',
                'deadline_type' => '30d',
                'status' => 'done',
                'priority' => 'medium',
                'progress' => 100,
                'deadline_in' => -40,
                'completed_days_ago' => 41,
                'updated_hours_ago' => 41 * 24,    // cold
                'ministry' => 'Ministry of Water Supply',
                'assign' => ['deepak', 'nirmala', 'namrata'],
                'focus' => ['deepak'],
            ],

            // ---- failed ------------------------------------------------------
            [
                'item_number' => 13,
                'title' => 'Issue the smart national ID to one million citizens',
                'description' => 'Enrolment ran; card production never started.',
                'category' => 'digital_governance',
                'deadline_type' => '60d',
                'status' => 'failed',
                'priority' => 'high',
                'progress' => 45,
                'deadline_in' => -15,
                'updated_hours_ago' => 15 * 24,    // stalled
                'status_note' => 'Vendor contract lapsed mid-run; a re-tender is now unavoidable.',
                'ministry' => 'Ministry of Home Affairs',
                'assign' => ['eliza', 'deepak', 'namrata'],
            ],
            [
                'item_number' => 14,
                'title' => 'Audit all 753 local units for procurement fraud',
                'description' => 'Scope was set for 753 units against an audit capacity of roughly ninety.',
                'category' => 'anti_corruption',
                'deadline_type' => '100d',
                'status' => 'failed',
                'priority' => 'urgent',
                'progress' => 30,
                'deadline_in' => -4,
                'updated_hours_ago' => 4 * 24,     // fresh
                'status_note' => 'Reset to a risk-ranked sample of ninety units for the next cycle.',
                'ministry' => 'Office of the Auditor General',
                'assign' => ['bikash', 'admin'],
            ],
            [
                'item_number' => 15,
                'title' => 'Hand over twelve stalled road packages',
                'description' => 'Contractor claims were unresolved, so none of the twelve could be handed over.',
                'category' => 'procurement_project',
                'deadline_type' => '45d',
                'status' => 'failed',
                'priority' => 'medium',
                'progress' => 20,
                'deadline_in' => -55,
                'updated_hours_ago' => 60 * 24,    // cold
                'ministry' => 'Ministry of Physical Infrastructure',
                'assign' => ['nirmala', 'admin'],
            ],
        ];
    }

    /**
     * One task per status for the supporting projects, so every board column is
     * populated everywhere — not only on the flagship plan.
     *
     * @return list<array<string, mixed>>
     */
    private function secondaryTasks(int $itemNumberBase, string $category, string $ministry): array
    {
        /** @var list<array{status: string, title: string, description: string, priority: string, progress: int, deadline_type: string, deadline_in: ?int, updated_hours_ago: int, completed_days_ago?: int, status_note?: string, assign: list<string>, focus?: list<string>, snooze?: list<string>}> $shape */
        $shape = [
            [
                'status' => 'not_started',
                'title' => 'Agree the delivery baseline',
                'description' => 'Scope, owners and the reporting cadence signed off before any build starts.',
                'priority' => 'medium',
                'progress' => 0,
                'deadline_type' => '30d',
                'deadline_in' => 21,
                'updated_hours_ago' => 12,
                'assign' => ['asha', 'namrata'],
            ],
            [
                'status' => 'unclear',
                'title' => 'Settle who owns the shared data set',
                'description' => 'Two directorates each maintain a copy; neither will retire theirs.',
                'priority' => 'low',
                'progress' => 5,
                'deadline_type' => 'unspecified',
                'deadline_in' => null,
                'updated_hours_ago' => 19 * 24,
                'status_note' => 'Parked until the MoU question is answered.',
                'assign' => ['eliza', 'namrata'],
            ],
            [
                'status' => 'in_progress',
                'title' => 'Run the first district pilot',
                'description' => 'Three districts on the new flow for a full reporting month.',
                'priority' => 'high',
                'progress' => 60,
                'deadline_type' => '60d',
                'deadline_in' => 9,
                'updated_hours_ago' => 30,
                'assign' => ['deepak', 'nirmala', 'admin'],
                'focus' => ['deepak'],
            ],
            [
                'status' => 'done',
                'title' => 'Sign the vendor support agreement',
                'description' => 'Two-year support and escalation terms agreed and countersigned.',
                'priority' => 'medium',
                'progress' => 100,
                'deadline_type' => '15d',
                'deadline_in' => -34,
                'completed_days_ago' => 36,
                'updated_hours_ago' => 36 * 24,
                'assign' => ['bikash'],
            ],
            [
                'status' => 'failed',
                'title' => 'Migrate the legacy registry in one pass',
                'description' => 'The single-pass cutover was abandoned after the dry run lost referential integrity.',
                'priority' => 'high',
                'progress' => 35,
                'deadline_type' => '45d',
                'deadline_in' => -11,
                'updated_hours_ago' => 11 * 24,
                'status_note' => 'Replaced by a phased migration in the next cycle.',
                'assign' => ['chhaya', 'admin'],
            ],
        ];

        $tasks = [];

        foreach ($shape as $offset => $spec) {
            $tasks[] = array_merge($spec, [
                'item_number' => $itemNumberBase + $offset + 1,
                'category' => $category,
                'ministry' => $ministry,
            ]);
        }

        return $tasks;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function seedTask(Project $project, array $spec, int $index): void
    {
        $today = CarbonImmutable::today();

        /** @var int $itemNumber */
        $itemNumber = $spec['item_number'];
        /** @var string $title */
        $title = $spec['title'];
        /** @var int|null $deadlineIn */
        $deadlineIn = $spec['deadline_in'] ?? null;
        /** @var int|null $completedDaysAgo */
        $completedDaysAgo = $spec['completed_days_ago'] ?? null;

        // The super-admin sets the board up; the assignees do the work below.
        Auth::login($this->users['admin']);

        $task = Task::query()->create([
            'project_id' => $project->getKey(),
            'slug' => $itemNumber.'-'.Str::slug(Str::limit($title, 60, '')),
            'item_number' => $itemNumber,
            'title' => $title,
            'title_np' => $spec['title_np'] ?? null,
            'description' => $spec['description'],
            'category' => $spec['category'],
            'deadline_type' => $spec['deadline_type'],
            // Left null for the rolling / unspecified types, whose config entry
            // carries no day count, so Task::booted leaves them dateless too.
            'deadline_at' => $deadlineIn === null ? null : $today->addDays($deadlineIn),
            'status' => $spec['status'],
            'priority' => $spec['priority'],
            'progress' => $spec['progress'],
            'status_note' => $spec['status_note'] ?? null,
            'responsible_ministry' => $spec['ministry'] ?? null,
            'status_updated_at' => CarbonImmutable::now()->subHours((int) $spec['updated_hours_ago']),
            // Set explicitly so a "finished late" task survives the saving hook,
            // which only backfills completed_at when it is still null.
            'completed_at' => $completedDaysAgo === null ? null : $today->subDays($completedDaysAgo)->setTime(11, 30),
        ]);

        // sort_order is not mass-assignable; set it quietly so the board keeps
        // the authored order without an extra activity row.
        $task->sort_order = $index;
        $task->saveQuietly();

        /** @var list<string> $assignees */
        $assignees = $spec['assign'] ?? [];
        /** @var list<string> $focus */
        $focus = $spec['focus'] ?? [];
        /** @var list<string> $snooze */
        $snooze = $spec['snooze'] ?? [];

        $this->seedAssignments($task, $assignees, $focus, $snooze);
        $this->seedSubtasks($task, $assignees);
        $this->seedNotes($task, $assignees);
        $this->seedContacts($task, $itemNumber);
        $this->seedComments($task, $assignees, $itemNumber);

        Auth::login($this->users['admin']);
    }

    /**
     * First assignee is the lead. Focus and snooze are named per task rather
     * than derived from the item number, so the My Workspace lanes (Due soon /
     * Focused / Everything else / Snoozed) can be populated on purpose.
     *
     * @param  list<string>  $assignees
     * @param  list<string>  $focus
     * @param  list<string>  $snooze
     */
    private function seedAssignments(Task $task, array $assignees, array $focus, array $snooze): void
    {
        $today = CarbonImmutable::today();

        foreach ($assignees as $position => $handle) {
            $member = $this->members[$handle];
            $isLead = $position === 0;

            ProjectAssignment::query()->create([
                'member_id' => $member->getKey(),
                'task_id' => $task->getKey(),
                'role' => $isLead ? 'Lead' : fake()->randomElement(['Support', 'Reviewer', 'Field verification']),
                'priority' => $isLead ? $task->priority : fake()->randomElement(['low', 'medium', 'high']),
                'personal_progress' => $isLead ? $task->progress : max(0, $task->progress - fake()->numberBetween(10, 30)),
                'personal_due_at' => $task->deadline_at?->subDays(fake()->numberBetween(1, 5)),
                'personal_status_note' => $isLead ? null : fake()->sentence(),
                'is_focused' => in_array($handle, $focus, true),
                'snoozed_until' => in_array($handle, $snooze, true) ? $today->addDays(4) : null,
            ]);
        }
    }

    /**
     * Subtasks are personal, so they only exist for assignees who have a login.
     * Each such assignee gets a mix of done, open and one overdue item.
     *
     * @param  list<string>  $assignees
     */
    private function seedSubtasks(Task $task, array $assignees): void
    {
        $today = CarbonImmutable::today();

        foreach ($assignees as $handle) {
            $user = $this->users[$handle] ?? null;

            if ($user === null) {
                continue;
            }

            Auth::login($user);

            // Rotate through the pool by item number so no two tasks carry an
            // identical checklist.
            $offset = ((int) $task->item_number) % count(self::TODO_POOL);
            $bodies = array_slice(array_merge(self::TODO_POOL, self::TODO_POOL), $offset, 4);

            /** @var list<array{body: string, done: bool, due_in: ?int}> $todos */
            $todos = [
                ['body' => $bodies[0], 'done' => true, 'due_in' => -9],
                ['body' => $bodies[1], 'done' => $task->isComplete(), 'due_in' => -2],
                ['body' => $bodies[2], 'done' => false, 'due_in' => 6],
                ['body' => $bodies[3], 'done' => false, 'due_in' => -3],
            ];

            foreach ($todos as $position => $todo) {
                Subtask::query()->create([
                    'task_id' => $task->getKey(),
                    'user_id' => $user->getKey(),
                    'body' => $todo['body'],
                    'is_done' => $todo['done'],
                    'done_at' => $todo['done'] ? CarbonImmutable::now()->subDays(fake()->numberBetween(1, 20)) : null,
                    'due_at' => $todo['due_in'] === null ? null : $today->addDays($todo['due_in']),
                    'position' => $position,
                ]);
            }
        }
    }

    /**
     * One note per type across the board, so every note type renders somewhere.
     *
     * @param  list<string>  $assignees
     */
    private function seedNotes(Task $task, array $assignees): void
    {
        /** @var array<string, list<string>> $bodies */
        $bodies = [
            'general' => [
                'Scope confirmed with the secretariat; no change to the target date.',
                'Reporting line agreed: weekly to the delivery unit, monthly to cabinet.',
                'Two districts asked for a longer transition; noted, no change yet.',
            ],
            'action_taken' => [
                'Circular issued to all district offices with the revised checklist.',
                'Training run for 40 focal persons across the three provinces.',
                'Data migration rehearsed on a copy of the live registry.',
            ],
            'meeting' => [
                'Review with the ministry: three of five deliverables signed off, two carried over.',
                'Joint sitting with the treasury on the release schedule.',
                'Walkthrough with the vendor; open items logged against the contract.',
            ],
            'blocker' => [
                'Blocked on the budget release; nothing moves until the authority letter lands.',
                'Waiting on a legal opinion before the data can be shared across provinces.',
                'Contractor claim unresolved, so the handover cannot be scheduled.',
            ],
            'milestone' => [
                'First district went live and processed 240 files in the opening week.',
                'Halfway point reached ahead of schedule.',
                'Public portal opened; 1,100 sessions on day one.',
            ],
            'decision' => [
                'Decided to keep the paper register in parallel for one more quarter.',
                'Agreed to phase the rollout by province rather than cut over at once.',
                'Scope trimmed to a risk-ranked sample for this cycle.',
            ],
        ];

        $types = array_keys($bodies);
        $author = $this->authorFor($assignees);

        Auth::login($author);

        $count = $task->isComplete() ? 3 : 2;

        for ($i = 0; $i < $count; $i++) {
            $type = $types[((int) $task->item_number + $i) % count($types)];
            $pool = $bodies[$type];

            ProjectNote::query()->create([
                'task_id' => $task->getKey(),
                'user_id' => $author->getKey(),
                'type' => $type,
                // Rotate within the type so the same task note does not appear
                // verbatim on every task in the workspace.
                'body' => $pool[((int) $task->item_number + $i) % count($pool)],
                'happened_at' => CarbonImmutable::today()->subDays(fake()->numberBetween(1, 45)),
            ]);
        }
    }

    /**
     * Contacts on every third task — enough to exercise the panel without
     * putting an address book on every screen.
     */
    private function seedContacts(Task $task, int $itemNumber): void
    {
        if ($itemNumber % 3 !== 0) {
            return;
        }

        $author = $this->users['admin'];

        Auth::login($author);

        ProjectContact::query()->create([
            'task_id' => $task->getKey(),
            'user_id' => $author->getKey(),
            'name' => fake()->name(),
            'organization' => $task->responsible_ministry ?? 'District Administration Office',
            'role' => fake()->randomElement(['Under Secretary', 'Section Officer', 'Focal Person', 'Joint Secretary']),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+977 98'.fake()->numerify('########'),
            'notes' => 'Reachable on the ministry line before noon.',
        ]);
    }

    /**
     * Comments on every second task, the first of which mentions the lead
     * assignee so the mention notification path has real data behind it.
     *
     * @param  list<string>  $assignees
     */
    private function seedComments(Task $task, array $assignees, int $itemNumber): void
    {
        if ($itemNumber % 2 !== 0 || $assignees === []) {
            return;
        }

        // Rotate who gets mentioned rather than always picking the lead, so the
        // mention notifications do not all land on the same few people.
        $mentionedHandle = $assignees[$itemNumber % count($assignees)];
        $mentioned = $this->members[$mentionedHandle];
        $others = array_values(array_filter($assignees, fn (string $h): bool => $h !== $mentionedHandle));
        $author = $this->authorFor($others ?: $assignees);

        Auth::login($author);

        TaskComment::query()->create([
            'task_id' => $task->getKey(),
            'user_id' => $author->getKey(),
            'body' => '@'.$mentioned->name.' can you confirm the district numbers before the review?',
            'mentioned_member_ids' => [$mentioned->getKey()],
        ]);

        TaskComment::query()->create([
            'task_id' => $task->getKey(),
            'user_id' => $this->users['admin']->getKey(),
            'body' => 'Noted. Keep the weekly line in the digest until this closes.',
            'mentioned_member_ids' => null,
        ]);
    }

    /**
     * The first assignee who actually has a login, falling back to the admin —
     * unlinked members cannot author anything, since notes and comments are
     * keyed to a user.
     *
     * @param  list<string>  $assignees
     */
    private function authorFor(array $assignees): User
    {
        foreach ($assignees as $handle) {
            if (isset($this->users[$handle])) {
                return $this->users[$handle];
            }
        }

        return $this->users['admin'];
    }

    /**
     * The observers only emit TaskAssigned while the tasks are being built —
     * a status notification needs a real transition, a mention needs the comment
     * service, and a deadline reminder needs the scheduled command. Emit the
     * other three kinds directly so the inbox has every kind in it, then spread
     * them over the past week so the day grouping has something to group.
     */
    private function seedNotifications(): void
    {
        $today = CarbonImmutable::today();

        $mentions = TaskComment::query()
            ->whereNotNull('mentioned_member_ids')
            ->with(['task.project', 'user'])
            ->get();

        foreach ($this->users as $handle => $user) {
            $memberId = $this->members[$handle]->getKey();

            $tasks = Task::query()
                ->forActiveProjects()
                ->whereHas('assignments', fn (Builder $q) => $q->where('member_id', $memberId))
                ->with('project')
                ->get();

            $tasks->filter(fn (Task $task): bool => $task->isComplete())
                ->take(2)
                ->each(fn (Task $task) => $user->notify(
                    new TaskStatusChanged($task, (string) $task->status_label, 'Workspace Admin'),
                ));

            $tasks->filter(fn (Task $task): bool => ! $task->isComplete()
                && $task->deadline_at !== null
                && $task->deadline_at->lte($today))
                ->take(3)
                ->each(fn (Task $task) => $user->notify(new TaskDeadlineDue(
                    $task,
                    $task->deadline_at?->lt($today) ? 'overdue' : 'due_today',
                )));

            $mentions
                ->filter(fn (TaskComment $comment): bool => in_array(
                    $memberId,
                    (array) ($comment->mentioned_member_ids ?? []),
                    true,
                ))
                ->take(3)
                ->each(fn (TaskComment $comment) => $user->notify(new MentionedInComment(
                    $comment->task,
                    $comment->user->name ?? 'Someone',
                    Str::limit($comment->body, 60),
                )));

            // Everything above lands at "now", which would collapse the inbox
            // into a single Today group. Walk them back ~7 hours apart instead.
            $user->notifications()->get()->values()->each(
                fn (object $notification, int $index) => $notification
                    ->forceFill(['created_at' => CarbonImmutable::now()->subHours($index * 7)])
                    ->saveQuietly(),
            );
        }
    }

    /**
     * Stickies for every login, covering all five paper colours.
     */
    private function seedStickies(): void
    {
        /** @var list<array{title: ?string, body: string, color: string}> $notes */
        $notes = [
            ['title' => 'Today', 'body' => "Chase the gateway sign-off\nConfirm the district pilot dates", 'color' => 'amber'],
            ['title' => null, 'body' => 'Digest goes out Sunday evening. Check the failed items read clearly.', 'color' => 'sky'],
            ['title' => 'Blocked', 'body' => 'Budget authority letter still not issued.', 'color' => 'rose'],
            ['title' => 'Wins', 'body' => 'Tracker shipped on day seven.', 'color' => 'emerald'],
            ['title' => 'Parking lot', 'body' => 'Revisit the paper registry question next quarter.', 'color' => 'violet'],
        ];

        foreach ($this->users as $index => $user) {
            WorkspaceNote::query()->where('user_id', $user->getKey())->forceDelete();

            foreach (array_slice($notes, 0, 2 + ($index === 'admin' ? 3 : 1)) as $position => $note) {
                WorkspaceNote::query()->create([
                    'user_id' => $user->getKey(),
                    'title' => $note['title'],
                    'body' => $note['body'],
                    'color' => $note['color'],
                    'position_x' => 40 + ($position % 3) * 260,
                    'position_y' => 40 + intdiv($position, 3) * 220,
                ]);
            }
        }
    }

    /**
     * Three digest recipients: confirmed, pending confirmation, unsubscribed.
     */
    private function seedDigestSubscribers(): void
    {
        /** @var list<array{email: string, categories: ?list<string>, confirmed: bool, unsubscribed: bool}> $subscribers */
        $subscribers = [
            ['email' => 'press@pmopm.test', 'categories' => null, 'confirmed' => true, 'unsubscribed' => false],
            ['email' => 'reform-watch@pmopm.test', 'categories' => ['admin_reform', 'anti_corruption'], 'confirmed' => false, 'unsubscribed' => false],
            ['email' => 'former-subscriber@pmopm.test', 'categories' => null, 'confirmed' => true, 'unsubscribed' => true],
        ];

        foreach ($subscribers as $spec) {
            ProjectDigestSubscriber::query()->updateOrCreate(
                ['email' => $spec['email']],
                [
                    'categories' => $spec['categories'],
                    'frequency' => 'weekly',
                    'confirmation_token' => Str::random(40),
                    'confirmed_at' => $spec['confirmed'] ? CarbonImmutable::now()->subDays(20) : null,
                    'unsubscribe_token' => Str::random(40),
                    'unsubscribed_at' => $spec['unsubscribed'] ? CarbonImmutable::now()->subDays(3) : null,
                    'last_sent_at' => $spec['confirmed'] ? CarbonImmutable::now()->subDays(8) : null,
                ],
            );
        }
    }

    private function summarise(): void
    {
        $statuses = Task::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $this->command->info(sprintf(
            'Demo workspace: %d users, %d members, %d teams, %d projects, %d tasks.',
            User::query()->count(),
            Member::query()->count(),
            Team::query()->count(),
            Project::query()->count(),
            Task::query()->count(),
        ));

        foreach ((array) config('project-management.statuses') as $key => $meta) {
            $this->command->line(sprintf('  %-14s %d', $key, (int) ($statuses[$key] ?? 0)));
        }

        $this->command->line('  every login uses the password "'.self::PASSWORD.'"');
    }
}
