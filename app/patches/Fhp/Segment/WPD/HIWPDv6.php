<?php
/** @noinspection PhpUnused */

namespace Fhp\Segment\WPD;

use Fhp\Segment\BaseSegment;
use Fhp\Syntax\Bin;

/**
 * Segment: Depotaufstellung Kreditinstitutsrückmeldung (Version 6)
 *
 * @link https://www.fints.org/de/spezifikation (FinTS_3.0_Messages_Geschaeftsvorfaelle_2022-04-15)
 * Section: C.4.3.1 Depotaufstellung - Kreditinstitutsrückmeldung
 */
class HIWPDv6 extends BaseSegment implements HIWPD
{
    /** Uses SWIFT format MT535, version SRG 1998 */
    public Bin $depotaufstellung;

    public function getDepotaufstellung(): Bin
    {
        return $this->depotaufstellung;
    }
}
