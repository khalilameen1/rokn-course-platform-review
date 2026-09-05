<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The dashboard role matrix is deliberately an allow-list.
 *
 * Administrators retain full dashboard access. Moderators only receive the
 * named educational workflows below; a newly-added or unnamed route is denied
 * until it is reviewed and explicitly added here.
 */
final class AdminPermissionMatrix
{
    public const ACCOUNT_CREDENTIALS = 'account.credentials';
    public const CONTENT_CURATION = 'content.curation';

    /** @var array<string, list<string>> */
    private const MODERATOR_RULES = [
        'admin.dashboard' => ['GET'],
        'admin.mfa.setup' => ['GET'],
        'admin.mfa.setup.confirm' => ['POST'],
        'admin.mfa.challenge' => ['GET'],
        'admin.mfa.challenge.verify' => ['POST'],
        'admin.mfa.backup-codes' => ['GET'],
        'admin.admin_data' => ['GET'],
        'admin.update_admin_data' => ['POST'],

        // Course authoring, including the learner-facing plan presentation.
        // Global AI policy, student operations and finance remain admin-owned.
        'admin.courses.index' => ['GET'],
        'admin.courses.show' => ['GET'],
        'admin.courses.create' => ['GET'],
        'admin.courses.store' => ['POST'],
        'admin.courses.draft.start' => ['POST'],
        'admin.courses.edit' => ['GET'],
        'admin.courses.update' => ['PUT', 'PATCH'],
        'admin.courses.student-preview' => ['GET'],
        'admin.courses.media-health.probe' => ['POST'],

        // Learner-home rows are editorial content, not platform taxonomy
        // ownership. Physical deletion remains administrator-only.
        'admin.classifications.index' => ['GET'],
        'admin.classifications.create' => ['GET'],
        'admin.classifications.store' => ['POST'],
        'admin.classifications.edit' => ['GET'],
        'admin.classifications.update' => ['PUT', 'PATCH'],

        // Educational content authoring and review workflows.
        'admin.courses.sections.create' => ['GET'],
        'admin.courses.sections.store' => ['POST'],
        'admin.courses.sections.edit' => ['GET'],
        'admin.courses.sections.update' => ['PUT', 'PATCH'],
        'admin.courses.sections.destroy' => ['DELETE'],
        'admin.courses.sections.reorder' => ['POST'],
        'admin.courses.sections.video-uploads.store' => ['POST'],
        'admin.courses.sections.video-uploads.renew' => ['POST'],
        'admin.courses.sections.create-intents.show' => ['GET'],
        'admin.courses.modules.create' => ['GET'],
        'admin.courses.modules.store' => ['POST'],
        'admin.courses.modules.edit' => ['GET'],
        'admin.courses.modules.update' => ['PUT', 'PATCH'],
        'admin.courses.modules.destroy' => ['DELETE'],
        'admin.courses.modules.reorder' => ['POST'],
        'admin.courses.pdfs.index' => ['GET'],
        'admin.courses.pdfs.create' => ['GET'],
        'admin.courses.pdfs.store' => ['POST'],
        'admin.courses.pdfs.edit' => ['GET'],
        'admin.courses.pdfs.update' => ['PUT', 'PATCH'],
        'admin.courses.pdfs.destroy' => ['DELETE'],
        'admin.courses.pdfs.reorder' => ['POST'],
        'admin.courses.pdfs.toggle-status' => ['POST'],
        'admin.courses.pdfs.preview' => ['GET'],
        'admin.project-submissions.index' => ['GET'],
        'admin.project-submissions.show' => ['GET'],
        'admin.project-submissions.download' => ['GET'],
        'admin.project-submissions.attachments.download' => ['GET'],
        'admin.project-submissions.pass' => ['POST'],
        'admin.project-submissions.reject' => ['POST'],
        // Teachers and curriculum reference data belong to course operations.
        'admin.teachers.index' => ['GET'],
        'admin.teachers.create' => ['GET'],
        'admin.teachers.store' => ['POST'],
        'admin.teachers.show' => ['GET'],
        'admin.teachers.edit' => ['GET'],
        'admin.teachers.update' => ['PUT', 'PATCH'],
        'admin.teachers.deactive' => ['PATCH'],
    ];

    public function allows(?string $role, ?string $routeName, string $method): bool
    {
        if ($this->isAdministrator($role)) {
            return true;
        }

        if ($this->normalizedRole($role) !== 'moderator' || blank($routeName)) {
            return false;
        }

        $method = strtoupper($method);
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $methods = self::MODERATOR_RULES[$routeName] ?? null;

        return is_array($methods) && in_array($method, $methods, true);
    }

    /**
     * Credential visibility is deliberately separate from content ownership.
     * A moderator may maintain an instructor's public profile without gaining
     * the ability to inspect or replace that instructor's login identity.
     */
    public function allowsCapability(?string $role, string $capability): bool
    {
        return match ($capability) {
            self::ACCOUNT_CREDENTIALS => $this->isAdministrator($role),
            self::CONTENT_CURATION => in_array(
                $this->normalizedRole($role),
                ['admin', 'moderator'],
                true
            ),
            default => false,
        };
    }

    public function isAdministrator(?string $role): bool
    {
        return $this->normalizedRole($role) === 'admin';
    }

    /** @return array<string, list<string>> */
    public function moderatorRules(): array
    {
        return self::MODERATOR_RULES;
    }

    private function normalizedRole(?string $role): string
    {
        return strtolower(trim((string) $role));
    }
}
