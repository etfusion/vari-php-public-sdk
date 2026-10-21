# Changelog

## 1.0.0 — 2026-10-21

- Client for the V-ARI Stable API over mutual TLS. The certificate and key are `Credentials` loaded from your secret manager, handed to cURL in memory and never written to disk.
- Identity, certificate rotation (`CertificateRotation::run`) and local key/CSR generation. Rotation saves the new certificate and key through a `CredentialStore` you implement over your secret manager. If saving fails, `RotationException` carries the new credentials.
- `FileCredentialStore` and `Credentials::fromFiles()` for local development.
- Stream metrics: list, create, update, set value, delete.
- Custom lists (up to 50 rows each): list, get, create, update, delete, clear, and row list/upsert/batch upsert/delete.
