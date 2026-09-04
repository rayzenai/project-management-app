<?php

use App\Http\Controllers\Workspace\AssignmentController;
use App\Http\Controllers\Workspace\AuthController;
use App\Http\Controllers\Workspace\ContactController;
use App\Http\Controllers\Workspace\HomeController;
use App\Http\Controllers\Workspace\MemberController;
use App\Http\Controllers\Workspace\MyWorkspaceController;
use App\Http\Controllers\Workspace\NoteController;
use App\Http\Controllers\Workspace\NotificationController;
use App\Http\Controllers\Workspace\PlanTrackerController;
use App\Http\Controllers\Workspace\PreferenceController;
use App\Http\Controllers\Workspace\ProjectController;
use App\Http\Controllers\Workspace\QuickAddController;
use App\Http\Controllers\Workspace\SubtaskController;
use App\Http\Controllers\Workspace\TaskCommentController;
use App\Http\Controllers\Workspace\TaskController;
use App\Http\Controllers\Workspace\TaskPreviewController;
use App\Http\Controllers\Workspace\TaskReorderController;
use App\Http\Controllers\Workspace\TaskSearchController;
use App\Http\Controllers\Workspace\TeamController;
use App\Http\Controllers\Workspace\TeamMemberController;
use App\Http\Controllers\Workspace\WorkspaceNoteController;
use App\Http\Middleware\EnsureProjectVisible;
use App\Http\Middleware\ShareWorkspaceData;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/workspace')->name('home');

Route::middleware('guest')
    ->prefix('workspace')
    ->name('workspace.')
    ->group(function () {
        Route::get('/login', [AuthController::class, 'create'])->name('login');
        Route::post('/login', [AuthController::class, 'store'])->name('login.store');
    });

Route::middleware(['auth', ShareWorkspaceData::class])
    ->prefix('workspace')
    ->name('workspace.')
    ->group(function () {
        Route::get('/', HomeController::class)->name('home');

        Route::get('/my', MyWorkspaceController::class)->name('my');
        Route::post('/quick-add', QuickAddController::class)->name('quick-add');
        Route::get('/search', TaskSearchController::class)->name('search');

        Route::get('/team', [TeamController::class, 'index'])->name('team');
        Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
        Route::patch('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::post('/teams/{team}/restore', [TeamController::class, 'restore'])->name('teams.restore')->withTrashed();
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
        Route::patch('/projects/{project:slug}', [ProjectController::class, 'update'])->name('projects.update');
        Route::patch('/projects/{project:slug}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
        Route::patch('/projects/{project:slug}/restore', [ProjectController::class, 'restore'])->name('projects.restore');

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
