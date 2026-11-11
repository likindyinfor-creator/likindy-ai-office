<?php

use App\Http\Controllers\Api\V1\DocumentAttachmentController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MobileAuthenticationController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\WebAuthenticationController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\Api\V1\WorkspaceInvitationController;
use App\Http\Controllers\Api\V1\WorkspaceMemberController;
use App\Http\Middleware\RedactInvitationToken;
use App\Http\Middleware\RequirePersonalAccessToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class)->name('api.v1.health');
    Route::post('/auth/register', RegistrationController::class)
        ->middleware('throttle:5,1')
        ->name('api.v1.auth.register');
    Route::get('/auth/email/verify/{id}/{hash}', EmailVerificationController::class)
        ->middleware('throttle:6,1')
        ->name('verification.verify');
    Route::post('/auth/login', [WebAuthenticationController::class, 'login'])
        ->middleware('throttle:auth-login')
        ->name('api.v1.auth.login');
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:auth-forgot-password')
        ->name('api.v1.auth.forgot-password');
    Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:auth-reset-password')
        ->name('api.v1.auth.reset-password');
    Route::post('/mobile/auth/login', [MobileAuthenticationController::class, 'login'])
        ->middleware('throttle:mobile-auth-login')
        ->name('api.v1.mobile.auth.login');
    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::post('/invitations/{token}/accept', [WorkspaceInvitationController::class, 'accept'])
            ->middleware('throttle:workspace-invitation-response')
            ->middleware(RedactInvitationToken::class)
            ->name('api.v1.invitations.accept');
        Route::post('/invitations/{token}/decline', [WorkspaceInvitationController::class, 'decline'])
            ->middleware('throttle:workspace-invitation-response')
            ->middleware(RedactInvitationToken::class)
            ->name('api.v1.invitations.decline');
    });

    Route::middleware(['auth:sanctum', 'verified', RequirePersonalAccessToken::class])
        ->prefix('mobile/auth')
        ->name('api.v1.mobile.auth.')
        ->group(function () {
            Route::post('/logout', [MobileAuthenticationController::class, 'logout'])
                ->name('logout');
            Route::get('/me', [MobileAuthenticationController::class, 'me'])
                ->name('me');
        });

    Route::middleware('auth:web')->group(function () {
        Route::post('/auth/logout', [WebAuthenticationController::class, 'logout'])
            ->name('api.v1.auth.logout');

        Route::middleware('verified')->group(function () {
            Route::get('/auth/me', [WebAuthenticationController::class, 'me'])
                ->name('api.v1.auth.me');
        });
    });

    Route::middleware(['auth:sanctum', 'verified'])->group(function () {
        Route::get('/workspaces', [WorkspaceController::class, 'index'])
            ->name('api.v1.workspaces.index');
        Route::post('/workspaces', [WorkspaceController::class, 'store'])
            ->middleware('throttle:workspace-create')
            ->name('api.v1.workspaces.store');
        Route::get('/workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'index'])
            ->name('api.v1.workspaces.invitations.index');
        Route::post('/workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'store'])
            ->middleware('throttle:workspace-invitation-create')
            ->name('api.v1.workspaces.invitations.store');
        Route::delete(
            '/workspaces/{workspace}/invitations/{invitation}',
            [WorkspaceInvitationController::class, 'revoke']
        )->name('api.v1.workspaces.invitations.destroy');
        Route::get('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'index'])
            ->name('api.v1.workspaces.members.index');
        Route::patch('/workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'update'])
            ->name('api.v1.workspaces.members.update');
        Route::delete('/workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'destroy'])
            ->name('api.v1.workspaces.members.destroy');

        Route::get('/workspaces/{workspace}/documents', [DocumentController::class, 'index'])
            ->name('api.v1.workspaces.documents.index');
        Route::post('/workspaces/{workspace}/documents', [DocumentController::class, 'store'])
            ->name('api.v1.workspaces.documents.store');
        Route::get('/workspaces/{workspace}/documents/{document}', [DocumentController::class, 'show'])
            ->name('api.v1.workspaces.documents.show');
        Route::patch('/workspaces/{workspace}/documents/{document}', [DocumentController::class, 'update'])
            ->name('api.v1.workspaces.documents.update');
        Route::delete('/workspaces/{workspace}/documents/{document}', [DocumentController::class, 'destroy'])
            ->name('api.v1.workspaces.documents.destroy');
        Route::post('/workspaces/{workspace}/documents/{document}/restore', [DocumentController::class, 'restore'])
            ->name('api.v1.workspaces.documents.restore');
        Route::get('/workspaces/{workspace}/documents/{document}/sharing', [DocumentController::class, 'accessIndex'])
            ->name('api.v1.workspaces.documents.sharing.index');
        Route::post('/workspaces/{workspace}/documents/{document}/sharing', [DocumentController::class, 'grantAccess'])
            ->name('api.v1.workspaces.documents.sharing.store');
        Route::patch(
            '/workspaces/{workspace}/documents/{document}/sharing/{member}',
            [DocumentController::class, 'updateAccess']
        )->name('api.v1.workspaces.documents.sharing.update');
        Route::delete(
            '/workspaces/{workspace}/documents/{document}/sharing/{member}',
            [DocumentController::class, 'revokeAccess']
        )->name('api.v1.workspaces.documents.sharing.destroy');
        Route::get('/workspaces/{workspace}/documents/{document}/versions', [DocumentController::class, 'versions'])
            ->name('api.v1.workspaces.documents.versions.index');
        Route::get(
            '/workspaces/{workspace}/documents/{document}/versions/{version}',
            [DocumentController::class, 'showVersion']
        )->whereNumber('version')->name('api.v1.workspaces.documents.versions.show');
        Route::post(
            '/workspaces/{workspace}/documents/{document}/versions/{version}/restore',
            [DocumentController::class, 'restoreVersion']
        )->whereNumber('version')->name('api.v1.workspaces.documents.versions.restore');
        Route::get(
            '/workspaces/{workspace}/documents/{document}/attachments',
            [DocumentAttachmentController::class, 'index']
        )->name('api.v1.workspaces.documents.attachments.index');
        Route::post(
            '/workspaces/{workspace}/documents/{document}/attachments',
            [DocumentAttachmentController::class, 'store']
        )->name('api.v1.workspaces.documents.attachments.store');
        Route::get(
            '/workspaces/{workspace}/documents/{document}/attachments/{attachment}/download',
            [DocumentAttachmentController::class, 'download']
        )->name('api.v1.workspaces.documents.attachments.download');
        Route::delete(
            '/workspaces/{workspace}/documents/{document}/attachments/{attachment}',
            [DocumentAttachmentController::class, 'destroy']
        )->name('api.v1.workspaces.documents.attachments.destroy');
    });
});
