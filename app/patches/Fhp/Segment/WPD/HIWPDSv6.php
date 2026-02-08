<?php
/** @noinspection PhpUnused */

namespace Fhp\Segment\WPD;

use Fhp\Segment\BaseGeschaeftsvorfallparameter;

/**
 * Segment: Parameter Depotaufstellung (Version 6)
 *
 * @link https://www.fints.org/de/spezifikation (FinTS_3.0_Messages_Geschaeftsvorfaelle_2022-04-15)
 * Section: C.4.3.1 Depotaufstellung - Bankparameterdaten
 *
 * Note: Version 6 uses BaseGeschaeftsvorfallparameter (with sicherheitsklasse field)
 * unlike Version 5 which uses BaseGeschaeftsvorfallparameterOld.
 */
class HIWPDSv6 extends BaseGeschaeftsvorfallparameter implements HIWPDS
{
    public ParameterDepotaufstellungV2 $parameter;

    public function getParameter(): ParameterDepotaufstellung
    {
        return $this->parameter;
    }
}
