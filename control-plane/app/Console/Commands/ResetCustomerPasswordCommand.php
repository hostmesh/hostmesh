<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Commands\ResetPassword;
use App\Application\Exceptions\CustomerNotFound;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan customer:reset-password` (issue #133, decision 0023): a customer
 * who lost the password gets a new, generated one from an operator with
 * shell access. It is printed once and their sessions end.
 */
final class ResetCustomerPasswordCommand extends Command
{
    protected $signature = 'customer:reset-password
        {email : The address the customer logs in with}';

    protected $description = 'Give a customer a new generated password, shown once, and end their sessions';

    /** @throws Throwable */
    public function handle(ResetPassword $resetPassword): int
    {
        $email = (string) $this->argument('email');

        try {
            $password = $resetPassword->customer($email);
        } catch (CustomerNotFound $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The password of '.mb_strtolower($email).' was reset and their sessions were signed out.');
        $this->components->twoColumnDetail('Password', $password);
        $this->components->warn('The password will not be shown again. Give it to the customer now.');

        return self::SUCCESS;
    }
}
