<?php
/** @noinspection PhpUnused */

namespace Fhp\Protocol;

use Fhp\Model\TanMode;
use Fhp\Segment\AnonymousSegment;
use Fhp\Segment\BaseSegment;
use Fhp\Segment\HIBPA\HIBPAv3;
use Fhp\Segment\HIPINS\HIPINSv1;
use Fhp\Segment\SegmentInterface;
use Fhp\Segment\TAN\HITANS;

/**
 * Segmentfolge: Bankparameterdaten (Version 3)
 *
 * Contains the "Bankparameterdaten" (BPD), i.e. configuration information that was retrieved from the bank server
 * during a synchronization. This library currently does not store persisted BPD, so it just retrieves them freshly
 * every time.
 *
 * Note: The following segments are part of BPD but not explicity implemented in this library:
 * - HIKOM (lists physical communication channels to the bank, but this library only supports HTTPS and the library user
 *   needs to specify the URL explicitly, so there is no need to know the HIKOM contents).
 * - HISHV (lists security protocols that the bank supports, but this library only supports PIN/TAN).
 * - HIKPV (lists compression protocols, but this library supports none).
 *
 * @link https://www.hbci-zka.de/dokumente/spezifikation_deutsch/fintsv3/FinTS_3.0_Formals_2017-10-06_final_version.pdf
 * Section D.1
 *
 * ---------------------------------------------------------------------------
 * !! BANKING-BRIDGE LOCAL PATCH for nemiah/php-fints v3.7.0 !!
 * Issue: nemiah/phpFinTS#554 ("The bank does not support PSD2." since 2026-04-25,
 *        Consorsbank stopped sending HITANSv6 and now only sends HITANSv7)
 *
 * Upstream supportsPsd2() is hard-coded to HITANS v6, although v3.7.0 already
 * ships full HITANSv7 / HKTANv7 / ParameterZweiSchrittTanEinreichungV7 parsing.
 * The patch below relaxes the check to "v6 OR v7" and hardens
 * supportsParameters() against the missing-key warning that the original code
 * produced when $this->parameters[$type] was unset.
 *
 * Removal once an upstream release fixes #554:
 *   1. composer update nemiah/php-fints
 *   2. delete this file (app/patches/Fhp/Protocol/BPD.php)
 *   3. remove the matching COPY line from /Dockerfile
 *   4. revert the special-case "does not support PSD2" branches in
 *      app/src/Services/FinTSService.php (selectTanMode, getBankCapabilities)
 *      -- they only existed as user-facing fallback while the patch wasn't
 *      available yet.
 * See app/patches/README.md for the full patch inventory.
 * ---------------------------------------------------------------------------
 */
class BPD
{
    /** @var HIBPAv3 The HIBPA segment received from the server, which contains most of the BPD data. */
    public $hibpa;

    /**
     * The parameters for each business transaction type, indexed in a nested array structure:
     * - Outer keys are segment identifiers (e.g. 'HIKAZS')
     * - Inner keys are segment versions (these keys are numerically sorted in DESCENDING order, so that the newest and
     *   thus most interesting segment is first)
     * - Inner values are the (possibly anonymous) parameter segments.
     * @var BaseSegment[][]
     */
    public $parameters = [];

    /** @var bool Whether the fake TAN mode 999 is allowed. */
    public $singleStepTanModeAllowed;

    /**
     * @var bool[] An array mapping business transaction request types ('HKxyz' strings) to a bool indicating whether
     *     the respective business transaction needs a TAN, according to the HIPINS information.
     */
    public $tanRequired = [];

    /**
     * @link https://www.hbci-zka.de/dokumente/spezifikation_deutsch/fintsv3/FinTS_3.0_Security_Sicherheitsverfahren_PINTAN_2018-02-23_final_version.pdf
     * Section: B.8.2
     * @var TanMode[] All TAN modes supported by this bank, indexed by their IDs. Note that the UPD contains the modes
     * that the user can use.
     */
    public $allTanModes = [];

    public function getVersion()
    {
        return $this->hibpa->bpdVersion;
    }

    public function getBankCode()
    {
        return $this->hibpa->kreditinstitutskennung->kreditinstitutscode;
    }

    public function getBankName()
    {
        return $this->hibpa->kreditinstitutsbezeichnung;
    }

    /**
     * @param string $type A business transaction type, represented by the segment name of the respective parameter
     *     segment (Geschäftsvorfallparameter segment, aka. Segmentparametersegment). Example: 'HIKAZS'.
     * @return BaseSegment[] All parameter segments of that type ordered descendingly by version (newest first),
     *     excluding such that are not explicitly implemented in this library (no AnonymousSegments). The returned array
     *     is possibly empty if no versions offered by the bank are also supported by the library.
     */
    public function getAllSupportedParameters(string $type): array
    {
        return array_filter($this->parameters[$type] ?? [], function (BaseSegment $segment) {
            return !($segment instanceof AnonymousSegment);
        });
    }

    /**
     * @param string $type A business transaction type, represented by the segment name of the respective parameter
     *     segment (Geschäftsvorfallparameter segment, aka. Segmentparametersegment). Example: 'HIKAZS'.
     * @return BaseSegment|null The latest parameter segment that is explicitly implemented in this library (never an
     *     AnonymousSegment), or null if none exists.
     */
    public function getLatestSupportedParameters(string $type): ?BaseSegment
    {
        if (!array_key_exists($type, $this->parameters)) {
            return null;
        }
        foreach ($this->parameters[$type] as $segment) {
            if (!($segment instanceof AnonymousSegment)) {
                return $segment;
            }
        }
        return null;
    }

    /**
     * @param string $type A business transaction type, see above.
     * @return BaseSegment The latest parameter segment, never null.
     * @throws UnexpectedResponseException If no version exists.
     */
    public function requireLatestSupportedParameters(string $type): BaseSegment
    {
        $result = $this->getLatestSupportedParameters($type);
        if ($result === null) {
            throw new UnexpectedResponseException(
                "The server does not support any $type versions implemented in this library");
        }
        return $result;
    }

    /**
     * @param string $type A business transaction type, see above.
     * @param int $version The segment version of the business transaction.
     * @return bool If that version of the given transaction type is supported by the bank.
     */
    public function supportsParameters(string $type, int $version): bool
    {
        // PATCH (banking-bridge / phpFinTS#554): guard against missing key.
        // Original upstream code accesses $this->parameters[$type] unguarded,
        // which produces an "Undefined array key" warning whenever the bank
        // doesn't advertise that segment type at all (e.g. HITANS missing).
        foreach ($this->parameters[$type] ?? [] as $segment) {
            if ($segment->getVersion() === $version) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param SegmentInterface[] $requestSegments The segments that shall be sent to the bank.
     * @return string|null Identifier of the (first) segment that requires a TAN according to HIPINS, or null if none of
     *     the segments require a TAN.
     */
    public function tanRequiredForRequest(array $requestSegments): ?string
    {
        foreach ($requestSegments as $segment) {
            if ($this->tanRequired[$segment->getName()] ?? false) {
                return $segment->getName();
            }
        }
        return null;
    }

    /**
     * @return bool Whether the BPD indicates that the bank supports PSD2.
     */
    public function supportsPsd2(): bool
    {
        // PATCH (banking-bridge / phpFinTS#554): accept HITANSv6 OR HITANSv7.
        //
        // Upstream only checks for HITANS v6. Since 2026-04-25 the Consorsbank
        // (and likely others over time) stopped advertising HITANSv6 in their
        // anonymous BPD response and only return HITANSv7. nemiah/php-fints
        // v3.7.0 already parses HITANSv7 fully (HITANSv7, HKTANv7,
        // ParameterZweiSchrittTanEinreichungV7,
        // VerfahrensparameterZweiSchrittVerfahrenV7), and the downstream code
        // in extractFromResponse() / FinTs::getTanModes() works through the
        // HITANS interface that both versions implement -- the only blocker is
        // this hard-coded version check.
        return $this->supportsParameters('HITANS', 6)
            || $this->supportsParameters('HITANS', 7);
    }

    /**
     * @param Message $response The dialog initialization response from the server.
     * @return BPD A new BPD instance with the extracted configuration data.
     */
    public static function extractFromResponse(Message $response): BPD
    {
        $bpd = new BPD();
        $bpd->hibpa = $response->requireSegment(HIBPAv3::class);

        // Extract the HIxyzS segments, which contain parameters that describe how (future) requests for the particular
        // type of business transaction have to look.
        foreach ($response->plainSegments as $segment) {
            $segmentName = $segment->getName();
            if (strlen($segmentName) === 6 && $segmentName[5] === 'S') {
                $bpd->parameters[$segmentName][$segment->getVersion()] = $segment;
                krsort($bpd->parameters[$segmentName]); // Newest first.
            }
        }
        ksort($bpd->parameters); // Sort alphabetically, for easier debugging.

        // Extract from HIPINS which HKxyz requests will need a TAN.
        /** @var HIPINSv1 $hipins */
        $hipins = $response->requireSegment(HIPINSv1::class);
        foreach ($hipins->parameter->geschaeftsvorfallspezifischePinTanInformationen as $typeInfo) {
            $bpd->tanRequired[$typeInfo->segmentkennung] = $typeInfo->tanErforderlich;
        }

        // Extract all TanModes from HIPINS.
        if ($bpd->supportsPsd2()) {
            /** @var HITANS[] $allHitans */
            $allHitans = $bpd->getAllSupportedParameters('HITANS');
            if (count($allHitans) === 0) {
                throw new UnexpectedResponseException(
                    'The server does not support any HITANS versions implemented in this library');
            }
            foreach ($allHitans as $hitans) {
                $tanParams = $hitans->getParameterZweiSchrittTanEinreichung();
                $bpd->singleStepTanModeAllowed = $tanParams->isEinschrittVerfahrenErlaubt();
                foreach ($tanParams->getVerfahrensparameterZweiSchrittVerfahren() as $verfahren) {
                    if (!array_key_exists($verfahren->getId(), $bpd->allTanModes)) {
                        $bpd->allTanModes[$verfahren->getId()] = $verfahren;
                    }
                }
            }
        }

        return $bpd;
    }
}
