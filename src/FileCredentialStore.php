<?php

declare(strict_types=1);

namespace ETFusion\VARI;

use ETFusion\VARI\Exception\ConfigurationException;

/**
 * Keeps credentials in a PEM certificate file and a PEM key file (mode 0600).
 *
 * For local development and hosts without a secret manager; prefer a
 * {@see CredentialStore} over your secret manager in production.
 *
 * Two files cannot be replaced at once, so save() installs the key, then the
 * certificate, and puts the old key back if the second step fails. If the
 * process stops between the two, the staged certificate is left beside the
 * old one and load() finishes the install.
 */
final class FileCredentialStore implements CredentialStore
{
    private readonly ?string $passphrase;

    /**
     * @param ?string $passphrase For an encrypted private key.
     */
    public function __construct(
        private readonly string $certificatePath,
        private readonly string $privateKeyPath,
        #[\SensitiveParameter] ?string $passphrase = null,
    ) {
        $this->passphrase = $passphrase;
    }

    public function load(): Credentials
    {
        $this->finishInterruptedSave();

        return Credentials::fromFiles($this->certificatePath, $this->privateKeyPath, $this->passphrase);
    }

    public function save(Credentials $credentials): void
    {
        $stagedKey = $this->privateKeyPath . '.new';
        $stagedCertificate = $this->certificatePath . '.new';
        $oldKey = $this->privateKeyPath . '.old';

        $hadKey = is_file($this->privateKeyPath);
        try {
            self::write($stagedKey, $credentials->privateKeyPem, 0600);
            self::write($stagedCertificate, $credentials->certificatePem, 0644);
            if ($hadKey) {
                $old = @file_get_contents($this->privateKeyPath);
                if ($old === false) {
                    throw new ConfigurationException(sprintf('Cannot read "%s".', $this->privateKeyPath));
                }
                self::write($oldKey, $old, 0600);
            }
        } catch (ConfigurationException $e) {
            @unlink($stagedKey);
            @unlink($stagedCertificate);
            @unlink($oldKey);
            throw $e;
        }

        if (!@rename($stagedKey, $this->privateKeyPath)) {
            @unlink($stagedKey);
            @unlink($stagedCertificate);
            @unlink($oldKey);
            throw new ConfigurationException(sprintf('Cannot move "%s" to "%s".', $stagedKey, $this->privateKeyPath));
        }

        if (!@rename($stagedCertificate, $this->certificatePath)) {
            // Put the old key back so it matches the old certificate again.
            $restored = $hadKey ? @rename($oldKey, $this->privateKeyPath) : @unlink($this->privateKeyPath);
            $message = sprintf('Cannot move "%s" to "%s".', $stagedCertificate, $this->certificatePath);
            if (!$restored) {
                // Leave the staged certificate for load() to install over the new key.
                throw new ConfigurationException($message . sprintf(' The old key is in "%s".', $oldKey));
            }
            @unlink($stagedCertificate);
            throw new ConfigurationException($message);
        }

        @unlink($oldKey);
    }

    /**
     * Completes a save() whose process stopped part way, so the key and
     * certificate on disk match again.
     */
    private function finishInterruptedSave(): void
    {
        $stagedCertificate = $this->certificatePath . '.new';
        $stagedKey = $this->privateKeyPath . '.new';
        if (!is_file($stagedCertificate)) {
            return;
        }
        $certificate = (string) @file_get_contents($stagedCertificate);

        // Stopped before installing the key, after both were fully staged.
        if (is_file($stagedKey) && $this->matches($certificate, $stagedKey)) {
            @rename($stagedKey, $this->privateKeyPath);
        }
        // Stopped between installing the key and the certificate.
        if ($this->matches($certificate, $this->privateKeyPath)) {
            @rename($stagedCertificate, $this->certificatePath);
            @unlink($this->privateKeyPath . '.old');
        }
    }

    private function matches(string $certificatePem, string $privateKeyPath): bool
    {
        $privateKeyPem = @file_get_contents($privateKeyPath);
        if ($privateKeyPem === false) {
            return false;
        }

        try {
            Credentials::fromPem($certificatePem, $privateKeyPem, $this->passphrase);
        } catch (ConfigurationException) {
            return false;
        }

        return true;
    }

    private static function write(string $path, string $contents, int $mode): void
    {
        // Create the file empty and restrict it before the contents go in.
        if (@file_put_contents($path, '') === false || !@chmod($path, $mode)
            || @file_put_contents($path, $contents) === false) {
            @unlink($path);
            throw new ConfigurationException(sprintf('Cannot write "%s".', $path));
        }
    }
}
