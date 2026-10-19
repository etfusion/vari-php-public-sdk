# V-ARI PHP SDK

PHP client for the **V-ARI Stable API**: push metrics and custom lists to your
V-ARI boards, and rotate your client certificate, authenticated by mutual TLS.

```bash
composer require etfusion/vari-php-sdk
```

Requires PHP 8.1+ with the `openssl` extension (and `curl`, recommended).

## Getting a certificate

The Stable API authenticates with a client certificate instead of a token. A
tenant admin creates an API client for you in V-ARI and gives you two files:

- `client.crt` — the client certificate (PEM)
- `client.key` — its private key (PEM)

Keep the key private, e.g. readable only by the user your integration runs as.
Certificates last 90 days; see [Rotating the certificate](#rotating-the-certificate).

## Quick start

```php
use ETFusion\VARI\Client;

$vari = new Client('/etc/vari/client.crt', '/etc/vari/client.key');

$identity = $vari->identity();
echo $identity->client->name, ' on tenant ', $identity->client->tenantId, PHP_EOL;
print_r($identity->scopes); // e.g. ["stream:read", "stream:write"]
```

The client talks to production by default. Pass a base URL for another environment:

```php
use ETFusion\VARI\Environment;

$vari = new Client($cert, $key, Environment::STAGING);
```

A certificate only works in the environment that issued it.

### Options

| Argument | Default | |
|---|---|---|
| `baseUrl` | `Environment::PRODUCTION` | |
| `privateKeyPassphrase` | `null` | For an encrypted key. |
| `tenantId` | looked up | Your tenant. Fetched once with `identity()` when omitted. |
| `timeout` | `30.0` | Seconds. |
| `newCertificateRetrySeconds` | `15` | See [Retries](#retries). `0` disables. |
| `handler` | Guzzle default | A Guzzle handler or `HandlerStack`, to add middleware (logging, retries). |
| `httpOptions` | `[]` | Extra [Guzzle request options](https://docs.guzzlephp.org/en/stable/request-options.html), e.g. `proxy`. |

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

Rotate well before the certificate expires. `CertificateRotation::run()` does
the whole flow:

1. generates a new key and CSR locally (the private key never leaves your machine),
2. has V-ARI sign it,
3. replaces your certificate and key files,
4. confirms the rotation with the new certificate, which disables the old one.

```php
use ETFusion\VARI\CertificateRotation;

if ($vari->certificateExpiresWithin(30)) {
    $vari = CertificateRotation::run($vari)->client;
}
```

Run it from the job that already uses the client, or a daily cron. The
process needs write access to the certificate and key files (or pass other
paths: `CertificateRotation::run($vari, $newCertPath, $newKeyPath)`).

If several servers share one certificate, rotate on one of them and copy the
new files to the others promptly: confirming the rotation stops the old
certificate working immediately. To keep the old certificate working through
its grace period (24 hours by default) instead, call the steps yourself and
skip the confirm:

```php
use ETFusion\VARI\KeyPair;

$pair = KeyPair::generate();
$issued = $vari->certificates()->rotate($pair->csrPem);
// Save $issued->certificatePem and $pair->privateKeyPem, distribute them,
// then optionally confirm from a client using the new certificate:
$new = $vari->withCertificate($newCertPath, $newKeyPath);
$new->certificates()->confirmRotation();
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
| `ConfigurationException` | Certificate or key file missing or unreadable. |

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

Releases are tagged `vX.Y.Z`; Packagist picks up new tags automatically.

## License

MIT. See [LICENSE](LICENSE).
