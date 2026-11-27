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
$r->get('/oauth/authorize', [App\Controllers\OAuthController::class, 'consent']);
$r->post('/oauth/authorize', [App\Controllers\OAuthController::class, 'consentSubmit']);
$r->get('/oauth/{app}/callback', [ChannelController::class, 'callback']);

// Media
$r->post('/w/{ws}/channels/{id}/schedule', [ChannelController::class, 'schedule']);
$r->get('/w/{ws}/library', [App\Controllers\LibraryController::class, 'index']);
$r->post('/w/{ws}/labels', [App\Controllers\LibraryController::class, 'labelStore']);
$r->post('/w/{ws}/labels/{id}', [App\Controllers\LibraryController::class, 'labelUpdate']);
$r->post('/w/{ws}/labels/{id}/delete', [App\Controllers\LibraryController::class, 'labelDelete']);
$r->post('/w/{ws}/signatures', [App\Controllers\LibraryController::class, 'signatureStore']);
$r->post('/w/{ws}/signatures/{id}', [App\Controllers\LibraryController::class, 'signatureUpdate']);
$r->post('/w/{ws}/signatures/{id}/delete', [App\Controllers\LibraryController::class, 'signatureDelete']);
$r->post('/w/{ws}/templates', [App\Controllers\LibraryController::class, 'templateStore']);
$r->post('/w/{ws}/templates/{id}', [App\Controllers\LibraryController::class, 'templateUpdate']);
$r->post('/w/{ws}/templates/{id}/delete', [App\Controllers\LibraryController::class, 'templateDelete']);
$r->post('/w/{ws}/posts/{id}/notes', [App\Controllers\LibraryController::class, 'noteStore']);
$r->post('/w/{ws}/posts/{id}/notes/{note}', [App\Controllers\LibraryController::class, 'noteUpdate']);
$r->post('/w/{ws}/posts/{id}/notes/{note}/delete', [App\Controllers\LibraryController::class, 'noteDelete']);
// Developers & AI agents: API keys, connected apps, webhooks
// Ideas board and feeds
$r->get('/w/{ws}/ideas', [App\Controllers\IdeaController::class, 'index']);
$r->post('/w/{ws}/ideas', [App\Controllers\IdeaController::class, 'store']);
$r->post('/w/{ws}/ideas/{id}', [App\Controllers\IdeaController::class, 'update']);
$r->post('/w/{ws}/ideas/{id}/move', [App\Controllers\IdeaController::class, 'move']);
$r->post('/w/{ws}/ideas/{id}/delete', [App\Controllers\IdeaController::class, 'delete']);
$r->post('/w/{ws}/idea-stages', [App\Controllers\IdeaController::class, 'stageStore']);
$r->post('/w/{ws}/idea-stages/{id}', [App\Controllers\IdeaController::class, 'stageUpdate']);
$r->post('/w/{ws}/idea-stages/{id}/delete', [App\Controllers\IdeaController::class, 'stageDelete']);
$r->post('/w/{ws}/feeds', [App\Controllers\IdeaController::class, 'feedStore']);
$r->post('/w/{ws}/feeds/items/{id}/idea', [App\Controllers\IdeaController::class, 'itemIdea']);
$r->post('/w/{ws}/feeds/items/{id}/dismiss', [App\Controllers\IdeaController::class, 'itemDismiss']);
$r->post('/w/{ws}/feeds/{id}', [App\Controllers\IdeaController::class, 'feedUpdate']);
$r->post('/w/{ws}/feeds/{id}/delete', [App\Controllers\IdeaController::class, 'feedDelete']);
$r->post('/w/{ws}/feeds/{id}/refresh', [App\Controllers\IdeaController::class, 'feedRefresh']);
// Media sources (Unsplash, Canva, Google Photos, Google Drive)
$r->get('/w/{ws}/media-sources', [App\Controllers\MediaSourceController::class, 'status']);
$r->get('/w/{ws}/media-sources/unsplash/search', [App\Controllers\MediaSourceController::class, 'unsplashSearch']);
$r->post('/w/{ws}/media-sources/unsplash/import', [App\Controllers\MediaSourceController::class, 'unsplashImport']);
$r->get('/w/{ws}/media-sources/canva/designs', [App\Controllers\MediaSourceController::class, 'canvaDesigns']);
$r->post('/w/{ws}/media-sources/canva/import', [App\Controllers\MediaSourceController::class, 'canvaImport']);
$r->post('/w/{ws}/media-sources/google-photos/session', [App\Controllers\MediaSourceController::class, 'photosSession']);
$r->get('/w/{ws}/media-sources/google-photos/session/{id}', [App\Controllers\MediaSourceController::class, 'photosPoll']);
$r->post('/w/{ws}/media-sources/google-photos/import', [App\Controllers\MediaSourceController::class, 'photosImport']);
$r->post('/w/{ws}/media-sources/url/import', [App\Controllers\MediaSourceController::class, 'urlImport']);
$r->get('/w/{ws}/media-sources/google-drive/picker', [App\Controllers\MediaSourceController::class, 'drivePicker']);
$r->post('/w/{ws}/media-sources/google-drive/import', [App\Controllers\MediaSourceController::class, 'driveImport']);
$r->get('/w/{ws}/media-sources/{source}/connect', [App\Controllers\MediaSourceController::class, 'connect']);
$r->post('/w/{ws}/media-sources/{source}/disconnect', [App\Controllers\MediaSourceController::class, 'disconnect']);
$r->get('/media-sources/{source}/callback', [App\Controllers\MediaSourceController::class, 'callback']);
// Repurpose rules
// Comment-to-DM flows (optional module; every route answers 404 while it is off)
$r->get('/w/{ws}/automations', [App\Controllers\AutomationController::class, 'index']);
$r->post('/w/{ws}/automations', [App\Controllers\AutomationController::class, 'store']);
$r->post('/w/{ws}/automations/channels/{channel}/connect', [App\Controllers\AutomationController::class, 'connect']);
$r->get('/w/{ws}/automations/channels/{channel}/posts', [App\Controllers\AutomationController::class, 'posts']);
$r->post('/w/{ws}/automations/{id}', [App\Controllers\AutomationController::class, 'update']);
$r->post('/w/{ws}/automations/{id}/status', [App\Controllers\AutomationController::class, 'status']);
$r->post('/w/{ws}/automations/{id}/delete', [App\Controllers\AutomationController::class, 'delete']);
$r->get('/w/{ws}/automations/{id}/activity', [App\Controllers\AutomationController::class, 'activity']);
$r->get('/go/{id}', [App\Controllers\AutomationController::class, 'go']);
$r->get('/w/{ws}/repurpose', [App\Controllers\RepurposeController::class, 'index']);
$r->post('/w/{ws}/repurpose', [App\Controllers\RepurposeController::class, 'store']);
$r->post('/w/{ws}/repurpose/{id}/status', [App\Controllers\RepurposeController::class, 'status']);
$r->post('/w/{ws}/repurpose/{id}/run', [App\Controllers\RepurposeController::class, 'run']);
$r->post('/w/{ws}/repurpose/{id}/delete', [App\Controllers\RepurposeController::class, 'delete']);
// AI writing assistant
$r->post('/w/{ws}/ai/assist', [App\Controllers\AiController::class, 'assist']);
$r->post('/w/{ws}/media/{id}/alt-text', [App\Controllers\AiController::class, 'altText']);
$r->post('/w/{ws}/settings/ai', [App\Controllers\AiController::class, 'save']);
$r->post('/w/{ws}/settings/ai/test', [App\Controllers\AiController::class, 'test']);
$r->get('/w/{ws}/developers', [App\Controllers\DeveloperController::class, 'index']);
$r->post('/w/{ws}/developers/keys', [App\Controllers\DeveloperController::class, 'keyStore']);
$r->post('/w/{ws}/developers/keys/{id}/revoke', [App\Controllers\DeveloperController::class, 'keyRevoke']);
$r->post('/w/{ws}/developers/apps/{id}/disconnect', [App\Controllers\DeveloperController::class, 'appDisconnect']);
$r->post('/w/{ws}/developers/webhooks', [App\Controllers\DeveloperController::class, 'hookStore']);
$r->post('/w/{ws}/developers/webhooks/{id}', [App\Controllers\DeveloperController::class, 'hookUpdate']);
$r->post('/w/{ws}/developers/webhooks/{id}/delete', [App\Controllers\DeveloperController::class, 'hookDelete']);
$r->post('/w/{ws}/developers/webhooks/{id}/rotate', [App\Controllers\DeveloperController::class, 'hookRotate']);
$r->post('/w/{ws}/developers/webhooks/{id}/test', [App\Controllers\DeveloperController::class, 'hookTest']);
$r->get('/w/{ws}/developers/webhooks/{id}/deliveries', [App\Controllers\DeveloperController::class, 'hookDeliveries']);
$r->post('/w/{ws}/developers/webhooks/{id}/deliveries/{d}/replay', [App\Controllers\DeveloperController::class, 'hookReplay']);
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
$r->post('/system/languages/{code}/fill', [SystemController::class, 'fillLanguage']);
$r->post('/system/languages/{code}/reset', [SystemController::class, 'resetLanguage']);
