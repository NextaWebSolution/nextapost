<?php

declare(strict_types=1);

/** Token-authenticated routes (REST API, MCP, OAuth). $r is a Router without CSRF. */

use App\Controllers\ApiController as A;
use App\Controllers\McpController as M;
use App\Controllers\OAuthController as O;

// MCP server for AI agents
$r->post('/mcp', [M::class, 'handle']);
$r->get('/mcp', [M::class, 'notAllowed']);
$r->delete('/mcp', [M::class, 'notAllowed']);

// OAuth 2.1 discovery, client registration, tokens
$r->get('/.well-known/oauth-protected-resource', [O::class, 'resourceMetadata']);
$r->get('/.well-known/oauth-protected-resource/mcp', [O::class, 'resourceMetadata']);
$r->get('/.well-known/oauth-protected-resource/api/v1', [O::class, 'resourceMetadata']);
$r->get('/.well-known/oauth-authorization-server', [O::class, 'serverMetadata']);
$r->get('/.well-known/oauth-authorization-server/mcp', [O::class, 'serverMetadata']);
$r->post('/oauth/register', [O::class, 'register']);
$r->post('/oauth/token', [O::class, 'token']);
$r->post('/oauth/revoke', [O::class, 'revoke']);

$r->get('/api/v1', [A::class, 'index']);
$r->get('/api/v1/workspace', [A::class, 'workspaceInfo']);

$r->get('/api/v1/channels', [A::class, 'channels']);
$r->get('/api/v1/channels/{id}', [A::class, 'channel']);
$r->get('/api/v1/channels/{id}/schedule', [A::class, 'schedule']);
$r->put('/api/v1/channels/{id}/schedule', [A::class, 'scheduleUpdate']);

$r->get('/api/v1/posts', [A::class, 'posts']);
$r->post('/api/v1/posts', [A::class, 'postStore']);
$r->post('/api/v1/posts/batch', [A::class, 'postBatch']);
$r->get('/api/v1/posts/{id}', [A::class, 'post']);
$r->patch('/api/v1/posts/{id}', [A::class, 'postUpdate']);
$r->delete('/api/v1/posts/{id}', [A::class, 'postDelete']);
$r->get('/api/v1/posts/{id}/notes', [A::class, 'notes']);
$r->post('/api/v1/posts/{id}/notes', [A::class, 'noteStore']);
$r->delete('/api/v1/posts/{id}/notes/{note}', [A::class, 'noteDelete']);
$r->post('/api/v1/posts/{id}/{action}', [A::class, 'postAction']);

$r->get('/api/v1/media', [A::class, 'media']);
$r->post('/api/v1/media', [A::class, 'mediaStore']);
$r->get('/api/v1/media/{id}', [A::class, 'mediaItem']);
$r->patch('/api/v1/media/{id}', [A::class, 'mediaUpdate']);
$r->delete('/api/v1/media/{id}', [A::class, 'mediaDelete']);

$r->post('/api/v1/webhooks/{id}/rotate-secret', [A::class, 'webhookRotate']);
$r->get('/api/v1/webhooks/{id}/deliveries', [A::class, 'webhookDeliveries']);
$r->post('/api/v1/webhooks/{id}/deliveries/{d}/replay', [A::class, 'webhookReplay']);
$r->get('/api/v1/idea-columns', [A::class, 'ideaColumns']);
$r->get('/api/v1/ideas', [A::class, 'ideas']);
$r->post('/api/v1/ideas', [A::class, 'ideaStore']);
$r->patch('/api/v1/ideas/{id}', [A::class, 'ideaUpdate']);
$r->delete('/api/v1/ideas/{id}', [A::class, 'ideaDelete']);
$r->get('/api/v1/feeds', [A::class, 'feeds']);
$r->post('/api/v1/feeds', [A::class, 'feedStore']);
$r->delete('/api/v1/feeds/{id}', [A::class, 'feedDelete']);
$r->get('/api/v1/feed-items', [A::class, 'feedItems']);
$r->post('/api/v1/feed-items/{id}/idea', [A::class, 'feedItemIdea']);

// labels, signatures, templates, webhooks (must stay last: {c} matches any name)
$r->get('/api/v1/{c}', [A::class, 'collList']);
$r->post('/api/v1/{c}', [A::class, 'collStore']);
$r->patch('/api/v1/{c}/{id}', [A::class, 'collUpdate']);
$r->delete('/api/v1/{c}/{id}', [A::class, 'collDelete']);
