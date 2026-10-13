<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Commands\CreateAdministrator;
use App\Application\Exceptions\AdministratorExists;
use App\Domain\Authorization\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * `php artisan administrator:create` (issue #122): how an installation gets
 * an administrator, for an operator with shell access (spec section 28).
 * Thin, like a controller: it checks what was typed and calls the
 * application layer. The password is generated there and printed here once;
 * it is never an argument, so it reaches no shell history.
 */
final class CreateAdministratorCommand extends Command
{
    protected $signature = 'administrator:create
        {email : The address the administrator logs in with}
        {--name= : The name of the administrator}
        {--role=owner : owner or read-only}';

    protected $description = 'Create an administrator with a generated password, which is shown once';

    /** @throws Throwable */
    public function handle(CreateAdministrator $createAdministrator): int
    {
        $roles = array_map(fn (Role $role): string => $role->value, Role::cases());

        $validator = Validator::make(
            [
                'email' => $this->argument('email'),
                'name' => $this->option('name'),
                'role' => $this->option('role'),
            ],
            [
                'email' => ['required', 'string', 'email:rfc', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'role' => ['required', 'string', 'in:'.implode(',', $roles)],
            ],
            [
                'name.required' => 'Give the name of the administrator with --name.',
                'role.in' => 'The role must be one of: '.implode(', ', $roles).'.',
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        /** @var array{email: string, name: string, role: string} $input */
        $input = $validator->validated();

        try {
            $created = $createAdministrator->handle(null, trim($input['name']), $input['email'], Role::from($input['role']));
        } catch (AdministratorExists $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The administrator was created.');
        $this->components->twoColumnDetail('Address', $created->administrator->email);
        $this->components->twoColumnDetail('Role', $input['role']);
        $this->components->twoColumnDetail('Password', $created->password);
        $this->components->warn('The password will not be shown again. Store it now. The first login sets up two-factor authentication.');

        return self::SUCCESS;
    }
}
