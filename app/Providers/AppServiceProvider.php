<?php

namespace App\Providers;

use App\Models\Member;
use App\Models\ProjectAssignment;
use App\Models\ProjectContact;
use App\Models\ProjectNote;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\User;
use App\Observers\ProjectAssignmentObserver;
use App\Observers\ProjectContactObserver;
use App\Observers\ProjectNoteObserver;
use App\Observers\SubtaskObserver;
use App\Observers\TaskCommentObserver;
use App\Observers\TaskObserver;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureWorkspace();
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
            'task' => Task::class,
            'task-comment' => TaskComment::class,
            'project-note' => ProjectNote::class,
            'project-contact' => ProjectContact::class,
            'subtask' => Subtask::class,
            'project-assignment' => ProjectAssignment::class,
            'team' => Team::class,
            'member' => Member::class,
        ]);

        // Observers own every side effect of a workspace mutation: the
        // ProjectActivity audit log and the in-app notifications.
        // WorkspaceAccess memoizes per user instance; keep that per request even
        // when one instance serves several (tests, long-running workers).
        Event::listen(RouteMatched::class, fn () => WorkspaceAccess::flush());
        Event::listen(RequestHandled::class, fn () => WorkspaceAccess::flush());

        Task::observe(TaskObserver::class);
        ProjectNote::observe(ProjectNoteObserver::class);
        ProjectContact::observe(ProjectContactObserver::class);
        Subtask::observe(SubtaskObserver::class);
        ProjectAssignment::observe(ProjectAssignmentObserver::class);
        TaskComment::observe(TaskCommentObserver::class);
    }
}
