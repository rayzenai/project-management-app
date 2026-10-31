<?php

use App\Http\Controllers\GitWebhookController;
use App\Http\Controllers\Workspace\AssignmentController;
use App\Http\Controllers\Workspace\AuthController;
use App\Http\Controllers\Workspace\CodeActivityController;
use App\Http\Controllers\Workspace\ContactController;
use App\Http\Controllers\Workspace\DesignationController;
use App\Http\Controllers\Workspace\GoToTaskController;
use App\Http\Controllers\Workspace\HomeController;
use App\Http\Controllers\Workspace\MemberController;
use App\Http\Controllers\Workspace\MyWorkspaceController;
use App\Http\Controllers\Workspace\NoteController;
use App\Http\Controllers\Workspace\NotificationController;
use App\Http\Controllers\Workspace\OrganizationController;
use App\Http\Controllers\Workspace\OrganizationGitController;
use App\Http\Controllers\Workspace\OrganogramController;
use App\Http\Controllers\Workspace\PlanTrackerController;
use App\Http\Controllers\Workspace\PreferenceController;
use App\Http\Controllers\Workspace\ProgramController;
use App\Http\Controllers\Workspace\ProjectController;
use App\Http\Controllers\Workspace\ProjectProgressController;
use App\Http\Controllers\Workspace\ProjectRepositoryController;
use App\Http\Controllers\Workspace\QuickAddController;
use App\Http\Controllers\Workspace\ReportsToController;
use App\Http\Controllers\Workspace\RepositoryGrantController;
use App\Http\Controllers\Workspace\SubtaskController;
use App\Http\Controllers\Workspace\TaskCommentController;
use App\Http\Controllers\Workspace\TaskController;
use App\Http\Controllers\Workspace\TaskDependencyController;
use App\Http\Controllers\Workspace\TaskPreviewController;
use App\Http\Controllers\Workspace\TaskReorderController;
use App\Http\Controllers\Workspace\TaskSearchController;
use App\Http\Controllers\Workspace\TeamController;
use App\Http\Controllers\Workspace\TeamMemberController;
use App\Http\Controllers\Workspace\TeamProgressController;
use App\Http\Controllers\Workspace\WorkspaceNoteController;
use App\Http\Middleware\EnsureCanSignIn;
use App\Http\Middleware\EnsureCanUseGit;
use App\Http\Middleware\EnsureProjectVisible;
use App\Http\Middleware\ShareWorkspaceData;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\AuthorizationController;

Route::redirect('/', '/workspace')->name('home');

// The forge's OpenID Connect sign-in. Passport's own routes are off; the
// openid package adds discovery (/.well-known/openid-configuration) and the
// signing key (/oauth/jwks). Only people GitAccess lets in get a code. The
// forge calls the token endpoint server to server: no session, no CSRF.
Route::get('/oauth/authorize', [AuthorizationController::class, 'authorize'])
    ->middleware(['auth', EnsureCanSignIn::class, EnsureCanUseGit::class])
    ->name('passport.authorizations.authorize');
Route::post('/oauth/token', [AccessTokenController::class, 'issueToken'])
    ->withoutMiddleware('web')
    ->middleware('throttle:60,1')
    ->name('passport.token');

// The forge's organization webhook: server to server, signed, no session or
// CSRF. The controller looks the connection up itself (no route binding).
// Its own throttle counter (the prefix): the forge's token calls come from
// the same address, and a busy push day must not lock out forge sign-in.
Route::post('/git/webhooks/{connection}', GitWebhookController::class)
    ->whereNumber('connection')
    ->withoutMiddleware('web')
    ->middleware('throttle:600,1,git-webhook')
    ->name('git.webhook');

Route::middleware('guest')
    ->prefix('workspace')
    ->name('workspace.')
    ->group(function () {
        Route::get('/login', [AuthController::class, 'create'])->name('login');
        Route::post('/login', [AuthController::class, 'store'])->name('login.store');
    });

Route::middleware(['auth', EnsureCanSignIn::class, ShareWorkspaceData::class])
    ->prefix('workspace')
    ->name('workspace.')
    ->group(function () {
        Route::get('/', HomeController::class)->name('home');

        Route::get('/my', MyWorkspaceController::class)->name('my');
        Route::post('/quick-add', QuickAddController::class)->name('quick-add');
        Route::get('/search', TaskSearchController::class)->name('search');

        Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
        Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy'])->name('organizations.destroy');
        Route::get('/programs/{program:slug}', [ProgramController::class, 'show'])->name('programs.show');
        Route::post('/programs', [ProgramController::class, 'store'])->name('programs.store');
        Route::patch('/programs/{program:slug}', [ProgramController::class, 'update'])->name('programs.update');
        Route::delete('/programs/{program:slug}', [ProgramController::class, 'destroy'])->name('programs.destroy');
        Route::post('/organizations/{organization}/git', [OrganizationGitController::class, 'store'])->name('organizations.git.store');
        Route::delete('/organizations/{organization}/git', [OrganizationGitController::class, 'destroy'])->name('organizations.git.destroy');

        Route::get('/team', [TeamController::class, 'index'])->name('team');
        Route::inertia('/guide', 'Guide')->name('guide');
        Route::get('/teams/{team}/progress', TeamProgressController::class)->name('teams.progress');
        Route::get('/teams/{team}/candidates', [TeamMemberController::class, 'candidates'])->name('teams.candidates');
        Route::post('/teams/{team}/members', [TeamMemberController::class, 'store'])->name('teams.members.store');
        Route::delete('/teams/{team}/members/{member}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');
        Route::patch('/teams/{team}/members/{member}', [TeamMemberController::class, 'updateRole'])->name('teams.members.role');

        Route::post('/members', [MemberController::class, 'store'])->name('members.store');
        Route::patch('/members/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::delete('/members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');
        Route::post('/members/{member}/restore', [MemberController::class, 'restore'])->name('members.restore')->withTrashed();

        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('/projects/{project:slug}', [ProjectController::class, 'show'])->name('projects.show');
        Route::get('/projects/{project:slug}/progress', ProjectProgressController::class)->name('projects.progress');
        Route::patch('/projects/{project:slug}', [ProjectController::class, 'update'])->name('projects.update');
        Route::patch('/projects/{project:slug}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
        Route::patch('/projects/{project:slug}/restore', [ProjectController::class, 'restore'])->name('projects.restore');

        // Linked forge repositories (the Code tab) and the forge's link back
        // to a task (TP-12 on the forge opens /workspace/go/{forge org}/TP-12).
        Route::get('/projects/{project:slug}/repositories/candidates', [ProjectRepositoryController::class, 'candidates'])->name('projects.repositories.candidates');
        Route::post('/projects/{project:slug}/repositories', [ProjectRepositoryController::class, 'store'])->name('projects.repositories.store');
        Route::post('/projects/{project:slug}/repositories/new', [ProjectRepositoryController::class, 'create'])->name('projects.repositories.create');
        Route::delete('/projects/{project:slug}/repositories/{repository}', [ProjectRepositoryController::class, 'destroy'])
            ->scopeBindings()
            ->name('projects.repositories.destroy');
        Route::get('/go/{org}/{index}', GoToTaskController::class)->name('go');

        // Who may pull and push a linked repository (its maintainers and grants).
        Route::scopeBindings()->group(function () {
            Route::get('/projects/{project:slug}/repositories/{repository}/access', [RepositoryGrantController::class, 'index'])->name('projects.repositories.access.index');
            Route::get('/projects/{project:slug}/repositories/{repository}/access/candidates', [RepositoryGrantController::class, 'candidates'])->name('projects.repositories.access.candidates');
            Route::post('/projects/{project:slug}/repositories/{repository}/access', [RepositoryGrantController::class, 'store'])->name('projects.repositories.access.store');
            Route::patch('/projects/{project:slug}/repositories/{repository}/access/{grant}', [RepositoryGrantController::class, 'update'])->name('projects.repositories.access.update');
            Route::delete('/projects/{project:slug}/repositories/{repository}/access/{grant}', [RepositoryGrantController::class, 'destroy'])->name('projects.repositories.access.destroy');
        });
        // Code activity: who committed what, within what each viewer runs.
        Route::get('/reports/code', [CodeActivityController::class, 'index'])->name('reports.code');
        Route::get('/reports/code/people/{user}', [CodeActivityController::class, 'person'])->whereNumber('user')->name('reports.code.person');

        // Structure: designations, reporting lines, the organogram.
        Route::get('/organogram', [OrganogramController::class, 'index'])->name('organogram');
        Route::post('/organizations/{organization}/designations', [DesignationController::class, 'store'])->name('designations.store');
        Route::patch('/designations/{designation}', [DesignationController::class, 'update'])->name('designations.update');
        Route::delete('/designations/{designation}', [DesignationController::class, 'destroy'])->name('designations.destroy');
        Route::put('/members/{member}/reports-to', [ReportsToController::class, 'update'])->name('members.reports-to');
        // (structure routes end)

        // Attendance: the clock, the register, leave and kaaj, settings.
        // (attendance: clock and settings routes end)

        // (attendance: register, leave and kaaj routes end)

        // Work reports: today's work, a person's work, a team's work.
        // (work routes end)

        // Every route that acts on a task or a task-child is gated centrally by
        // EnsureProjectVisible: route-model binding has already resolved the
        // bound parameter to a model instance, so the middleware walks it back
        // to its parent project and 403s anyone who cannot view that project.
        Route::middleware(EnsureProjectVisible::class)->group(function () {
            Route::post('/projects/{project:slug}/tasks/reorder', TaskReorderController::class)
                ->name('tasks.reorder');

            Route::get('/tasks/{task}/preview', TaskPreviewController::class)
                ->name('tasks.preview');

            Route::post('/projects/{project:slug}/tasks', [TaskController::class, 'store'])
                ->scopeBindings()
                ->name('tasks.store');
            Route::get('/projects/{project:slug}/tasks/{task:slug}', [TaskController::class, 'show'])
                ->scopeBindings()
                ->name('tasks.show');
            Route::patch('/projects/{project:slug}/tasks/{task:slug}', [TaskController::class, 'update'])
                ->scopeBindings()
                ->name('tasks.update');
            Route::delete('/projects/{project:slug}/tasks/{task:slug}', [TaskController::class, 'destroy'])
                ->scopeBindings()
                ->name('tasks.destroy');
            Route::post('/projects/{project:slug}/tasks/{task:slug}/restore', [TaskController::class, 'restore'])
                ->scopeBindings()
                ->withTrashed()
                ->name('tasks.restore');

            Route::post('/tasks/{task}/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
            Route::patch('/assignments/{assignment}', [AssignmentController::class, 'update'])->name('assignments.update');
            Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy'])->name('assignments.destroy');
            Route::post('/assignments/{assignment}/restore', [AssignmentController::class, 'restore'])->name('assignments.restore')->withTrashed();

            Route::post('/tasks/{task}/notes', [NoteController::class, 'store'])->name('notes.store');
            Route::delete('/notes/{note}', [NoteController::class, 'destroy'])->name('notes.destroy');
            Route::post('/notes/{note}/restore', [NoteController::class, 'restore'])->name('notes.restore')->withTrashed();

            Route::post('/tasks/{task}/contacts', [ContactController::class, 'store'])->name('contacts.store');

            Route::get('/tasks/{task}/comments', [TaskCommentController::class, 'index'])->name('tasks.comments.index');
            Route::post('/tasks/{task}/comments', [TaskCommentController::class, 'store'])->name('tasks.comments.store');
            Route::post('/tasks/{task}/dependencies', [TaskDependencyController::class, 'store'])->name('tasks.dependencies.store');
            Route::delete('/tasks/{task}/dependencies/{dependsOn}', [TaskDependencyController::class, 'destroy'])->name('tasks.dependencies.destroy');
            Route::patch('/comments/{comment}', [TaskCommentController::class, 'update'])->name('comments.update');
            Route::delete('/comments/{comment}', [TaskCommentController::class, 'destroy'])->name('comments.destroy');
            Route::post('/comments/{comment}/restore', [TaskCommentController::class, 'restore'])->name('comments.restore')->withTrashed();

            Route::post('/tasks/{task}/subtasks', [SubtaskController::class, 'store'])->name('subtasks.store');
            Route::patch('/subtasks/{subtask}', [SubtaskController::class, 'update'])->name('subtasks.update');
            Route::delete('/subtasks/{subtask}', [SubtaskController::class, 'destroy'])->name('subtasks.destroy');
            Route::post('/subtasks/{subtask}/restore', [SubtaskController::class, 'restore'])->name('subtasks.restore')->withTrashed();
        });

        Route::post('/my-notes', [WorkspaceNoteController::class, 'store'])->name('my-notes.store');
        Route::patch('/my-notes/{workspaceNote}', [WorkspaceNoteController::class, 'update'])->name('my-notes.update');
        Route::patch('/my-notes/{workspaceNote}/placement', [WorkspaceNoteController::class, 'placement'])->name('my-notes.placement');
        Route::delete('/my-notes/{workspaceNote}', [WorkspaceNoteController::class, 'destroy'])->name('my-notes.destroy');
        Route::post('/my-notes/{workspaceNote}/restore', [WorkspaceNoteController::class, 'restore'])->name('my-notes.restore')->withTrashed();

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');

        Route::get('/100-point-tracker', PlanTrackerController::class)->name('plan-tracker');

        Route::patch('/preferences', [PreferenceController::class, 'update'])->name('preferences.update');

        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    });
