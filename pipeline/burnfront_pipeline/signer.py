"""Ed25519 manifest signing (WS-05).

Chain of trust: detached signature (``<manifest>.sig``, raw 64 bytes, over
the exact manifest bytes) -> the manifest's sha256 file map -> files.
Only manifests are signed (calendar + pack manifests).

The private key is read from the path in ``BURNFRONT_SIGNING_KEY`` (or an
explicit path argument). Key file format: '#' comment lines, then the
32-byte Ed25519 seed as 64 hex characters. The committed
tests/fixtures/dev-signing-key is a DEV key generated for tests only —
production keys live in Forge and never enter the repo (CLAUDE.md rule 5).

PHP verifies with sodium_crypto_sign_verify_detached(sig, bytes, pubkey)
(WS-07); the public key is the 32-byte value in ``<key>.pub`` (hex).
"""

import os

from nacl.exceptions import BadSignatureError
from nacl.signing import SigningKey, VerifyKey

ENV_VAR = "BURNFRONT_SIGNING_KEY"


class SigningError(Exception):
    pass


def _read_hex_file(path):
    with open(path, "r", encoding="ascii") as f:
        payload = "".join(
            line.strip() for line in f
            if line.strip() and not line.lstrip().startswith("#"))
    try:
        return bytes.fromhex(payload)
    except ValueError as exc:
        raise SigningError(f"{path}: not a hex key file") from exc


def load_signing_key(path=None):
    """Load the Ed25519 signing key from `path` or $BURNFRONT_SIGNING_KEY."""
    path = path or os.environ.get(ENV_VAR)
    if not path:
        raise SigningError(
            f"no signing key: pass --key or set {ENV_VAR} to a key file path")
    seed = _read_hex_file(path)
    if len(seed) != 32:
        raise SigningError(f"{path}: expected a 32-byte seed, got {len(seed)}")
    return SigningKey(seed)


def load_verify_key(path):
    """Load a verify key from a .pub file (32 bytes hex)."""
    raw = _read_hex_file(path)
    if len(raw) != 32:
        raise SigningError(f"{path}: expected a 32-byte public key")
    return VerifyKey(raw)


def sign_bytes(data, signing_key):
    """Detached Ed25519 signature (raw 64 bytes) over exact bytes."""
    return signing_key.sign(data).signature


def verify_bytes(data, signature, verify_key):
    """True iff `signature` is a valid detached signature over `data`."""
    try:
        verify_key.verify(data, signature)
        return True
    except BadSignatureError:
        return False


def sign_manifest(manifest_path, signing_key):
    """Write <manifest>.sig next to the manifest. Returns the sig path."""
    with open(manifest_path, "rb") as f:
        data = f.read()
    sig_path = str(manifest_path) + ".sig"
    with open(sig_path, "wb") as f:
        f.write(sign_bytes(data, signing_key))
    return sig_path


def verify_manifest(manifest_path, verify_key, sig_path=None):
    """Verify a manifest's detached signature. Returns True/False."""
    sig_path = sig_path or str(manifest_path) + ".sig"
    with open(manifest_path, "rb") as f:
        data = f.read()
    with open(sig_path, "rb") as f:
        signature = f.read()
    if len(signature) != 64:
        return False
    return verify_bytes(data, signature, verify_key)
