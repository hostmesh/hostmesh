<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Commands\ResetAdministratorTwoFactor;
use App\Application\Exceptions\AdministratorNotFound;
use App\Application\Exceptions\TwoFactorNotEnabled;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan administrator:reset-two-factor` (issue #136): for an
 * administrator who lost both the authenticator and the recovery codes,
 * run by an operator with shell access (spec section 28). Thin, like a
 * controller: it calls the application layer and reports the outcome.
 */
final class ResetAdministratorTwoFactorCommand extends Command
{
    protected $signature = 'administrator:reset-two-factor
        {email : The address the administrator logs in with}';

    protected $description = 'Remove the second factor of an administrator and end their sessions';

    /** @throws Throwable */
    public function handle(ResetAdministratorTwoFactor $resetTwoFactor): int
    {
        $email = (string) $this->argument('email');

        try {
            $resetTwoFactor->handle($email);
        } catch (AdministratorNotFound|TwoFactorNotEnabled $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The second factor of '.mb_strtolower($email).' was removed and their sessions were ended.');
        $this->components->warn('The next login, with the password, sets up two-factor authentication again.');

        return self::SUCCESS;
    }
}
