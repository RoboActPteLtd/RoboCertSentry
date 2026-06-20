<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Plesk;

use PHPUnit\Framework\TestCase;

/**
 * Locks the one piece of brittle, output-format-dependent logic in the Plesk glue:
 * picking out our own Event Manager handler ids from `event_handler --list`. The
 * fixture is the real listing format captured from Plesk Obsidian 18.0.78, so this
 * test fails loudly if that format ever drifts.
 */
final class EventHandlerParsingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // The plib class touches pm_* only inside methods, so it loads fine here
        // and its pure parser can be exercised without a Plesk runtime.
        require_once __DIR__ . '/../../plib/library/EventHandlers.php';
    }

    private const REAL_LISTING = <<<TXT
   Id               1
   Name             Some operator handler
   Priority         50
   User             root
   Command          /bin/true

   Id               2
   Name             SSL/TLS certificate on domain assigned/unassigned
   Priority         50
   User             root
   Command          /usr/local/psa/bin/extension --exec robocertsentry reconcile.php

   Id               5
   Name             SSL/TLS certificate on webmail assigned/unassigned
   Priority         50
   User             root
   Command          /usr/local/psa/bin/extension --exec robocertsentry reconcile.php
TXT;

    public function testReturnsOnlyTheIdsOfOurOwnHandlers(): void
    {
        $ids = \Modules_Robocertsentry_EventHandlers::parseOwnIds(self::REAL_LISTING);

        $this->assertSame([2, 5], $ids, 'matches handlers whose command names the module, skips the operator handler');
    }

    public function testReturnsEmptyWhenNoneAreOurs(): void
    {
        $listing = "   Id               1\n   Command          /bin/true\n";

        $this->assertSame([], \Modules_Robocertsentry_EventHandlers::parseOwnIds($listing));
    }

    public function testReturnsEmptyForEmptyListing(): void
    {
        $this->assertSame([], \Modules_Robocertsentry_EventHandlers::parseOwnIds(''));
    }
}
