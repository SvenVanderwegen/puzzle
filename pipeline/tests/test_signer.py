"""Ed25519 signing chain (WS-05)."""

import pytest

from burnfront_pipeline import signer

from conftest import DEV_KEY, DEV_PUB


def test_dev_key_fixture_loads_and_matches_pub():
    sk = signer.load_signing_key(str(DEV_KEY))
    vk = signer.load_verify_key(str(DEV_PUB))
    assert bytes(sk.verify_key) == bytes(vk)


def test_dev_key_fixture_is_marked_dev():
    text = DEV_KEY.read_text()
    assert "DEV" in text and "NOT A SECRET" in text


def test_sign_verify_roundtrip(tmp_path):
    sk = signer.load_signing_key(str(DEV_KEY))
    vk = sk.verify_key
    manifest = tmp_path / "calendar.json"
    manifest.write_bytes(b'{"schema":"burnfront.calendar/1"}\n')
    sig_path = signer.sign_manifest(manifest, sk)
    assert (tmp_path / "calendar.json.sig").read_bytes() == \
        open(sig_path, "rb").read()
    assert len(open(sig_path, "rb").read()) == 64
    assert signer.verify_manifest(manifest, vk)


def test_tampered_manifest_fails_verification(tmp_path):
    sk = signer.load_signing_key(str(DEV_KEY))
    manifest = tmp_path / "m.json"
    manifest.write_bytes(b"{}\n")
    signer.sign_manifest(manifest, sk)
    manifest.write_bytes(b"{ }\n")
    assert not signer.verify_manifest(manifest, sk.verify_key)


def test_key_path_from_environment(monkeypatch):
    monkeypatch.setenv(signer.ENV_VAR, str(DEV_KEY))
    sk = signer.load_signing_key()
    assert bytes(sk.verify_key) == bytes(signer.load_verify_key(str(DEV_PUB)))


def test_missing_key_is_an_error(monkeypatch):
    monkeypatch.delenv(signer.ENV_VAR, raising=False)
    with pytest.raises(signer.SigningError):
        signer.load_signing_key()


def test_malformed_key_file_is_an_error(tmp_path):
    bad = tmp_path / "key"
    bad.write_text("# comment only\nzz-not-hex\n")
    with pytest.raises(signer.SigningError):
        signer.load_signing_key(str(bad))
    short = tmp_path / "short"
    short.write_text("abcd\n")
    with pytest.raises(signer.SigningError):
        signer.load_signing_key(str(short))
