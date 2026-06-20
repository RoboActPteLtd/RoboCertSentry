<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Ingest;

/**
 * What the recorder did with one observation.
 *
 * Both sources may legitimately report the same issuance: the live reconciler
 * re-reads the whole cert store on each event, and the importer may replay a log.
 * Reporting whether an observation was stored or recognised as already known lets
 * the importer and reconciler summarise their work honestly rather than guess.
 */
enum RecordOutcome
{
    case RECORDED;
    case DUPLICATE_SKIPPED;
}
