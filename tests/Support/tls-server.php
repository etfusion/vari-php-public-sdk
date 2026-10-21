<?php

/**
 * A mutual-TLS HTTPS server on 127.0.0.1 for TestTlsServer. Prints its
 * address, then answers every request with an identity whose certificate
 * fingerprint is the client certificate it received, or 401 without one.
 *
 *     php tls-server.php <server.crt> <server.key> <client-ca.crt>
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use ETFusion\VARI\Tests\Fixtures;

[, $certificate, $privateKey, $clientCa] = $argv;

$context = stream_context_create(['ssl' => [
    'local_cert' => $certificate,
    'local_pk' => $privateKey,
    'cafile' => $clientCa,
    'verify_peer' => true,
    // Client certificates name a client, not a host.
    'verify_peer_name' => false,
    'capture_peer_cert' => true,
]]);

$server = stream_socket_server('tls://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, $error . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, stream_socket_get_name($server, false) . PHP_EOL);

while (true) {
    // Fails, and is skipped, when the handshake does: e.g. a certificate from another CA.
    $connection = @stream_socket_accept($server, -1);
    if ($connection === false) {
        continue;
    }

    while (($line = fgets($connection)) !== false && rtrim($line) !== '') {
        // Skip the request line and headers; every request gets the same answer.
    }

    $peer = stream_context_get_params($connection)['options']['ssl']['peer_certificate'] ?? null;
    if ($peer instanceof OpenSSLCertificate) {
        $identity = Fixtures::identity();
        $identity['certificate']['fingerprint'] = openssl_x509_fingerprint($peer, 'sha256');
        [$status, $body] = ['200 OK', json_encode($identity, JSON_THROW_ON_ERROR)];
    } else {
        [$status, $body] = ['401 Unauthorized', '{"code":"unauthenticated","message":"No client certificate."}'];
    }

    fwrite($connection, sprintf(
        "HTTP/1.1 %s\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
        $status,
        strlen($body),
        $body,
    ));
    fclose($connection);
}
