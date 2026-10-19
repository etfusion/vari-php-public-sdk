<?php

declare(strict_types=1);

namespace ETFusion\VARI;

use ETFusion\VARI\Exception\ConfigurationException;
use ETFusion\VARI\Internal\CertificateFile;
use ETFusion\VARI\Internal\Transport;
use ETFusion\VARI\Model\Identity;
use ETFusion\VARI\Resource\Certificates;
use ETFusion\VARI\Resource\Lists;
use ETFusion\VARI\Resource\Metrics;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;

/**
 * Client for the V-ARI Stable API, authenticated by a client certificate
 * (mutual TLS).
 *
 *     $vari = new Client('/etc/vari/client.crt', '/etc/vari/client.key');
 *     $vari->metrics()->setValue('open-tickets', 42);
 */
final class Client
{
    public const VERSION = '1.0.0';

    /** A certificate's notBefore is set this many seconds before it is issued. */
    private const ISSUE_BACKDATE_SECONDS = 60;

    private readonly Transport $transport;
    private readonly CertificateFile $certificate;
    private ?Identity $identity = null;

    /**
     * @param string $certificatePath PEM file holding the client certificate V-ARI issued.
     * @param string $privateKeyPath PEM file holding its private key.
     * @param string $baseUrl See {@see Environment}.
     * @param ?string $privateKeyPassphrase For an encrypted private key.
     * @param ?string $tenantId The certificate's tenant. Looked up with identity() on first use when omitted.
     * @param float $timeout Seconds to wait for a response.
     * @param int $newCertificateRetrySeconds A new certificate can take a few seconds to be accepted in every
     *                                        region; a 401 within this many seconds of issue is retried. 0 disables.
     * @param ?callable $handler Guzzle handler or HandlerStack, e.g. to add middleware or mock responses.
     * @param array<string, mixed> $httpOptions Extra Guzzle request options, e.g. `proxy` or `verify`.
     */
    public function __construct(
        private readonly string $certificatePath,
        private readonly string $privateKeyPath,
        private readonly string $baseUrl = Environment::PRODUCTION,
        private readonly ?string $privateKeyPassphrase = null,
        private readonly ?string $tenantId = null,
        private readonly float $timeout = 30.0,
        private readonly int $newCertificateRetrySeconds = 15,
        private readonly mixed $handler = null,
        private readonly array $httpOptions = [],
    ) {
        if (!is_readable($privateKeyPath)) {
            throw new ConfigurationException(sprintf('Cannot read private key "%s".', $privateKeyPath));
        }
        if ($handler !== null && !is_callable($handler)) {
            throw new ConfigurationException('$handler must be a Guzzle handler or HandlerStack.');
        }
        $this->certificate = CertificateFile::read($certificatePath);

        $cert = $privateKeyPassphrase !== null ? [$certificatePath, $privateKeyPassphrase] : $certificatePath;
        $key = $privateKeyPassphrase !== null ? [$privateKeyPath, $privateKeyPassphrase] : $privateKeyPath;

        $http = new GuzzleClient(array_replace(
            [
                'timeout' => $timeout,
                'connect_timeout' => min($timeout, 10.0),
                'headers' => ['User-Agent' => 'vari-php-sdk/' . self::VERSION . ' php/' . PHP_VERSION],
            ],
            $httpOptions,
            [
                'base_uri' => rtrim($baseUrl, '/') . '/',
                'cert' => $cert,
                'ssl_key' => $key,
                'http_errors' => false,
                'handler' => $handler ?? HandlerStack::create(),
            ],
        ));

        $this->transport = new Transport(
            $http,
            $this->certificate->notBefore->add(new \DateInterval('PT' . self::ISSUE_BACKDATE_SECONDS . 'S')),
            $newCertificateRetrySeconds,
        );
    }

    /**
     * The same client presenting a different certificate, e.g. after rotation.
     */
    public function withCertificate(
        string $certificatePath,
        string $privateKeyPath,
        #[\SensitiveParameter] ?string $privateKeyPassphrase = null,
    ): self {
        return new self(
            $certificatePath,
            $privateKeyPath,
            $this->baseUrl,
            $privateKeyPassphrase,
            $this->tenantId ?? $this->identity?->client->tenantId,
            $this->timeout,
            $this->newCertificateRetrySeconds,
            $this->handler,
            $this->httpOptions,
        );
    }

    /**
     * Describes the presented certificate, its client and the client's scopes.
     * Cached after the first call unless `$refresh` is set.
     */
    public function identity(bool $refresh = false): Identity
    {
        if ($this->identity === null || $refresh) {
            /** @var array<string, mixed> $data */
            $data = $this->transport->request('GET', 'identity');
            $this->identity = Identity::fromArray($data);
        }

        return $this->identity;
    }

    /**
     * The tenant the certificate belongs to.
     */
    public function tenantId(): string
    {
        return $this->tenantId ?? $this->identity()->client->tenantId;
    }

    public function certificates(): Certificates
    {
        return new Certificates($this->transport);
    }

    public function metrics(): Metrics
    {
        return new Metrics($this->transport, $this->tenantId(...));
    }

    public function lists(): Lists
    {
        return new Lists($this->transport, $this->tenantId(...));
    }

    public function certificatePath(): string
    {
        return $this->certificatePath;
    }

    public function privateKeyPath(): string
    {
        return $this->privateKeyPath;
    }

    /**
     * When the presented certificate expires, read from the certificate file.
     */
    public function certificateExpiresAt(): \DateTimeImmutable
    {
        return $this->certificate->notAfter;
    }

    /**
     * Whether the certificate expires within `$days` days and should be rotated.
     */
    public function certificateExpiresWithin(int $days): bool
    {
        return $this->certificate->notAfter <= new \DateTimeImmutable(sprintf('+%d days', $days));
    }
}
