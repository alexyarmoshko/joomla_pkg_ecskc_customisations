<?php

declare(strict_types=1);

/**
 * A process that appends a run of event records as fast as it can, then exits.
 *
 * An exclusive lock per append is only worth testing against a second process: two
 * appends inside one process are serial whatever the lock does. So the tests start two
 * of these on one log and then read it. Each record carries the tag as its reason, so
 * the parent can count both runs separately and see that neither lost a record to the
 * other.
 *
 * Not a test itself, and not collected as one: the suites scan tests/Unit and
 * tests/Integration.
 *
 * Usage: php log_append.php <package root> <tag> <count>
 */

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventLog;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\EventRecord;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$root  = $argv[1] ?? '';
$tag   = $argv[2] ?? '';
$count = (int) ($argv[3] ?? 0);

$log = new EventLog(new PackagePaths($root));

for ($appended = 0; $appended < $count; ++$appended) {
    $seed   = $tag . ':' . $appended;
    $digest = \hash('sha256', $seed);
    $record = EventRecord::mutation(
        EventRecord::WRITE,
        EventRecord::COPY,
        \sprintf('templates/et_tennis/css/override.%s.%d.css', $tag, $appended),
        EventRecord::ABSENT,
        $digest,
        \substr($digest, 0, 32),
        reason: $tag
    );

    if (!$log->append($record)) {
        \fwrite(\STDERR, (string) $log->failure());
        exit(2);
    }
}

exit(0);
