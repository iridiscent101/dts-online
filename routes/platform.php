<?php

declare(strict_types=1);

use App\Http\Controllers\DocumentAdditionalRouteController;
use App\Http\Controllers\DocumentAttachmentController;
use App\Http\Controllers\DocumentDeletionController;
use App\Http\Controllers\DocumentIntakeController;
use App\Http\Controllers\DocumentLabelController;
use App\Http\Controllers\DocumentLabelsController;
use App\Http\Controllers\DocumentMovementController;
use App\Http\Controllers\DocumentNotificationController;
use App\Http\Controllers\DocumentRemarkController;
use App\Http\Controllers\DocumentRouteCorrectionController;
use App\Http\Controllers\DocumentWorkspaceController;
use App\Http\Middleware\LogoutInactiveStaff;
use App\Orchid\Screens\DocumentEditScreen;
use App\Orchid\Screens\DocumentRegistrationScreen;
use App\Orchid\Screens\DocumentViewScreen;
use App\Orchid\Screens\Office\OfficeEditScreen;
use App\Orchid\Screens\Office\OfficeListScreen;
use App\Orchid\Screens\PlatformScreen;
use App\Orchid\Screens\Role\RoleEditScreen;
use App\Orchid\Screens\Role\RoleListScreen;
use App\Orchid\Screens\School\SchoolListScreen;
use App\Orchid\Screens\SystemHealthScreen;
use App\Orchid\Screens\User\UserEditScreen;
use App\Orchid\Screens\User\UserListScreen;
use App\Orchid\Screens\User\UserProfileScreen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tabuna\Breadcrumbs\Trail;

/*
|--------------------------------------------------------------------------
| Dashboard Routes
|--------------------------------------------------------------------------
|
| Register web routes for the application.
| Routes loaded by the RouteServiceProvider within a group which
| contains the need "dashboard" middleware group.
|
*/

// Main
Route::screen('/main', PlatformScreen::class)
    ->name('platform.main');

Route::screen('system/health', SystemHealthScreen::class)
    ->name('platform.systems.health')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('System Health'), route('platform.systems.health')));

Route::screen('documents/create', DocumentRegistrationScreen::class)
    ->name('platform.documents.create');
// Pages and file views; not rate limited.
Route::get('documents/labels', DocumentLabelsController::class)
    ->name('platform.documents.labels');
Route::screen('documents/{document}/edit', DocumentEditScreen::class)
    ->name('platform.documents.edit');
Route::get('documents/{document}/attachments/{attachment}/preview', [DocumentAttachmentController::class, 'preview'])
    ->whereNumber('attachment')
    ->name('platform.documents.attachments.preview');
Route::get('documents/{document}/attachments/{attachment}/content', [DocumentAttachmentController::class, 'content'])
    ->whereNumber('attachment')
    ->name('platform.documents.attachments.content');
Route::get('documents/{document}/attachments/{attachment}/download', [DocumentAttachmentController::class, 'download'])
    ->whereNumber('attachment')
    ->name('platform.documents.attachments.download');
Route::get('documents/{document}/label', DocumentLabelController::class)
    ->whereNumber('document')
    ->name('platform.documents.label');
Route::get('notifications/{notification}/open', DocumentNotificationController::class)
    ->name('platform.notifications.open');

// Inactivity sign-out (LogoutInactiveStaff): the page reports typing and clicks that send no request of their own, and
// signs out when its countdown ends.
Route::post('session/keep-alive', fn () => response()->noContent())
    ->name('platform.session.keep-alive');
Route::post('session/idle-sign-out', fn (Request $request) => LogoutInactiveStaff::signOut($request))
    ->name('platform.session.idle-sign-out');

// Background requests and actions (not page loads): at most 120 a minute per user.
Route::middleware('throttle:staff-requests')->group(function (): void {
    Route::get('documents/intake', DocumentIntakeController::class)
        ->name('platform.documents.intake');
    Route::get('documents/{document}/workspace', DocumentWorkspaceController::class)
        ->name('platform.documents.workspace');
    Route::post('documents/{document}/attachments', [DocumentAttachmentController::class, 'store'])
        ->name('platform.documents.attachments.store');
    Route::post('documents/{document}/attachments/{attachment}/replace', [DocumentAttachmentController::class, 'replace'])
        ->whereNumber('attachment')
        ->name('platform.documents.attachments.replace');
    Route::post('documents/{document}/attachments/{attachment}/delete', [DocumentAttachmentController::class, 'destroy'])
        ->whereNumber('attachment')
        ->name('platform.documents.attachments.destroy');
    Route::post('documents/{document}/movements', DocumentMovementController::class)
        ->name('platform.documents.movements.store');
    Route::post('documents/{document}/remarks', DocumentRemarkController::class)
        ->name('platform.documents.remarks.store');
    Route::post('documents/{document}/additional-routes', DocumentAdditionalRouteController::class)
        ->name('platform.documents.additional-routes.store');
    Route::post('documents/{document}/routes/correct', DocumentRouteCorrectionController::class)
        ->name('platform.documents.routes.correct');
    Route::post('documents/bulk-delete', [DocumentDeletionController::class, 'bulk'])
        ->name('platform.documents.bulk-destroy');
    Route::post('documents/{document}/delete', DocumentDeletionController::class)
        ->whereNumber('document')
        ->name('platform.documents.destroy');
    Route::post('notifications/actions/read-all', [DocumentNotificationController::class, 'markAllRead'])
        ->name('platform.notifications.read-all');
});
Route::screen('documents/{document}', DocumentViewScreen::class)
    ->name('platform.documents.view');

foreach (['documents', 'incoming', 'outgoing', 'archived'] as $section) {
    Route::screen($section, PlatformScreen::class)
        ->defaults('section', $section)
        ->name('platform.'.$section);
}

// Platform > Profile
Route::screen('profile', UserProfileScreen::class)
    ->name('platform.profile')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Profile'), route('platform.profile')));

// Platform > System > Users > User
Route::screen('users/{user}/edit', UserEditScreen::class)
    ->name('platform.systems.users.edit')
    ->breadcrumbs(fn (Trail $trail, $user) => $trail
        ->parent('platform.systems.users')
        ->push($user->name, route('platform.systems.users.edit', $user)));

// Platform > System > Users > Create
Route::screen('users/create', UserEditScreen::class)
    ->name('platform.systems.users.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.systems.users')
        ->push(__('Create'), route('platform.systems.users.create')));

// Platform > System > Users
Route::screen('users', UserListScreen::class)
    ->name('platform.systems.users')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Users'), route('platform.systems.users')));

// Platform > System > Offices > Office
Route::screen('offices/{office}/edit', OfficeEditScreen::class)
    ->name('platform.systems.offices.edit')
    ->breadcrumbs(fn (Trail $trail, $office) => $trail
        ->parent('platform.systems.offices')
        ->push($office->name, route('platform.systems.offices.edit', $office)));

// Platform > System > Offices > Create
Route::screen('offices/create', OfficeEditScreen::class)
    ->name('platform.systems.offices.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.systems.offices')
        ->push(__('Create'), route('platform.systems.offices.create')));

// Platform > System > Offices
Route::screen('offices', OfficeListScreen::class)
    ->name('platform.systems.offices')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Offices'), route('platform.systems.offices')));

// Platform > System > Schools
Route::screen('schools', SchoolListScreen::class)
    ->name('platform.systems.schools')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Schools'), route('platform.systems.schools')));

// Platform > System > Roles > Role
Route::screen('roles/{role}/edit', RoleEditScreen::class)
    ->name('platform.systems.roles.edit')
    ->breadcrumbs(fn (Trail $trail, $role) => $trail
        ->parent('platform.systems.roles')
        ->push($role->name, route('platform.systems.roles.edit', $role)));

// Platform > System > Roles > Create
Route::screen('roles/create', RoleEditScreen::class)
    ->name('platform.systems.roles.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.systems.roles')
        ->push(__('Create'), route('platform.systems.roles.create')));

// Platform > System > Roles
Route::screen('roles', RoleListScreen::class)
    ->name('platform.systems.roles')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push(__('Roles'), route('platform.systems.roles')));
