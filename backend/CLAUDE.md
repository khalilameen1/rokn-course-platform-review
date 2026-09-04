# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Rokn is a **single-tenant** e-learning mobile app backend (dashboard + APIs) built with Laravel 12 and the PHP 8.4 release line (8.4.24 minimum). It provides course access, learning progress, projects, certificates, payments, notifications and administration functionality.

**Important context:** The codebase was forked from an older multi-tenant SaaS app. It is being incrementally cleaned up to remove all multi-tenancy code (tenant_id columns, subdomain routing, tenant middleware) and legacy modules that don't belong to Rokn (e-commerce: orders, products, stores, merchant portal, etc.). This cleanup is ongoing — expect to encounter leftover multi-tenant patterns and unused modules that should be removed when touched.

## Common Commands

```bash
# Install dependencies
composer install
npm install

# Run dev server
php artisan serve

# Build dashboard assets (esbuild + Sass)
npm run dev          # one-time build
npm run watch        # watch mode
npm run prod         # production build

# Run all tests (uses SQLite in-memory)
php artisan test
# or
./vendor/bin/phpunit

# Run a single test file
./vendor/bin/phpunit tests/Feature/GradeApiTest.php

# Run a single test method
./vendor/bin/phpunit --filter test_method_name

# Database
php artisan migrate
php artisan db:seed

# Generate Swagger/OpenAPI docs
php artisan l5-swagger:generate

# Run the scheduler locally
php artisan schedule:run
```

## Architecture

### Legacy Multi-Tenancy (being removed)
- The old app used subdomain-based tenant isolation with `AppFrontNameSpace` middleware and `tenant_id` columns everywhere.
- **Do NOT add tenant_id to new tables or new code.** When modifying existing code, remove tenant_id references and tenant middleware where possible.
- Legacy middleware stack (`checkWebSiteEnabled`, `AppFrontNameSpace`, `WebsiteVisitorCount`) should be simplified/removed as cleanup progresses.
- Legacy modules from the old app (e-commerce, merchant portal, stores, products) should be removed when encountered, not maintained.

### API Response Format
All API endpoints return:
```json
{ "status": 200, "success": true, "data": [...], "message": "..." }
```

### Layers
- **Controllers** (`app/Http/Controllers/`): Organized into `API/`, `Admin/` namespaces (`Merchant/` is legacy — to be removed). Keep controllers thin — delegate business logic to services.
- **Services** (`app/Services/`): Business logic layer. Includes `BunnyService` (CDN/media), `CertificateService`, `FcmNotificationService`, `WhatsAppService`, social auth services (Apple, Google, Facebook), etc.
- **Models** (`app/Models/`): Eloquent models with soft deletes used throughout. Traits: `HasPhoto` (polymorphic media), `HasTranslate`, `NotifyUsers`, `SendMessage`.
- **Resources** (`app/Http/Resources/`): API resource classes for response transformation.

### Routes
- `routes/api.php` — Main API for the mobile app (auth via Sanctum `auth:api` middleware)
- `routes/web.php` — Admin dashboard (`/dashboard/*`). Merchant portal routes (`/merchant/*`) are legacy — to be removed.

### Authentication
API routes use the `auth:api` guard backed by hashed, revocable API-token rows.
API sessions accept hashed bearer tokens only. Social OAuth uses server-created
state and requires PKCE S256.

### Key Integrations
- **Firebase**: Realtime database + cloud storage + FCM push notifications (config in `config/firebase.php`)
- **Pusher**: Real-time WebSocket broadcasting
- **WhatsApp** (Whatspie): Verification codes — 6-digit, 10min expiry (`config/whatsapp.php`)
- **Bunny CDN**: Media/file storage via `BunnyService`
- **DomPDF**: PDF generation for certificates

## Code Style

- PSR-12, 4-space indentation
- `declare(strict_types=1);` in new files
- PascalCase for classes, camelCase for methods/variables, snake_case for database columns, kebab-case for route names
- Use explicit return type declarations and type hints
- Use Eloquent ORM and Query Builder over raw SQL
