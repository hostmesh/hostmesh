<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Database\Factories\Concerns\TwoFactorTestValues;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class Browser
{
    public const string ADMIN = 'https://admin.hostmesh.test';

    public const string PORTAL = 'https://app.hostmesh.test';

    public const string ADMIN_SESSION = '__Host-hostmesh-admin-session';

    public const string PORTAL_SESSION = '__Host-hostmesh-portal-session';

    public const string ADMIN_CSRF = 'XSRF-TOKEN-ADMIN';

    public const string PORTAL_CSRF = 'XSRF-TOKEN-PORTAL';

    public const string PASSWORD = 'correct-password';

    protected const array CSRF_COOKIES = [
        self::ADMIN => self::ADMIN_CSRF,
        self::PORTAL => self::PORTAL_CSRF,
    ];

    protected const array PREFIXES = [
        self::ADMIN => '/api/v1/admin',
        self::PORTAL => '/api/v1/customer',
    ];

    /** @var array<string, string> Raw (encrypted) cookie values, as a browser stores them. */
    protected array $cookies = [];

    /** null: copy the app's own cookie. false: send no header. string: send this value.  */
    protected string|false|null $csrfToken = null;

    public function __construct(
        protected readonly TestCase $test,
        protected readonly string $api = 'https://api.hostmesh.test',
    ) {
        //
    }

    public static function createAccount(string $origin): Administrator|User
    {
        return $origin === self::ADMIN
            ? Administrator::factory()->create()
            : User::factory()->create();
    }

    /** An account with confirmed two-factor (TwoFactorTestValues::TEST_SECRET). */
    public static function createEnrolled(string $origin): Administrator|User
    {
        return $origin === self::ADMIN
            ? Administrator::factory()->withTwoFactor()->create()
            : User::factory()->withTwoFactor()->create();
    }

    /**
     * The code an authenticator app shows now. A code works once: call
     * $this->travel(30)->seconds() in the test before asking for another.
     */
    public static function code(string $secret = TwoFactorTestValues::TEST_SECRET): string
    {
        return (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30));
    }

    /** Six digits that are not valid now, nor one step either way. */
    public static function wrongCode(string $secret = TwoFactorTestValues::TEST_SECRET): string
    {
        $step = intdiv(now()->getTimestamp(), 30);
        $valid = array_map(fn (int $s): string => (new Google2FA)->oathTotp($secret, $s), [$step - 1, $step, $step + 1]);

        foreach (['000000', '111111', '222222', '333333'] as $candidate) {
            if (! in_array($candidate, $valid, true)) {
                return $candidate;
            }
        }

        return '444444';
    }

    /** @return TestResponse<Response> */
    public function get(string $origin, string $uri): TestResponse
    {
        return $this->send('GET', $origin, $uri, []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    public function post(string $origin, string $uri, array $data = []): TestResponse
    {
        return $this->send('POST', $origin, $uri, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    public function put(string $origin, string $uri, array $data = []): TestResponse
    {
        return $this->send('PUT', $origin, $uri, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    public function delete(string $origin, string $uri, array $data = []): TestResponse
    {
        return $this->send('DELETE', $origin, $uri, $data);
    }

    /**
     * Any method, for tests that walk the route list.
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    public function request(string $method, string $origin, string $uri, array $data = []): TestResponse
    {
        return $this->send($method, $origin, $uri, $data);
    }

    /**
     * The SPA's login sequence: fetch the CSRF cookie, then post the credentials.
     *
     * @return TestResponse<Response>
     */
    public function logIn(string $origin, string $email, string $password = self::PASSWORD): TestResponse
    {
        $prefix = self::PREFIXES[$origin];

        $this->get($origin, $prefix.'/auth/csrf-cookie');

        return $this->post($origin, $prefix.'/auth/login', ['email' => $email, 'password' => $password]);
    }

    /**
     * A complete login: password, then the challenge when the account has
     * two-factor. Uses the current code, so travel 30 seconds before the
     * test sends another one.
     */
    public function signIn(string $origin, Administrator|User $account): void
    {
        $this->logIn($origin, $account->email)->assertOk();

        if ($account->hasTwoFactor()) {
            $this->post($origin, self::PREFIXES[$origin].'/auth/two-factor/challenge', ['code' => self::code()])
                ->assertNoContent();
        }
    }

    public function usingCsrfToken(string|false|null $token): self
    {
        $this->csrfToken = $token;

        return $this;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /** The decrypted session ID held in the given cookie. */
    public function sessionId(string $cookie): string
    {
        return CookieValuePrefix::remove(
            app('encrypter')->decrypt($this->cookies[$cookie], false)
        );
    }

    /** Stores a cookie that is validly encrypted for $name and carries $value. */
    public function forge(string $name, string $value): void
    {
        $encrypter = app('encrypter');

        $this->cookies[$name] = $encrypter->encrypt(
            CookieValuePrefix::create($name, $encrypter->getKey()).$value,
            false,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    protected function send(string $method, string $origin, string $uri, array $data): TestResponse
    {
        $server = [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        $token = $this->csrfToken ?? $this->cookies[self::CSRF_COOKIES[$origin] ?? ''] ?? false;

        if ($token !== false) {
            $server['HTTP_X_XSRF_TOKEN'] = $token;
        }

        $response = $this->test->call(
            $method,
            $this->api.$uri,
            [],
            $this->cookies,
            [],
            $server,
            $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR),
        );

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
            }
        }

        return $response;
    }
}
