<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Commands\CreateCustomer;
use App\Application\Exceptions\CustomerExists;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * `php artisan customer:create` (issue #134, decision 0022): how a customer
 * gets an account until registration comes with checkout (Slice 6), for an
 * operator with shell access. Thin, like a controller: it checks what was
 * typed and calls the application layer. The password is generated there
 * and printed here once; it is never an argument, so it reaches no shell
 * history.
 */
final class CreateCustomerCommand extends Command
{
    protected $signature = 'customer:create
        {email : The address the customer logs in with}
        {--name= : The name of the customer}';

    protected $description = 'Create a customer account with a generated password, which is shown once';

    /** @throws Throwable */
    public function handle(CreateCustomer $createCustomer): int
    {
        $validator = Validator::make(
            [
                'email' => $this->argument('email'),
                'name' => $this->option('name'),
            ],
            [
                'email' => ['required', 'string', 'email:rfc', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
            ],
            [
                'name.required' => 'Give the name of the customer with --name.',
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        /** @var array{email: string, name: string} $input */
        $input = $validator->validated();

        try {
            $created = $createCustomer->handle(null, trim($input['name']), $input['email']);
        } catch (CustomerExists $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The customer was created.');
        $this->components->twoColumnDetail('Address', $created->user->email);
        $this->components->twoColumnDetail('Password', $created->password);
        $this->components->warn('The password will not be shown again. Give it to the customer now, who logs in at the portal.');

        return self::SUCCESS;
    }
}
