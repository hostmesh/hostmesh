<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * What happened. A value is stored forever: add cases, never rename one.
 */
enum AuditAction: string
{
    case LoginSucceeded = 'auth.login.succeeded';
    case LoginFailed = 'auth.login.failed';
    case LoggedOut = 'auth.logout';
    case TwoFactorEnrollmentStarted = 'auth.two_factor.enrollment_started';
    case TwoFactorConfirmed = 'auth.two_factor.confirmed';
    case TwoFactorDisabled = 'auth.two_factor.disabled';
    case TwoFactorChallengePassed = 'auth.two_factor.challenge_passed';
    case TwoFactorChallengeFailed = 'auth.two_factor.challenge_failed';
    case RecoveryCodeUsed = 'auth.two_factor.recovery_code_used';
    case RecoveryCodesRegenerated = 'auth.two_factor.recovery_codes_regenerated';
    case AdministratorRoleAssigned = 'administrator.role_assigned';
    case AdministratorRoleRevoked = 'administrator.role_revoked';
    case BrandingUpdated = 'branding.updated';
    case BrandingImageReplaced = 'branding.image_replaced';
    case BrandingImageRemoved = 'branding.image_removed';
}
