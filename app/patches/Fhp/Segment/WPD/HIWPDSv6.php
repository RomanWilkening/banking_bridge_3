<?php
/** @noinspection PhpUnused */

namespace Fhp\Segment\WPD;

use Fhp\Segment\BaseGeschaeftsvorfallparameterOld;

/**
 * Segment: Parameter Depotaufstellung (Version 6)
 *
 * @link https://www.fints.org/de/spezifikation (FinTS_3.0_Messages_Geschaeftsvorfaelle_2022-04-15)
 * Section: C.4.3.1 Depotaufstellung - Bankparameterdaten
 */
class HIWPDSv6 extends BaseGeschaeftsvorfallparameterOld implements HIWPDS
{
    public ParameterDepotaufstellungV2 $parameter;

    public function getParameter(): ParameterDepotaufstellung
    {
        return $this->parameter;
    }
}
