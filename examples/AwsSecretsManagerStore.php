<?php

/**
 * A CredentialStore over AWS Secrets Manager. The certificate and key live
 * together in one secret, as JSON:
 *
 *     {"certificate": "-----BEGIN CERTIFICATE-----\n...", "privateKey": "-----BEGIN PRIVATE KEY-----\n..."}
 *
 * Secrets Manager already encrypts the secret. If you also encrypt the key
 * (CertificateRotation::run(..., $passphrase)), give the store the same
 * passphrase from somewhere other than this secret.
 *
 * Needs `composer require aws/aws-sdk-php`, and secretsmanager:GetSecretValue
 * and secretsmanager:PutSecretValue on the secret.
 */

declare(strict_types=1);

use Aws\SecretsManager\SecretsManagerClient;
use ETFusion\VARI\CredentialStore;
use ETFusion\VARI\Credentials;

final class AwsSecretsManagerStore implements CredentialStore
{
    private readonly ?string $passphrase;

    public function __construct(
        private readonly SecretsManagerClient $secrets,
        private readonly string $secretId,
        #[\SensitiveParameter] ?string $passphrase = null,
    ) {
        $this->passphrase = $passphrase;
    }

    public function load(): Credentials
    {
        $result = $this->secrets->getSecretValue(['SecretId' => $this->secretId]);
        $secret = json_decode((string) $result['SecretString'], true, 512, JSON_THROW_ON_ERROR);

        return Credentials::fromPem($secret['certificate'], $secret['privateKey'], $this->passphrase);
    }

    public function save(Credentials $credentials): void
    {
        // Refuse a key load() could not decrypt, before replacing the one that works.
        Credentials::fromPem($credentials->certificatePem, $credentials->privateKeyPem, $this->passphrase);

        // One new secret version holds both, so a reader never sees a mismatched pair.
        $this->secrets->putSecretValue([
            'SecretId' => $this->secretId,
            'SecretString' => json_encode([
                'certificate' => $credentials->certificatePem,
                'privateKey' => $credentials->privateKeyPem,
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}
