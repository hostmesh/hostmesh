# SPDX-License-Identifier: Apache-2.0
"""The mock DNS server answers what its management API was told."""

import dns.resolver
import httpx
import pytest

from tests.support.doubles import DNS_HOST, DNS_PORT

pytestmark = pytest.mark.doubles

_HOST = "www.example.test"
_ADDRESS = "192.0.2.10"


def _resolver() -> dns.resolver.Resolver:
    # configure=False: never read /etc/resolv.conf, never ask a real resolver.
    resolver = dns.resolver.Resolver(configure=False)
    resolver.nameservers = [DNS_HOST]
    resolver.port = DNS_PORT
    resolver.lifetime = 5.0
    return resolver


def test_a_record_added_through_the_api_resolves(challenge_api: httpx.Client) -> None:
    resolver = _resolver()
    # Without this the test would pass against a server that answers every name.
    with pytest.raises((dns.resolver.NoAnswer, dns.resolver.NXDOMAIN)):
        resolver.resolve(_HOST, "A")

    challenge_api.post("/add-a", json={"host": _HOST, "addresses": [_ADDRESS]}).raise_for_status()
    try:
        answer = resolver.resolve(_HOST, "A")
    finally:
        challenge_api.post("/clear-a", json={"host": _HOST}).raise_for_status()

    assert [record.to_text() for record in answer] == [_ADDRESS]
