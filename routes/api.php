<?php

use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CodeActivityController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DesignationController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MyWorkspaceController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrganizationGitController;
use App\Http\Controllers\Api\OrganogramController;
use App\Http\Controllers\Api\PlanTrackerController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\ProgramProgressController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectProgressController;
use App\Http\Controllers\Api\ProjectRepositoryController;
use App\Http\Controllers\Api\QuickAddController;
use App\Http\Controllers\Api\ReportsToController;
use App\Http\Controllers\Api\RepositoryGrantController;
use App\Http\Controllers\Api\SubtaskController;
use App\Http\Controllers\Api\TaskCommentController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskDependencyController;
use App\Http\Controllers\Api\TaskPreviewController;
use App\Http\Controllers\Api\TaskReorderController;
use App\Http\Controllers\Api\TaskSearchController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TeamMemberController;
use App\Http\Controllers\Api\ThemeController;
use App\Http\Controllers\Api\WorkspaceNoteController;
use App\Http\Middleware\EnsureCanSignIn;
use App\Http\Middleware\EnsureProjectVisible;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.')->group(function () {
    // Unauthenticated: exchange credentials for a personal access token.
    Route::post('/login', [AuthController::class, 'login'])->name('login');

    Route::middleware(['auth:sanctum', EnsureCanSignIn::class])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/user', [AuthController::class, 'user'])->name('user');

        // Appearance: the theme catalogue + the caller's saved preferences.
        Route::get('/themes', [ThemeController::class, 'index'])->name('themes');
        Route::get('/user/preferences', [PreferenceController::class, 'show'])->name('preferences.show');
        Route::patch('/user/preferences', [PreferenceController::class, 'update'])->name('preferences.update');

        // Notification inbox (in-app) — top-level under /api/v1, not the workspace prefix.
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

        Route::prefix('workspace')->name('workspace.')->group(function () {
            // Feeds & overview
            Route::get('/home', HomeController::class)->name('home');
            Route::get('/dashboard', DashboardController::class)->name('dashboard');
            Route::get('/my', MyWorkspaceController::class)->name('my');
            Route::get('/plan-tracker', PlanTrackerController::class)->name('plan-tracker');
            Route::get('/search', TaskSearchController::class)->name('search');
            Route::post('/quick-add', QuickAddController::class)->name('quick-add');

            // Organizations (super-admin)
            Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
            Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
            Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
            Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy'])->name('organizations.destroy');
            Route::post('/organizations/{organization}/git', [OrganizationGitController::class, 'store'])->name('organizations.git.store');
            Route::delete('/organizations/{organization}/git', [OrganizationGitController::class, 'destroy'])->name('organizations.git.destroy');

            // Programs (office admin of the organization, or super-admin)
            Route::get('/programs', [ProgramController::class, 'index'])->name('programs.index');
            Route::get('/programs/{program:slug}/progress', ProgramProgressController::class)->name('programs.progress');
            Route::post('/programs', [ProgramController::class, 'store'])->name('programs.store');
            Route::patch('/programs/{program:slug}', [ProgramController::class, 'update'])->name('programs.update');
            Route::delete('/programs/{program:slug}', [ProgramController::class, 'destroy'])->name('programs.destroy');

            // Teams
            Route::get('/team', [TeamController::class, 'index'])->name('team');
            Route::get('/teams/{team}/candidates', [TeamMemberController::class, 'candidates'])->name('teams.candidates');
            Route::post('/teams/{team}/members', [TeamMemberController::class, 'store'])->name('teams.members.store');
            Route::delete('/teams/{team}/members/{member}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');
            Route::patch('/teams/{team}/members/{member}', [TeamMemberController::class, 'updateRole'])->name('teams.members.role');

            // Members
            Route::post('/members', [MemberController::class, 'store'])->name('members.store');
            Route::patch('/members/{member}', [MemberController::class, 'update'])->name('members.update');
            Route::delete('/members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');
            Route::post('/members/{member}/restore', [MemberController::class, 'restore'])->name('members.restore')->withTrashed();

            // Projects
            Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::get('/projects/{project:slug}', [ProjectController::class, 'show'])->name('projects.show');
            Route::get('/projects/{project:slug}/progress', ProjectProgressController::class)->name('projects.progress');
            Route::patch('/projects/{project:slug}', [ProjectController::class, 'update'])->name('projects.update');
            Route::patch('/projects/{project:slug}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
            Route::patch('/projects/{project:slug}/restore', [ProjectController::class, 'restore'])->name('projects.restore');

            // Linked forge repositories
            Route::get('/projects/{project:slug}/repositories', [ProjectRepositoryController::class, 'index'])->name('projects.repositories.index');
            Route::get('/projects/{project:slug}/repositories/candidates', [ProjectRepositoryController::class, 'candidates'])->name('projects.repositories.candidates');
            Route::post('/projects/{project:slug}/repositories', [ProjectRepositoryController::class, 'store'])->name('projects.repositories.store');
            Route::post('/projects/{project:slug}/repositories/new', [ProjectRepositoryController::class, 'create'])->name('projects.repositories.create');
            Route::delete('/projects/{project:slug}/repositories/{repository}', [ProjectRepositoryController::class, 'destroy'])->scopeBindings()->name('projects.repositories.destroy');

            // Who may pull and push a linked repository (its maintainers and grants).
            Route::scopeBindings()->group(function () {
                Route::get('/projects/{project:slug}/repositories/{repository}/access', [RepositoryGrantController::class, 'index'])->name('projects.repositories.access.index');
                Route::get('/projects/{project:slug}/repositories/{repository}/access/candidates', [RepositoryGrantController::class, 'candidates'])->name('projects.repositories.access.candidates');
                Route::post('/projects/{project:slug}/repositories/{repository}/access', [RepositoryGrantController::class, 'store'])->name('projects.repositories.access.store');
                Route::patch('/projects/{project:slug}/repositories/{repository}/access/{grant}', [RepositoryGrantController::class, 'update'])->name('projects.repositories.access.update');
                Route::delete('/projects/{project:slug}/repositories/{repository}/access/{grant}', [RepositoryGrantController::class, 'destroy'])->name('projects.repositories.access.destroy');
            });
            // Code activity
            Route::get('/reports/code', [CodeActivityController::class, 'index'])->name('reports.code');
            Route::get('/reports/code/people/{user}', [CodeActivityController::class, 'person'])->whereNumber('user')->name('reports.code.person');

            // Structure: designations, reporting lines, the organogram.
            Route::get('/organizations/{organization}/organogram', [OrganogramController::class, 'show'])->name('organogram');
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

            // Tasks + task-children: gated centrally by EnsureProjectVisible
            // (binding has resolved the parameter to a model instance, so the
            // middleware walks it back to its project and 403s non-viewers).
            Route::middleware(EnsureProjectVisible::class)->group(function () {
                // Tasks
                Route::post('/projects/{project:slug}/tasks/reorder', TaskReorderController::class)->name('tasks.reorder');
                Route::get('/tasks/{task}/preview', TaskPreviewController::class)->name('tasks.preview');
                Route::post('/projects/{project:slug}/tasks', [TaskController::class, 'store'])->scopeBindings()->name('tasks.store');
                Route::get('/projects/{project:slug}/tasks/{task:slug}', [TaskController::class, 'show'])->scopeBindings()->name('tasks.show');
                Route::patch('/projects/{project:slug}/tasks/{task:slug}', [TaskController::class, 'update'])->scopeBindings()->name('tasks.update');
                Route::delete('/projects/{project:slug}/tasks/{task:slug}', [TaskController::class, 'destroy'])->scopeBindings()->name('tasks.destroy');
                Route::post('/projects/{project:slug}/tasks/{task:slug}/restore', [TaskController::class, 'restore'])->scopeBindings()->name('tasks.restore')->withTrashed();

                // Assignments
                Route::post('/tasks/{task}/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
                Route::patch('/assignments/{assignment}', [AssignmentController::class, 'update'])->name('assignments.update');
                Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy'])->name('assignments.destroy');
                Route::post('/assignments/{assignment}/restore', [AssignmentController::class, 'restore'])->name('assignments.restore')->withTrashed();

                // Subtasks
                Route::post('/tasks/{task}/subtasks', [SubtaskController::class, 'store'])->name('subtasks.store');
                Route::patch('/subtasks/{subtask}', [SubtaskController::class, 'update'])->name('subtasks.update');
                Route::delete('/subtasks/{subtask}', [SubtaskController::class, 'destroy'])->name('subtasks.destroy');
                Route::post('/subtasks/{subtask}/restore', [SubtaskController::class, 'restore'])->name('subtasks.restore')->withTrashed();

                // Comments
                Route::get('/tasks/{task}/comments', [TaskCommentController::class, 'index'])->name('tasks.comments.index');
                Route::post('/tasks/{task}/comments', [TaskCommentController::class, 'store'])->name('tasks.comments.store');
                Route::post('/tasks/{task}/dependencies', [TaskDependencyController::class, 'store'])->name('tasks.dependencies.store');
                Route::delete('/tasks/{task}/dependencies/{dependsOn}', [TaskDependencyController::class, 'destroy'])->name('tasks.dependencies.destroy');
                Route::patch('/comments/{comment}', [TaskCommentController::class, 'update'])->name('comments.update');
                Route::delete('/comments/{comment}', [TaskCommentController::class, 'destroy'])->name('comments.destroy');
                Route::post('/comments/{comment}/restore', [TaskCommentController::class, 'restore'])->name('comments.restore')->withTrashed();

                // Notes & contacts
                Route::post('/tasks/{task}/notes', [NoteController::class, 'store'])->name('notes.store');
                Route::delete('/notes/{note}', [NoteController::class, 'destroy'])->name('notes.destroy');
                Route::post('/notes/{note}/restore', [NoteController::class, 'restore'])->name('notes.restore')->withTrashed();
                Route::post('/tasks/{task}/contacts', [ContactController::class, 'store'])->name('contacts.store');
            });

            // Personal workspace notes
            Route::get('/my-notes', [WorkspaceNoteController::class, 'index'])->name('my-notes.index');
            Route::post('/my-notes', [WorkspaceNoteController::class, 'store'])->name('my-notes.store');
            Route::patch('/my-notes/{workspaceNote}', [WorkspaceNoteController::class, 'update'])->name('my-notes.update');
            Route::patch('/my-notes/{workspaceNote}/placement', [WorkspaceNoteController::class, 'placement'])->name('my-notes.placement');
            Route::delete('/my-notes/{workspaceNote}', [WorkspaceNoteController::class, 'destroy'])->name('my-notes.destroy');
            Route::post('/my-notes/{workspaceNote}/restore', [WorkspaceNoteController::class, 'restore'])->name('my-notes.restore')->withTrashed();
        });
    });
});
