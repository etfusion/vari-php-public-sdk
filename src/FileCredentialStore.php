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
 *
 * load() and save() hold an exclusive lock on a `.lock` file next to the key,
 * so concurrent processes never read a half-replaced pair. flock() is
 * unreliable on some network filesystems; keep the files on a local disk.
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
        return $this->locked(function (): Credentials {
            $this->finishInterruptedSave();

            return Credentials::fromFiles($this->certificatePath, $this->privateKeyPath, $this->passphrase);
        });
    }

    /**
     * @throws ConfigurationException This store's passphrase cannot read the key, or a file cannot be written.
     */
    public function save(Credentials $credentials): void
    {
        // Installing a key load() cannot read would strand the new certificate.
        Credentials::fromPem($credentials->certificatePem, $credentials->privateKeyPem, $this->passphrase);

        $this->locked(function () use ($credentials): void {
            $this->replace($credentials);
        });
    }

    private function replace(Credentials $credentials): void
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
     * Runs `$operation` holding the store's lock.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function locked(\Closure $operation): mixed
    {
        $path = $this->privateKeyPath . '.lock';
        $lock = @fopen($path, 'c');
        if ($lock === false) {
            throw new ConfigurationException(sprintf('Cannot open lock file "%s".', $path));
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new ConfigurationException(sprintf('Cannot lock "%s".', $path));
            }

            return $operation();
        } finally {
            // Closing releases the lock. The file stays: deleting it would let two processes lock different files.
            fclose($lock);
        }
    }

    /**
     * Completes a save() whose process stopped part way, so the key and
     * certificate on disk match again. The old key stays in `.old` until the
     * certificate is installed; a failed step throws and is retried by the
     * next load().
     *
     * @throws ConfigurationException A file could not be moved into place.
     */
    private function finishInterruptedSave(): void
    {
        $stagedCertificate = $this->certificatePath . '.new';
        $stagedKey = $this->privateKeyPath . '.new';
        $oldKey = $this->privateKeyPath . '.old';
        if (!is_file($stagedCertificate)) {
            return;
        }
        $certificate = (string) @file_get_contents($stagedCertificate);

        // Stopped before installing the key, after both were fully staged.
        if (is_file($stagedKey) && $this->matches($certificate, $stagedKey)) {
            // save() may have stopped before backing up the old key.
            if (!is_file($oldKey) && is_file($this->privateKeyPath)) {
                $old = @file_get_contents($this->privateKeyPath);
                if ($old === false) {
                    throw new ConfigurationException(sprintf('Cannot read "%s".', $this->privateKeyPath));
                }
                self::write($oldKey, $old, 0600);
            }
            self::move($stagedKey, $this->privateKeyPath, $oldKey);
        }
        // Stopped between installing the key and the certificate.
        if ($this->matches($certificate, $this->privateKeyPath)) {
            self::move($stagedCertificate, $this->certificatePath, $oldKey);
            @unlink($oldKey);
        }
    }

    private static function move(string $from, string $to, string $oldKey): void
    {
        if (!@rename($from, $to)) {
            throw new ConfigurationException(sprintf(
                'Cannot finish an interrupted save: cannot move "%s" to "%s". The old key is in "%s".',
                $from,
                $to,
                $oldKey,
            ));
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
