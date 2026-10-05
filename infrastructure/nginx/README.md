# Nginx example

`hostmesh.conf.example` is a starting point for serving Hostmesh on the hostname layout of spec section 28. It is an example, not a managed file: copy it, replace `example.com` with your `HOSTMESH_DOMAIN`, and adjust the paths.

| Host                | Serves                         | Notes                                                              |
|---------------------|--------------------------------|--------------------------------------------------------------------|
| `app.<domain>`      | Customer portal (static build) | Sends the Content Security Policy.                                 |
| `admin.<domain>`    | Admin app (static build)       | Sends the Content Security Policy. A separate origin on purpose.   |
| `api.<domain>`      | Laravel API                    | CORS, sessions and CSRF are handled by the application.            |
| `agent.<domain>`    | Agent mTLS endpoint            | **Never behind a CDN** or a proxy that terminates TLS (see below). |

## The agent hostname is never behind a CDN

Agents authenticate with client certificates (mutual TLS, spec section 5). A CDN or reverse proxy that terminates TLS, for example Cloudflare with proxying switched on, ends the TLS session before it reaches Hostmesh, and the client certificate is lost. Give `agent.<domain>` a DNS record that points straight at the server ("DNS only"). The other three hostnames may sit behind a CDN.

## Content Security Policy

Both static apps ship a strict policy: no inline script or style, and `connect-src` limited to the API origin. The policy is defined once, in `control-plane/config/hostmesh.php`. `control-plane/tests/Feature/Platform/ContentSecurityPolicyTest.php` fails when the header in the example differs from it, so change the config first and copy the new value here.

To print the header value for your domain:

    docker compose -f infrastructure/development/compose.yaml exec -e XDG_CONFIG_HOME=/tmp php \
      php artisan tinker --execute="echo app(App\Support\ContentSecurityPolicy::class)->header('portal');"

`XDG_CONFIG_HOME` gives Tinker a writable configuration directory: the service runs as your user, which has no home directory in the container, and without it the command prints a warning and nothing else.

Keep `add_header` at server level. A `location` block with its own `add_header` drops the inherited ones.

## All four names come from one setting

`HOSTMESH_DOMAIN` in the control plane's `.env` is the only hostname setting. The names in this file must use the same domain.
