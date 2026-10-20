# 0018. Trusted proxies: a list of addresses in the environment, and X-Forwarded-For only

- Status: accepted
- Date: 2026-10-20
- Issue: Configure trusted proxies for the client address (#204)

## Context

The web server talks FastCGI to PHP-FPM, so the address the control plane sees is the address that connected to the web server. `infrastructure/nginx/README.md` allows `app.`, `admin.` and `api.<domain>` behind a CDN. Behind one, every request seems to come from one of the CDN's edge addresses. All clients behind it then share one budget of each rate limit (`login`, `two-factor`, `branding`), and the audit log records the CDN's address instead of the client's.

A proxy names the client in a forwarded header. Believing that header from anyone else lets a client choose its own address. That would get it around the rate limits and let it write any address into the audit log. So the control plane must know which proxies to believe.

Laravel's `TrustProxies` middleware is in the global middleware already, with no proxies. It reads `config('trustedproxy.proxies')`, which did not exist. While that value is null, it trusts every address on hostnames of some hosting platforms (`*.on-forge.com`, `*.on-vapor.com`, Laravel Cloud). By default, it also believes the proxy's `X-Forwarded-Host`, `-Proto`, `-Port` and `-Prefix`.

## Options considered

1. **In the web server:** Nginx's `realip` module (`set_real_ip_from`, `real_ip_header`), so that PHP gets the client address as `REMOTE_ADDR`. Nothing changes in the application, but every web server is configured differently (Caddy in development, Nginx in the example), and no test in this repository can check it.
2. **In the application, trusting whoever connects (`*`):** simple, and safe only while the server's firewall lets nothing but the CDN connect. A server that anyone can reach directly would believe everyone.
3. **In the application, with a list of addresses in the environment.**

## Decision

Option 3.

- **The setting:** `HOSTMESH_TRUSTED_PROXIES` holds IPv4 and IPv6 addresses and CIDR ranges, separated by commas. It is read in `config/hostmesh.php`, like the other settings, and is empty by default: no proxy is trusted.
- **Validation:** `App\Support\TrustedProxies` checks it at boot. A malformed entry stops the application, like a malformed `HOSTMESH_DOMAIN`. So does an entry that trusts every address: `*`, `**`, `REMOTE_ADDR`, and a range with a prefix length of 0.
- **Configuration:** `AppServiceProvider` writes the list to `trustedproxy.proxies`. It is always a list, an empty one included, so the platform exception above never applies.
- **Headers:** only `X-Forwarded-For` is read (`trustProxies(headers: HEADER_X_FORWARDED_FOR)` in `bootstrap/app.php`). Every major CDN and load balancer sends it. Symfony reads it from the right and skips trusted hops, so addresses that a client writes ahead of the one its proxy saw are not believed. `Forwarded`, `X-Forwarded-Host`, `-Proto`, `-Port` and `-Prefix` are ignored even from a trusted proxy: TLS ends at the web server, which knows the host and the scheme itself.

## Consequences

- Behind a CDN, the operator lists the CDN's published ranges (Cloudflare publishes them at https://www.cloudflare.com/ips/) and keeps the list current when the CDN changes it. An address missing from the list makes those requests look like they come from the CDN again. That is the situation before this decision, not a security risk.
- A web server that also uses `realip` does no harm: PHP then sees the client as the connecting address, which is not a trusted proxy, and the header is not read again. The Nginx README says to use one or the other, and recommends this setting.
- `agent.<domain>` is never behind a proxy (spec section 5), so the setting does not concern the agents.
- Rate limits and the audit log use the client address that this setting determines.
- `tests/Feature/Platform/TrustedProxiesTest.php` covers the default (trust none), a trusted proxy, an untrusted address that sends forwarded headers, a forged address ahead of the proxy's, a chain of trusted proxies, the headers that are not read, separate rate limits behind one proxy, and a refused setting.
