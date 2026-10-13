<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\EndToEnd;

use App\Application\Commands\AssignAdministratorRole;
use App\Application\Commands\BrandingChanges;
use App\Application\Commands\UpdateBranding;
use App\Domain\Authorization\Role;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\User;
use Illuminate\Support\Str;
use Throwable;

/**
 * The data of the Playwright suite in web/e2e (issue #32). Passwords and
 * TOTP secrets are made on every run and returned to the caller; none is
 * in the repository. Running it again replaces the same accounts.
 *
 * One administrator per test that passes a two-factor challenge: a code
 * works once per account, so tests that shared one would depend on the clock.
 */
final readonly class Fixtures
{
    public const string DOMAIN = 'e2e.hostmesh.test';

    public const string PRODUCT_NAME = 'Hostmesh E2E';

    public const string PRIMARY_LIGHT = '#0b3d91';

    /** @var list<string> */
    public const array ADMINISTRATORS = ['login', 'wrong-code', 'second-factor', 'branding', 'logout', 'isolation'];

    /** @var list<string> */
    public const array CUSTOMERS = ['login', 'logout'];

    public function __construct(
        protected Totp $totp,
        protected AssignAdministratorRole $assignRole,
        protected UpdateBranding $updateBranding,
    ) {
        //
    }

    /**
     * @return array{
     *     branding: array{product_name: string, primary_light: string},
     *     administrators: array<string, array{name: string, email: string, password: string, totp_secret: string}>,
     *     customers: array<string, array{name: string, email: string, password: string}>,
     * }
     *
     * @throws Throwable
     */
    public function seed(): array
    {
        $this->updateBranding->handle(null, new BrandingChanges(
            productName: self::PRODUCT_NAME,
            primaryLight: self::PRIMARY_LIGHT,
            primaryForegroundLight: '#ffffff',
            primaryDark: '#9cc2ff',
            primaryForegroundDark: '#0a1a33',
            supportUrl: null,
            termsUrl: null,
            privacyUrl: null,
        ));

        $administrators = [];

        foreach (self::ADMINISTRATORS as $key) {
            $administrators[$key] = $this->administrator($key);
        }

        $customers = [];

        foreach (self::CUSTOMERS as $key) {
            $customers[$key] = $this->customer($key);
        }

        return [
            'branding' => ['product_name' => self::PRODUCT_NAME, 'primary_light' => self::PRIMARY_LIGHT],
            'administrators' => $administrators,
            'customers' => $customers,
        ];
    }

    /**
     * @return array{name: string, email: string, password: string, totp_secret: string}
     *
     * @throws Throwable
     */
    protected function administrator(string $key): array
    {
        $entry = [
            'name' => "E2E Admin $key",
            'email' => "admin-$key@".self::DOMAIN,
            'password' => Str::random(40),
            'totp_secret' => $this->totp->generateSecret(),
        ];

        $administrator = Administrator::query()->firstOrNew(['email' => $entry['email']]);
        $administrator->fill(['name' => $entry['name'], 'password' => $entry['password']])->save();

        // Also forgets the last accepted step of an earlier run.
        $administrator->beginTwoFactorEnrollment($entry['totp_secret']);
        $administrator->confirmTwoFactor([]);

        $this->assignRole->handle(null, $administrator, Role::Owner);

        return $entry;
    }

    /** @return array{name: string, email: string, password: string} */
    protected function customer(string $key): array
    {
        $entry = [
            'name' => "E2E Customer $key",
            'email' => "customer-$key@".self::DOMAIN,
            'password' => Str::random(40),
        ];

        $user = User::query()->firstOrNew(['email' => $entry['email']]);
        $user->fill(['name' => $entry['name'], 'password' => $entry['password']])->save();

        return $entry;
    }
}
