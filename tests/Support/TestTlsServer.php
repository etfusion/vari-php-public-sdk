<?php

declare(strict_types=1);

namespace ETFusion\VARI\Tests\Support;

/**
 * Runs tls-server.php in a child process, listening on 127.0.0.1 only.
 */
final class TestTlsServer
{
    public readonly string $baseUrl;

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    /**
     * @param TestCa $ca Issues the server certificate and is the only CA trusted for client certificates.
     */
    public function __construct(TestCa $ca)
    {
        $server = $ca->issueServer();
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/tls-server.php', $server['certificatePath'], $server['privateKeyPath'], $ca->certificatePath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        \assert(is_resource($process));
        $this->process = $process;
        $this->pipes = $pipes;

        $read = [$pipes[1]];
        $write = $except = null;
        $address = stream_select($read, $write, $except, 10) === 1 ? trim((string) fgets($pipes[1])) : '';
        if ($address === '') {
            // Its error output is complete only once it has exited; reading earlier would block.
            $error = proc_get_status($process)['running'] ? 'no address within 10 seconds' : stream_get_contents($pipes[2]);
            $this->stop();
            throw new \RuntimeException('The TLS test server did not start: ' . $error);
        }
        $this->baseUrl = 'https://' . $address;
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        proc_terminate($this->process);
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->process);
    }
}
