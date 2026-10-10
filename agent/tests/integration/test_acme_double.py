# SPDX-License-Identifier: Apache-2.0
"""Pebble issues a certificate for a test hostname.

The challenge is DNS-01, answered through the mock DNS server, so the test
uses both doubles and needs no web server. This is not domain verification:
that belongs to Slice 3.
"""

import ssl
from pathlib import Path

import httpx
import pytest
from acme import challenges, client, messages
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec, rsa
from josepy.jwk import JWKRSA

from tests.support.doubles import PEBBLE_DIRECTORY, PEBBLE_MANAGEMENT

pytestmark = pytest.mark.doubles

_HOSTNAME = "cert.example.test"
_CHALLENGE_RECORD = f"_acme-challenge.{_HOSTNAME}."


def _signing_request(hostname: str) -> bytes:
    key = ec.generate_private_key(ec.SECP256R1())
    request = (
        x509.CertificateSigningRequestBuilder()
        .subject_name(x509.Name([]))
        .add_extension(x509.SubjectAlternativeName([x509.DNSName(hostname)]), critical=False)
        .sign(key, hashes.SHA256())
    )
    return request.public_bytes(serialization.Encoding.PEM)


def test_pebble_issues_a_certificate_for_a_test_hostname(
    pebble_ca: Path, challenge_api: httpx.Client, monkeypatch: pytest.MonkeyPatch
) -> None:
    # The ACME client uses requests, which reads its CA bundle from here.
    monkeypatch.setenv("REQUESTS_CA_BUNDLE", str(pebble_ca))
    account_key = JWKRSA(key=rsa.generate_private_key(public_exponent=65537, key_size=2048))
    network = client.ClientNetwork(account_key, user_agent="hostmesh-tests")
    directory = messages.Directory.from_json(network.get(PEBBLE_DIRECTORY).json())
    acme = client.ClientV2(directory, net=network)
    acme.new_account(messages.NewRegistration.from_data(terms_of_service_agreed=True))

    order = acme.new_order(_signing_request(_HOSTNAME))
    (authorization,) = order.authorizations
    challenge = next(
        candidate
        for candidate in authorization.body.challenges
        if isinstance(candidate.chall, challenges.DNS01)
    )
    response, validation = challenge.response_and_validation(account_key)
    challenge_api.post(
        "/set-txt", json={"host": _CHALLENGE_RECORD, "value": validation}
    ).raise_for_status()
    try:
        acme.answer_challenge(challenge, response)
        issued = acme.poll_and_finalize(order)
    finally:
        challenge_api.post("/clear-txt", json={"host": _CHALLENGE_RECORD}).raise_for_status()

    leaf, *intermediates = x509.load_pem_x509_certificates(issued.fullchain_pem.encode())
    names = leaf.extensions.get_extension_for_class(x509.SubjectAlternativeName).value
    assert names.get_values_for_type(x509.DNSName) == [_HOSTNAME]

    # Pebble makes a new root at every start and serves it on its management port.
    trust = ssl.create_default_context(cafile=str(pebble_ca))
    root_pem = httpx.get(f"{PEBBLE_MANAGEMENT}/roots/0", verify=trust).content
    root = x509.load_pem_x509_certificate(root_pem)
    assert intermediates, "Pebble sent no intermediate certificate"
    leaf.verify_directly_issued_by(intermediates[0])
    intermediates[-1].verify_directly_issued_by(root)
