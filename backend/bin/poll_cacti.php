<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CircuitMap\Models\CircuitRepository;
use CircuitMap\Models\LocationRepository;
use CircuitMap\Services\Cacti\CactiClient;
use CircuitMap\Services\Cacti\CactiPollService;
use CircuitMap\Support\Database;
use CircuitMap\Support\Env;

/**
 * Cacti status/usage poller, run as a long-lived supervisord program.
 * Pass --once to run a single poll pass and exit (0 on success, 1 if
 * Cacti was unreachable) — used by tests and manual verification.
 */

$once = in_array('--once', $argv, true);

$cactiHost = Env::get('CACTI_DB_HOST');
if ($cactiHost === null) {
    fwrite(STDOUT, "Cacti integration disabled (CACTI_DB_HOST is not set).\n");
    if ($once) {
        exit(0);
    }
    // Idle instead of exiting: supervisord would otherwise restart-spin
    // this program on every deployment that doesn't configure Cacti.
    while (true) {
        sleep(3600);
    }
}

$intervalSeconds = max(30, Env::getInt('CACTI_POLL_INTERVAL', 300));

$client = new CactiClient(
    $cactiHost,
    Env::getInt('CACTI_DB_PORT', 3306),
    Env::get('CACTI_DB_NAME', 'cacti'),
    Env::get('CACTI_DB_USER', 'cacti'),
    Env::get('CACTI_DB_PASSWORD', '') ?? '',
    Env::get('CACTI_TRAFFIC_IN_NAME', 'traffic_in'),
    Env::get('CACTI_TRAFFIC_OUT_NAME', 'traffic_out')
);
$service = new CactiPollService(
    new LocationRepository(Database::connection()),
    new CircuitRepository(Database::connection()),
    $client,
    Env::getInt('CACTI_STALE_AFTER', 900)
);

$runOnce = static function (CactiPollService $service): bool {
    $result = $service->poll();
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    if ($result['ok']) {
        fwrite(STDOUT, sprintf(
            "[%s] cacti poll ok: %d site status(es) from %d mapped location(s), %d usage row(s) from %d mapped circuit(s)\n",
            $timestamp,
            $result['statuses'],
            $result['locations'],
            $result['usages'],
            $result['circuits']
        ));
    } else {
        fwrite(STDERR, sprintf(
            "[%s] cacti poll failed: %s (%d location status(es) marked stale-unknown)\n",
            $timestamp,
            $result['error'],
            $result['stale']
        ));
    }
    return $result['ok'];
};

if ($once) {
    exit($runOnce($service) ? 0 : 1);
}

fwrite(STDOUT, "Cacti poller started (interval {$intervalSeconds}s, db {$cactiHost}).\n");
while (true) {
    try {
        $runOnce($service);
    } catch (\Throwable $e) {
        // poll() maps expected Cacti failures itself; anything reaching
        // here is a bug or a local DB problem. Log and keep the loop alive.
        fwrite(STDERR, 'cacti poller error: ' . $e->getMessage() . "\n");
    }
    sleep($intervalSeconds);
}
