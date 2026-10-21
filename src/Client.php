<?php

declare(strict_types=1);

namespace ETFusion\VARI;

use ETFusion\VARI\Exception\ConfigurationException;
use ETFusion\VARI\Internal\CertificateValidity;
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
 *     $vari = new Client(Credentials::fromPem($certificatePem, $privateKeyPem));
 *     $vari->metrics()->setValue('open-tickets', 42);
 */
final class Client
{
    public const VERSION = '1.0.1';

    /** A certificate's notBefore is set this many seconds before it is issued. */
    private const ISSUE_BACKDATE_SECONDS = 60;

    /** @var callable|null */
    private readonly mixed $handler;
    private readonly Transport $transport;
    private readonly CertificateValidity $certificate;
    private ?Identity $identity = null;

    /**
     * @param Credentials $credentials The client certificate and key, from your secret manager. They stay in memory.
     * @param string $baseUrl See {@see Environment}.
     * @param ?string $tenantId The certificate's tenant. Looked up with identity() on first use when omitted.
     * @param float $timeout Seconds to wait for a response.
     * @param int $newCertificateRetrySeconds A new certificate can take a few seconds to be accepted in every
     *                                        region; a 401 within this many seconds of issue is retried. 0 disables.
     * @param ?callable $handler Guzzle handler or HandlerStack, e.g. to add middleware or mock responses.
     * @param array<string, mixed> $httpOptions Extra Guzzle request options, e.g. `proxy` or `verify`.
     *
     * @throws ConfigurationException The curl extension cannot take a certificate from memory (libcurl < 7.71).
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly string $baseUrl = Environment::PRODUCTION,
        private readonly ?string $tenantId = null,
        private readonly float $timeout = 30.0,
        private readonly int $newCertificateRetrySeconds = 15,
        ?callable $handler = null,
        private readonly array $httpOptions = [],
    ) {
        if (!\defined('CURLOPT_SSLCERT_BLOB')) {
            throw new ConfigurationException('The curl extension must be built with libcurl 7.71 or later.');
        }
        $this->handler = $handler;
        $this->certificate = CertificateValidity::parse($credentials->certificatePem);

        // The certificate and key go to cURL from memory and are never written to disk.
        $curl = array_replace(
            is_array($httpOptions['curl'] ?? null) ? $httpOptions['curl'] : [],
            array_filter([
                \CURLOPT_SSLCERT_BLOB => $credentials->certificatePem,
                \CURLOPT_SSLCERTTYPE => 'PEM',
                \CURLOPT_SSLKEY_BLOB => $credentials->privateKeyPem,
                \CURLOPT_SSLKEYTYPE => 'PEM',
                \CURLOPT_KEYPASSWD => $credentials->passphrase,
            ], static fn (?string $value): bool => $value !== null),
        );

        $http = new GuzzleClient(array_replace(
            [
                'timeout' => $timeout,
                'connect_timeout' => min($timeout, 10.0),
                'headers' => ['User-Agent' => 'vari-php-sdk/' . self::VERSION . ' php/' . PHP_VERSION],
            ],
            $httpOptions,
            [
                'base_uri' => rtrim($baseUrl, '/') . '/',
                'curl' => $curl,
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
     * The same client presenting other credentials, e.g. after rotation.
     */
    public function withCredentials(Credentials $credentials): self
    {
        return new self(
            $credentials,
            $this->baseUrl,
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

    /**
     * The certificate and key this client presents.
     */
    public function credentials(): Credentials
    {
        return $this->credentials;
    }

    /**
     * When the presented certificate expires.
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
