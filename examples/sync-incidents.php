<?php

/**
 * Pushes open incidents into a custom list and a count into a metric, and
 * rotates the client certificate when it nears expiry. Run it from cron.
 *
 * The certificate and key are kept in AWS Secrets Manager (see
 * AwsSecretsManagerStore.php); implement CredentialStore for another secret manager.
 *
 *     composer require aws/aws-sdk-php
 *     VARI_SECRET_ID=vari/client php sync-incidents.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/AwsSecretsManagerStore.php';

use Aws\SecretsManager\SecretsManagerClient;
use ETFusion\VARI\CertificateRotation;
use ETFusion\VARI\Client;
use ETFusion\VARI\Exception\NotFoundException;
use ETFusion\VARI\Model\ListRow;

$store = new AwsSecretsManagerStore(new SecretsManagerClient([]), (string) getenv('VARI_SECRET_ID'));
$vari = new Client($store->load());

if ($vari->certificateExpiresWithin(30)) {
    $vari = CertificateRotation::run($vari, $store)->client;
}

// Your data source.
$incidents = [
    ['id' => 'INC-1042', 'title' => 'Printer on fire', 'location' => 'Floor 3', 'priority' => 'P1', 'opened' => '-25 minutes'],
    ['id' => 'INC-1043', 'title' => 'Badge reader offline', 'location' => 'Lobby', 'priority' => 'P3', 'opened' => '-2 hours'],
];

try {
    $vari->lists()->get('incidents');
} catch (NotFoundException) {
    $vari->lists()->create(
        'incidents',
        'Open incidents',
        badges: ['P1' => '#D32F2F', 'P2' => '#F57C00', 'P3' => '#1976D2'],
        showRelativeDate: true,
    );
}

// Remove rows for closed incidents first, so a full list has room for new ones.
// If a run stops part way, the list is briefly out of date; the next run fixes it.
$open = array_column($incidents, 'id');
foreach ($vari->lists()->rows('incidents') as $row) {
    if (!in_array($row->externalUniqueIdentifier, $open, true)) {
        $vari->lists()->deleteRow('incidents', $row->externalUniqueIdentifier);
    }
}

$vari->lists()->upsertRows('incidents', array_map(
    static fn (array $incident): ListRow => new ListRow(
        externalUniqueIdentifier: $incident['id'],
        title: $incident['title'],
        displayIdentifier: $incident['id'],
        subtitles: [$incident['location']],
        timestamp: new DateTimeImmutable($incident['opened']),
        badge: $incident['priority'],
    ),
    $incidents,
));

try {
    $vari->metrics()->setValue('open-incidents', count($incidents));
} catch (NotFoundException) {
    $vari->metrics()->create('open-incidents', 'Open incidents', unit: 'count', value: count($incidents));
}
