<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Commands\ResetPassword;
use App\Application\Exceptions\AdministratorNotFound;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan administrator:reset-password` (issue #133, decision 0023): a administrator
 * who lost the password gets a new, generated one from an operator with
 * shell access. It is printed once and their sessions end.
 */
final class ResetAdministratorPasswordCommand extends Command
{
    protected $signature = 'administrator:reset-password
        {email : The address the administrator logs in with}';

    protected $description = 'Give a administrator a new generated password, shown once, and end their sessions';

    /** @throws Throwable */
    public function handle(ResetPassword $resetPassword): int
    {
        $email = (string) $this->argument('email');

        try {
            $password = $resetPassword->administrator($email);
        } catch (AdministratorNotFound $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The password of '.mb_strtolower($email).' was reset and their sessions were signed out.');
        $this->components->twoColumnDetail('Password', $password);
        $this->components->warn('The password will not be shown again. Give it to the administrator now.');

        return self::SUCCESS;
    }
}
