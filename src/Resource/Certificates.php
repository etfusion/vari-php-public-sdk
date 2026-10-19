<?php

declare(strict_types=1);

namespace ETFusion\VARI\Resource;

use ETFusion\VARI\Internal\Transport;
use ETFusion\VARI\Model\RetiredCertificate;
use ETFusion\VARI\Model\RotatedCertificate;

/**
 * Self-service certificate rotation.
 *
 * For the whole flow (new key, rotate, install, confirm) see
 * {@see \ETFusion\VARI\CertificateRotation}.
 */
final class Certificates
{
    /**
     * @internal
     */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Issues a new certificate for this client and starts the presented
     * certificate's grace period.
     *
     * @param ?string $csrPem A PEM CERTIFICATE REQUEST (ECDSA P-256/P-384 or RSA >= 2048);
     *                        its subject and SANs are ignored. Omit to have V-ARI generate
     *                        the key pair, returned once in `privateKeyPem`.
     *
     * @throws \ETFusion\VARI\Exception\ConflictException The presented certificate was already rotated away from.
     */
    public function rotate(?string $csrPem = null): RotatedCertificate
    {
        $body = $csrPem !== null ? ['csr' => $csrPem] : [];

        /** @var array<string, mixed> $data */
        $data = $this->transport->request('POST', 'certificates/rotate', $body);

        return RotatedCertificate::fromArray($data);
    }

    /**
     * Call with the new certificate once it works: the certificate it replaced
     * stops being accepted immediately instead of at the end of its grace period.
     * Idempotent.
     *
     * @throws \ETFusion\VARI\Exception\ConflictException The presented certificate was not issued by rotation.
     */
    public function confirmRotation(): RetiredCertificate
    {
        /** @var array<string, mixed> $data */
        $data = $this->transport->request('POST', 'certificates/rotate/confirm');

        return RetiredCertificate::fromArray($data);
    }
}
