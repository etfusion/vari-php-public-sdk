# Changelog

## 1.0.3 — Unreleased

- `Credentials::fromPem()` refuses an encrypted key given without its passphrase straight away. OpenSSL used to ask for the passphrase on the terminal and wait.

## 1.0.2 — 2026-10-21

- `CertificateRotation::run()` loads the new credentials back from the store before confirming, so the old certificate is never disabled while the store holds something the next process cannot read.
- `FileCredentialStore` locks a `.lock` file around `load()` and `save()`, so concurrent processes never read a half-replaced pair, and refuses to save a key its passphrase cannot read.
- `FileCredentialStore` recovery of an interrupted save throws if it cannot move a file, keeping the old key in `.old` until the certificate is installed; the next `load()` retries.
- The AWS Secrets Manager example store takes the key's passphrase and refuses keys it could not load.

## 1.0.1 — 2026-10-21

Breaking changes to 1.0.0, released as a patch because 1.0.0 had no users.

- The client takes `Credentials` instead of file paths: `new Client(Credentials::fromPem($certificatePem, $privateKeyPem))`. Load them from your secret manager; cURL gets them from memory and nothing is written to disk. `withCertificate()`, `certificatePath()` and `privateKeyPath()` are replaced by `withCredentials()` and `credentials()`.
- `CertificateRotation::run($client, $store)` saves the new certificate and key through a `CredentialStore` you implement, instead of writing files. If saving fails, `RotationException` carries the new credentials so they are not lost.
- `FileCredentialStore` and `Credentials::fromFiles()` for local development. The store puts the old key back if the certificate cannot be installed, and `load()` finishes a save that was interrupted part way.
- `ext-curl` is now required. Guzzle's stream handler cannot present a separate key, so authentication never worked without it.
- Keys and row identifiers that are empty, `.` or `..` throw `InvalidArgumentException`. They used to address a different endpoint.
- The sync example deletes closed incidents before writing new ones, so a full list has room.

## 1.0.0 — 2026-10-19

- Client for the V-ARI Stable API over mutual TLS.
- Identity, certificate rotation (`CertificateRotation::run`) and local key/CSR generation.
- Stream metrics: list, create, update, set value, delete.
- Custom lists (up to 50 rows each): list, get, create, update, delete, clear, and row list/upsert/batch upsert/delete.
