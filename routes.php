<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CalendarController;
use App\Controllers\ChannelController;
use App\Controllers\HomeController;
use App\Controllers\MediaController;
use App\Controllers\PostController;
use App\Controllers\SystemController;
use App\Controllers\WorkspaceController;

/** @var App\Core\Router $r */

$r->get('/', [HomeController::class, 'index']);
$r->get('/health', [HomeController::class, 'health']);

// Authentication
$r->get('/login', [AuthController::class, 'showLogin']);
$r->post('/login', [AuthController::class, 'login']);
$r->post('/logout', [AuthController::class, 'logout']);
$r->get('/register', [AuthController::class, 'showRegister']);
$r->post('/register', [AuthController::class, 'register']);
$r->get('/forgot', [AuthController::class, 'showForgot']);
$r->post('/forgot', [AuthController::class, 'forgot']);
$r->get('/reset/{token}', [AuthController::class, 'showReset']);
$r->post('/reset/{token}', [AuthController::class, 'reset']);

// Account
$r->get('/account', [AccountController::class, 'show']);
$r->post('/account', [AccountController::class, 'update']);
$r->post('/account/theme', [AccountController::class, 'theme']);

// Workspaces
$r->post('/workspaces', [WorkspaceController::class, 'create']);
$r->get('/w/{ws}/settings', [WorkspaceController::class, 'settings']);
$r->post('/w/{ws}/settings', [WorkspaceController::class, 'update']);
$r->post('/w/{ws}/delete', [WorkspaceController::class, 'destroy']);
$r->post('/w/{ws}/retention', [WorkspaceController::class, 'saveRetention']);
$r->post('/w/{ws}/retention/run', [WorkspaceController::class, 'runRetention']);
$r->post('/w/{ws}/members', [WorkspaceController::class, 'addMember']);
$r->post('/w/{ws}/members/{user}/role', [WorkspaceController::class, 'updateMember']);
$r->post('/w/{ws}/members/{user}/remove', [WorkspaceController::class, 'removeMember']);

// Calendar
$r->get('/w/{ws}', [CalendarController::class, 'index']);
$r->get('/w/{ws}/calendar', [CalendarController::class, 'index']);
$r->get('/w/{ws}/calendar/events', [CalendarController::class, 'events']);

// Posts
$r->get('/w/{ws}/posts', [PostController::class, 'index']);
$r->get('/w/{ws}/posts/new', [PostController::class, 'create']);
$r->post('/w/{ws}/posts', [PostController::class, 'store']);
$r->get('/w/{ws}/posts/{id}/edit', [PostController::class, 'edit']);
$r->post('/w/{ws}/posts/{id}', [PostController::class, 'update']);
$r->patch('/w/{ws}/posts/{id}/move', [PostController::class, 'move']);
$r->post('/w/{ws}/posts/{id}/delete', [PostController::class, 'delete']);
$r->post('/w/{ws}/posts/{id}/approve', [PostController::class, 'approve']);
$r->post('/w/{ws}/posts/{id}/reject', [PostController::class, 'reject']);
$r->post('/w/{ws}/posts/{id}/retry', [PostController::class, 'retry']);
$r->post('/w/{ws}/posts/{id}/duplicate', [PostController::class, 'duplicate']);

// Channels
$r->get('/w/{ws}/channels', [ChannelController::class, 'index']);
$r->get('/w/{ws}/channels/connect/{network}', [ChannelController::class, 'connectForm']);
$r->post('/w/{ws}/channels/connect/{network}', [ChannelController::class, 'connect']);
$r->post('/w/{ws}/channels/pick', [ChannelController::class, 'pick']);
$r->post('/w/{ws}/channels/{id}/delete', [ChannelController::class, 'delete']);
$r->get('/oauth/{app}/callback', [ChannelController::class, 'callback']);

// Media
$r->get('/w/{ws}/media', [MediaController::class, 'index']);
$r->get('/w/{ws}/media.json', [MediaController::class, 'list']);
$r->post('/w/{ws}/media', [MediaController::class, 'upload']);
$r->get('/w/{ws}/media/{id}/file', [MediaController::class, 'file']);
$r->post('/w/{ws}/media/{id}', [MediaController::class, 'update']);
$r->post('/w/{ws}/media/{id}/delete', [MediaController::class, 'delete']);

// System (super admin)
$r->get('/system', [SystemController::class, 'index']);
$r->post('/system/general', [SystemController::class, 'saveGeneral']);
$r->post('/system/mail', [SystemController::class, 'saveMail']);
$r->post('/system/integrations', [SystemController::class, 'saveIntegrations']);
$r->post('/system/updates', [SystemController::class, 'saveUpdates']);
$r->post('/system/updates/check', [SystemController::class, 'checkUpdates']);
$r->post('/system/updates/install', [SystemController::class, 'installUpdate']);
$r->post('/system/updates/upload', [SystemController::class, 'uploadUpdate']);
$r->post('/system/backups', [SystemController::class, 'createBackup']);
$r->get('/system/backups/{name}', [SystemController::class, 'downloadBackup']);
$r->post('/system/backups/{name}/delete', [SystemController::class, 'deleteBackup']);
$r->post('/system/users/{uuid}/toggle', [SystemController::class, 'toggleUser']);
$r->post('/system/users/{uuid}/delete', [SystemController::class, 'deleteUser']);
$r->post('/system/cron', [SystemController::class, 'runCron']);
