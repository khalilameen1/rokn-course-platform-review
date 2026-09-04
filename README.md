# Rokn Course Platform

This is the working repository for the Rokn mobile application and its Laravel API and dashboard. The production deployment uses this repository. Production credentials, runtime data, dependency folders and mobile build outputs do not belong in source control.

## Structure

- `mobile/` — React Native / Expo application for Android and iOS.
- `backend/` — Laravel API, commerce and learning domains, operations, and the admin dashboard.

## Review status

The source is under active repair. A deployed backend or a passing test suite is not a claim that the latest APK has passed acceptance. Track source changes, deployment and device verification separately.

- Mobile and backend verification commands are listed below. Treat their current
  output and CI artifact as evidence; do not rely on a copied historical test
  count.
- Current files pass the repository secret scanners. The old source repositories are deliberately not included because their historical commits contained credentials that must be rotated independently.
- iOS remains release-blocked until `Podfile.lock` is regenerated on macOS with the pinned modern Ruby/Bundler/CocoaPods toolchain.
- Production promotion still requires staging API parity, payment reconciliation evidence, a database restore drill, and operator approval.

## Local setup

### Mobile

```bash
cd mobile
npm ci
npm run verify:config
npm run typecheck
npm run lint:release
npm run test:release
```

### Backend

```bash
cd backend
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan test
```

Use only development or staging credentials. Never copy production secrets into this repository.

## Rights

Public visibility is provided for source review. No additional license or permission is granted beyond licenses explicitly included with third-party components.
