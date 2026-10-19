<?php

/**
 * Pushes open incidents into a custom list and a count into a metric, and
 * rotates the client certificate when it nears expiry. Run it from cron.
 *
 *     VARI_CERT=/etc/vari/client.crt VARI_KEY=/etc/vari/client.key php sync-incidents.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ETFusion\VARI\CertificateRotation;
use ETFusion\VARI\Client;
use ETFusion\VARI\Exception\NotFoundException;
use ETFusion\VARI\Model\ListRow;

$vari = new Client((string) getenv('VARI_CERT'), (string) getenv('VARI_KEY'));

if ($vari->certificateExpiresWithin(30)) {
    $vari = CertificateRotation::run($vari)->client;
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

// Remove rows for incidents that have closed.
$open = array_column($incidents, 'id');
foreach ($vari->lists()->rows('incidents') as $row) {
    if (!in_array($row->externalUniqueIdentifier, $open, true)) {
        $vari->lists()->deleteRow('incidents', $row->externalUniqueIdentifier);
    }
}

try {
    $vari->metrics()->setValue('open-incidents', count($incidents));
} catch (NotFoundException) {
    $vari->metrics()->create('open-incidents', 'Open incidents', unit: 'count', value: count($incidents));
}
