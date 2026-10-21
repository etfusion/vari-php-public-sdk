# V-ARI PHP SDK

PHP client for the **V-ARI Stable API**: push metrics and custom lists to your
V-ARI boards, and rotate your client certificate, authenticated by mutual TLS.

```bash
composer require etfusion/vari-php-sdk
```

Requires PHP 8.1+ with the `openssl` and `curl` extensions (libcurl 7.71 or later).

## Getting a certificate

The Stable API authenticates with a client certificate instead of a token. A
tenant admin creates an API client for you in V-ARI and gives you the client
certificate and its private key, both PEM.

Put them in your secret manager (Vault, AWS Secrets Manager, GCP Secret
Manager, ...) rather than in files on the server, ideally together in one
secret. The SDK takes them from memory and hands them to cURL without writing
them to disk. Certificates last 90 days; see
[Rotating the certificate](#rotating-the-certificate).

## Quick start

```php
use ETFusion\VARI\Client;
use ETFusion\VARI\Credentials;

$vari = new Client(Credentials::fromPem($certificatePem, $privateKeyPem));

$identity = $vari->identity();
echo $identity->client->name, ' on tenant ', $identity->client->tenantId, PHP_EOL;
print_r($identity->scopes); // e.g. ["stream:read", "stream:write"]
```

`Credentials::fromPem()` checks that the key belongs to the certificate, and
takes a third argument for an encrypted key's passphrase. `var_dump()` and
`print_r()` show the key redacted.

The client talks to production by default. Pass a base URL for another environment:

```php
use ETFusion\VARI\Environment;

$vari = new Client($credentials, Environment::STAGING);
```

A certificate only works in the environment that issued it.

### Options

| Argument | Default | |
|---|---|---|
| `baseUrl` | `Environment::PRODUCTION` | |
| `tenantId` | looked up | Your tenant. Fetched once with `identity()` when omitted. |
| `timeout` | `30.0` | Seconds. |
| `newCertificateRetrySeconds` | `15` | See [Retries](#retries). `0` disables. |
| `handler` | Guzzle default | A Guzzle handler or `HandlerStack`, to add middleware (logging, retries). |
| `httpOptions` | `[]` | Extra [Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html), e.g. `proxy`. |

### Local development

`Credentials::fromFiles($certificatePath, $privateKeyPath)` reads PEM files,
and `FileCredentialStore` keeps rotated credentials in them (key mode 0600).
Use a secret manager in production: a key in a file tends to get copied,
backed up and left readable.

## Custom lists

A custom list is a set of rows your system keeps up to date, shown by
*customList* widgets on the tenant's boards. Once you create a list, a tenant
admin adds it to a board by choosing it in the customList widget.

```php
use ETFusion\VARI\Model\ListRow;

$list = $vari->lists()->create(
    'incidents',                  // key: ^[a-z0-9][a-z0-9_-]{0,63}$
    'Open incidents',             // title shown on the board
    badges: ['P1' => '#D32F2F', 'P2' => '#F57C00'],
    showRelativeDate: true,       // rows show "5 min ago"
);

$vari->lists()->upsertRow('incidents', new ListRow(
    externalUniqueIdentifier: 'INC-1042',   // your ID; writing it again replaces the row
    title: 'Printer on fire',
    displayIdentifier: 'INC-1042',
    subtitles: ['Floor 3', 'Reported by front desk'],
    timestamp: new DateTimeImmutable('-25 minutes'),
    badge: 'P1',
    rank: 1,                      // optional: lower ranks show first
));
```

Rules the API enforces:

- If a list has `badges`, every row must have a `badge` from that set.
- If `showRelativeDate` is on, every row must have a `timestamp`.
- A change to a list that would break those rules for existing rows is
  refused with `ConflictException`; update or clear the rows first.
- A list holds at most 50 rows, and a client can have at most 50 lists.
- Writing a row replaces it entirely: any field you leave out is cleared.

| Method | |
|---|---|
| `lists()->list()` | Your lists, without rows. |
| `lists()->get($key)` | One list, without rows. |
| `lists()->create($key, $title, $badges = [], $showRelativeDate = false)` | |
| `lists()->update($key, title:, badges:, showRelativeDate:)` | Omitted arguments are unchanged. `badges: []` removes all badges. |
| `lists()->delete($key)` | Deletes the list and its rows. |
| `lists()->clear($key)` | Removes every row, keeps the list. |
| `lists()->rows($key)` | Rows in display order. |
| `lists()->upsertRow($key, ListRow $row)` | Create or replace one row. |
| `lists()->upsertRows($key, iterable $rows)` | Create or replace many rows. |
| `lists()->deleteRow($key, $externalUniqueIdentifier)` | |

`upsertRows()` writes up to 50 rows in one all-or-nothing request: one
invalid row rejects them all. Passing more than 50 throws
`InvalidArgumentException` without calling the API, and a write that would
leave the list holding more than 50 rows gets `ConflictException`.

### Display order

Ranked rows come first, lowest rank first; then the newest `timestamp`; then
the order rows were first written in.

## Metrics

A metric is a single value your system updates, shown by *metric* widgets.

```php
$vari->metrics()->create('open-tickets', 'Open tickets', unit: 'count', value: 0);

$vari->metrics()->setValue('open-tickets', 42);   // int, float, string (≤256 bytes), bool or null
```

| Method | |
|---|---|
| `metrics()->list()` | Your metrics with their current values. |
| `metrics()->create($key, $label, description:, unit:, value:)` | At most 100 per client. |
| `metrics()->update($key, label:, description:, unit:)` | Omitted arguments are unchanged; `''` clears description or unit. |
| `metrics()->setValue($key, $value)` | `null` clears the value. |
| `metrics()->delete($key)` | |

Boards showing a list or metric refresh within seconds of every write.
Deleting one leaves it on boards marked as deleted until an admin removes the
widget.

## Rotating the certificate

Rotate well before the certificate expires. `CertificateRotation::run()`
does the whole flow:

1. generates a new key and CSR locally (the private key never leaves your process),
2. has V-ARI sign it,
3. saves the new certificate and key to your credential store,
4. confirms the rotation with the new certificate, which disables the old one.

```php
use ETFusion\VARI\CertificateRotation;

if ($vari->certificateExpiresWithin(30)) {
    $vari = CertificateRotation::run($vari, $store)->client;
}
```

Run it from the job that already uses the client, or a daily cron.

### Credential stores

A store is how rotation saves the new credentials. Implement
`ETFusion\VARI\CredentialStore` over your secret manager:

```php
interface CredentialStore
{
    public function load(): Credentials;
    public function save(Credentials $credentials): void;
}
```

`save()` must replace the certificate and key together, so nothing ever reads
the new certificate with the old key. Keeping both in one secret does that.
[examples/AwsSecretsManagerStore.php](examples/AwsSecretsManagerStore.php)
is a complete store for AWS Secrets Manager, and
[examples/sync-incidents.php](examples/sync-incidents.php) uses it.

If `save()` fails, `run()` throws `RotationException` without
confirming. The new certificate is already issued and the old one cannot be
rotated again, but keeps working until `$e->issued->previousValidUntil` (24
hours by default). The exception holds the new credentials in
`$e->credentials`: save them some other way before then, or ask a tenant admin
for a new certificate.

If confirming fails, the new credentials are already saved and the old
certificate keeps working until its grace period ends; confirm later with a
client for the new credentials.

### Sharing a certificate

If several servers share one certificate, have them all load it from the same
store: confirming the rotation stops the old certificate working immediately.
To keep the old certificate working through its grace period instead, call the
steps yourself and skip the confirm:

```php
use ETFusion\VARI\Credentials;
use ETFusion\VARI\KeyPair;

$pair = KeyPair::generate();
$issued = $vari->certificates()->rotate($pair->csrPem);
$credentials = Credentials::fromPem($issued->certificatePem, $pair->privateKeyPem);
$store->save($credentials);
// Once every server has picked them up, optionally:
$vari->withCredentials($credentials)->certificates()->confirmRotation();
```

A certificate can only be rotated once; rotating it again gets
`ConflictException`.

## Errors

Every exception implements `ETFusion\VARI\Exception\VariException`.

| Exception | When |
|---|---|
| `ValidationException` | 400/422: invalid input. |
| `AuthenticationException` | 401: certificate unknown, revoked, or past its grace period. |
| `PermissionDeniedException` | 403: the client's role lacks the scope (`stream:read` / `stream:write`). |
| `NotFoundException` | 404: no such list, row or metric. |
| `ConflictException` | 409: key in use, limit reached, or a list change that breaks its rows. |
| `InvalidArgumentException` | Input the API would refuse, caught before sending (e.g. more than 50 rows). |
| `RateLimitException` | 429. |
| `ServerException` | 5xx. |
| `ApiException` | Base class of the above: `getStatusCode()`, `getErrorCode()`, `getMessage()`. |
| `TransportException` | No HTTP response: network, timeout, or a TLS handshake the server rejected (e.g. an expired certificate). |
| `ConfigurationException` | Certificate or key unusable: not PEM, wrong passphrase, not a matching pair, or a file missing. |
| `RotationException` | A new certificate was issued but the credential store could not save it. See [Credential stores](#credential-stores). |

```php
use ETFusion\VARI\Exception\ConflictException;

try {
    $vari->lists()->update('incidents', badges: []);
} catch (ConflictException $e) {
    echo $e->getMessage();
}
```

## Retries

A newly issued certificate can take a few seconds to be accepted in every
region. The client retries a 401 once a second while the certificate is less
than `newCertificateRetrySeconds` old. It does not retry anything else; add
Guzzle's retry middleware through `handler` if you want that.

## Development

```bash
composer install
composer test
composer analyse
```

To release, bump `Client::VERSION` and date its `CHANGELOG.md` entry
(`## X.Y.Z — YYYY-MM-DD`) in one pull request; CI fails until the entry is
dated. After it merges, tag `vX.Y.Z` on `main`; Packagist picks up new tags
automatically.

## License

MIT. See [LICENSE](LICENSE).
