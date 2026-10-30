<?php

namespace App\Providers;

use App\Models\GitBranch;
use App\Models\GitCommit;
use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Models\GitRepositoryGrant;
use App\Models\Member;
use App\Models\OAuthClient;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectContact;
use App\Models\ProjectNote;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskDependency;
use App\Models\TaskGitLink;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Observers\GitPullRequestObserver;
use App\Observers\GitRepositoryObserver;
use App\Observers\ProgramObserver;
use App\Observers\ProjectAssignmentObserver;
use App\Observers\ProjectContactObserver;
use App\Observers\ProjectNoteObserver;
use App\Observers\ProjectObserver;
use App\Observers\SubtaskObserver;
use App\Observers\TaskCommentObserver;
use App\Observers\TaskDependencyObserver;
use App\Observers\TaskGitLinkObserver;
use App\Observers\TaskObserver;
use App\Observers\TeamMembershipObserver;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The OIDC routes are registered by hand in routes/web.php (behind
        // EnsureCanUseGit); none of Passport's own. Must run before Passport
        // boots.
        Passport::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureWorkspace();
        $this->configureOidcProvider();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Wire the workspace domain: the super-admin gate, the polymorphic morph
     * map, and the observers that write the activity log + notifications.
     */
    protected function configureWorkspace(): void
    {
        // Super-admins hold `manage-workspace`: they manage organizations and
        // everything inside them. Driven by PM_SUPER_ADMINS. Office admins and
        // team leaders are data, resolved in WorkspaceAccess.
        Gate::define('manage-workspace', function (User $user): bool {
            return in_array(
                $user->email,
                (array) config('project-management.super_admins', []),
                true,
            );
        });

        // enforceMorphMap makes the map STRICT app-wide: any model used as a
        // morph target must have an entry. User is itself a morph target
        // (Sanctum's personal_access_tokens.tokenable), so it must be mapped or
        // `createToken()` throws ClassMorphViolationException.
        Relation::enforceMorphMap([
            'user' => User::class,
            'project' => Project::class,
            'task' => Task::class,
            'task-comment' => TaskComment::class,
            'task-dependency' => TaskDependency::class,
            'project-note' => ProjectNote::class,
            'project-contact' => ProjectContact::class,
            'subtask' => Subtask::class,
            'project-assignment' => ProjectAssignment::class,
            'team' => Team::class,
            'member' => Member::class,
            'git-repository' => GitRepository::class,
            'git-branch' => GitBranch::class,
            'git-commit' => GitCommit::class,
            'git-pull-request' => GitPullRequest::class,
            'git-repository-grant' => GitRepositoryGrant::class,
        ]);

        // Observers own every side effect of a workspace mutation: the
        // ProjectActivity audit log and the in-app notifications.
        // WorkspaceAccess memoizes per user instance; keep that per request even
        // when one instance serves several (tests, long-running workers).
        Event::listen(RouteMatched::class, fn () => WorkspaceAccess::flush());
        Event::listen(RequestHandled::class, fn () => WorkspaceAccess::flush());

        Program::observe(ProgramObserver::class);
        Project::observe(ProjectObserver::class);
        Task::observe(TaskObserver::class);
        ProjectNote::observe(ProjectNoteObserver::class);
        ProjectContact::observe(ProjectContactObserver::class);
        Subtask::observe(SubtaskObserver::class);
        ProjectAssignment::observe(ProjectAssignmentObserver::class);
        TaskComment::observe(TaskCommentObserver::class);
        TaskDependency::observe(TaskDependencyObserver::class);
        TeamMembership::observe(TeamMembershipObserver::class);
        GitRepository::observe(GitRepositoryObserver::class);
        TaskGitLink::observe(TaskGitLinkObserver::class);
        GitPullRequest::observe(GitPullRequestObserver::class);
    }

    /**
     * The tracker as the forge's OpenID Connect provider (Passport plus
     * jeremy379/laravel-openid-connect, whose provider replaces Passport's;
     * see config/openid.php). The forge is the one client and skips consent,
     * so the consent view is only reached by asking for it (prompt=consent),
     * and is refused. Its tokens only carry the sign-in, so they are short
     * lived; `passport:purge` clears the spent ones daily.
     */
    protected function configureOidcProvider(): void
    {
        Passport::useClientModel(OAuthClient::class);
        Passport::tokensExpireIn(CarbonInterval::hours(1));
        Passport::refreshTokensExpireIn(CarbonInterval::days(1));

        /** @var array<string, string> $scopes */
        $scopes = config('openid.passport.tokens_can', []);
        Passport::tokensCan($scopes);

        Passport::authorizationView(fn (): Response => abort(403));
    }
}
