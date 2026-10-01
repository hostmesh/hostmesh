#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Prints the root certificate of Caddy's local CA, read from the running
# stack. With --apply, installs it in the system trust store (asks for
# sudo). Run it again after `make reset`: a reset creates a new CA.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

ROOT_CERT_PATH=/data/caddy/pki/authorities/local/root.crt
ANCHOR_NAME=hostmesh-development-ca.crt

root_cert() {
    if ! docker compose exec -T caddy cat "$ROOT_CERT_PATH"; then
        echo "Could not read the CA certificate. Is the stack up? Run: make up" >&2
        return 1
    fi
}

case "${1:-}" in
    "")
        root_cert
        ;;
    --apply)
        cert=$(root_cert)
        if [ -d /etc/pki/ca-trust/source/anchors ]; then
            # Fedora, RHEL and derivatives
            anchor="/etc/pki/ca-trust/source/anchors/${ANCHOR_NAME}"
            refresh=update-ca-trust
        elif [ -d /usr/local/share/ca-certificates ]; then
            # Debian, Ubuntu and derivatives
            anchor="/usr/local/share/ca-certificates/${ANCHOR_NAME}"
            refresh=update-ca-certificates
        else
            echo "Unsupported distribution. Run $0 without arguments and install the printed certificate by hand." >&2
            exit 1
        fi
        printf '%s\n' "$cert" | sudo tee "$anchor" > /dev/null
        sudo "$refresh"
        echo "Installed ${anchor}"
        ;;
    *)
        echo "Usage: $0 [--apply]" >&2
        exit 2
        ;;
esac
