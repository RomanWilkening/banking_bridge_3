<?php

namespace Fhp\MT535;

use Fhp\Model\StatementOfHoldings\Holding;
use Fhp\Model\StatementOfHoldings\StatementOfHoldings;

/**
 * Data format: MT 535 (Version SRG 1998)
 * 
 * PATCHED VERSION: Extended to support additional bank formats (e.g., Baader Bank)
 *
 * @link https://www.hbci-zka.de/dokumente/spezifikation_deutsch/fintsv3/FinTS_3.0_Messages_Finanzdatenformate_2010-08-06_final_version.pdf
 * Section: B.4
 */
class MT535
{
    /** @var string */
    private $cleanedRawData;

    /** @var string */
    private $rawData;

    public function __construct(string $rawData)
    {
        $this->rawData = $rawData;
        // The divider can be either \r\n or @@
        $divider = substr_count($rawData, "\r\n-") > substr_count($rawData, '@@-') ? "\r\n" : '@@';
        $this->cleanedRawData = preg_replace('#' . $divider . '([^:])#ms', '$1', $rawData);
    }

    public function parseDepotWert(): float
    {
        // Try standard format first: :16R:ADDINFO ... EUR... :16S:ADDINFO
        if (preg_match('/:16R:ADDINFO(.*?):16S:ADDINFO/sm', $this->cleanedRawData, $block)) {
            if (preg_match('/EUR([\d,\.]+)/sm', $block[1], $matches)) {
                return floatval(str_replace(',', '.', $matches[1]));
            }
            // Try alternative format: :19A::HOLP//EUR...
            if (preg_match('/:19A::HOLP\/\/EUR([\d,\.]+)/sm', $block[1], $matches)) {
                return floatval(str_replace(',', '.', $matches[1]));
            }
        }
        
        // Fallback: sum up individual holding values
        $total = 0.0;
        preg_match_all('/:19A::HOLD\/\/EUR([\d,\.]+)/sm', $this->cleanedRawData, $matches);
        foreach ($matches[1] as $value) {
            $total += floatval(str_replace(',', '.', $value));
        }
        return $total;
    }

    public function parseHoldings(): StatementOfHoldings
    {
        $result = new StatementOfHoldings();
        preg_match_all('/:16R:FIN(.*?):16S:FIN/sm', $this->cleanedRawData, $blocks);
        // Also extract raw FIN blocks for multiline-sensitive parsing (e.g., :70E::HOLD)
        preg_match_all('/:16R:FIN(.*?):16S:FIN/sm', $this->rawData, $rawBlocks);
        
        foreach ($blocks[1] as $blockIndex => $block) {
            $rawBlock = isset($rawBlocks[1][$blockIndex]) ? $rawBlocks[1][$blockIndex] : '';
            $holding = new Holding();
            
            // === ISIN, WKN & Name Parsing ===
            // Standard format: :35B:ISIN DE0005190003/DE/519000BAY.MOTOREN WERKE AG ST
            // Baader format (after cleanup, lines are joined):
            //   :35B:ISIN IE000I8IKC59IMII-MJECPA E. DLAIMII-MSCI J.ESG Cl.Par.Al.ETF
            // We need to use the RAW block data to properly parse multiline names
            
            // Find the raw block from original data for name parsing
            $isin = null;
            $name = null;
            
            // First extract ISIN from cleaned data
            if (preg_match('/:35B:.*?ISIN\s*([A-Z]{2}[A-Z0-9]{10})/i', $block, $isinMatch)) {
                $isin = $isinMatch[1];
                $holding->setISIN($isin);
            }
            
            // Try standard format with WKN: ISIN XXXXXXXXXXXX/DE/WKNXXX Name
            if (preg_match('/:35B:ISIN\s*([A-Z]{2}[A-Z0-9]{10})\/([A-Z]{2})\/([A-Z0-9]{6})(.*?)(?=:)/si', $block, $r)) {
                $holding->setWKN(trim($r[3]));
                $holding->setName(trim($r[4]));
            }
            // Baader format: After cleanup, content after ISIN is concatenated
            // Pattern: ISIN + 12 chars + rest until next field
            // We DON'T set the name here - let the fallback handle it from raw data
            // Only set WKN to empty if we know it's Baader format
            elseif ($isin && preg_match('/:35B:.*?ISIN\s*' . preg_quote($isin, '/') . '(.+?)(?=:\d)/s', $block, $nameMatch)) {
                // Don't parse concatenated name from cleaned data - it's unreliable
                // Mark WKN as empty (Baader doesn't provide it)
                $holding->setWKN('');
                // Name will be set by the fallback parser below using raw data
            }
            
            // Fallback: Try to get name from raw data by finding multiline structure
            if (($holding->getName() === null || $holding->getName() === '') && $isin) {
                // Search in raw data for proper multiline parsing
                // Pattern: :35B:ISIN XXXXXXXXXXXX followed by newline, short name, newline, full name
                $pattern = '/:35B:ISIN\s*' . preg_quote($isin, '/') . '\s*[\r\n]+([^\r\n]+)[\r\n]+([^\r\n]+)/s';
                if (preg_match($pattern, $this->rawData, $rawMatch)) {
                    // rawMatch[2] should be the full name (third line)
                    $name = trim($rawMatch[2]);
                    if (!empty($name) && strlen($name) > 2) {
                        $holding->setName($name);
                    } elseif (!empty(trim($rawMatch[1]))) {
                        // Use short name if full name is too short or empty
                        $holding->setName(trim($rawMatch[1]));
                    }
                }
                // If still no name, try with just one line after ISIN
                if (($holding->getName() === null || $holding->getName() === '') && $isin) {
                    $pattern2 = '/:35B:ISIN\s*' . preg_quote($isin, '/') . '\s*[\r\n]+([^\r\n:]+)/s';
                    if (preg_match($pattern2, $this->rawData, $rawMatch2)) {
                        $name = trim($rawMatch2[1]);
                        if (!empty($name)) {
                            $holding->setName($name);
                        }
                    }
                }
            }

            // === Acquisition Value (Einstandswert) ===
            // Baader Bank provides the acquisition value in :70C::SUBB// block, line 4:
            // :70C::SUBB//1 NAME\r\n2\r\n3 GDM PRICE...\r\n4 VALUE EUR ISIN, 1/SO
            // The value on line 4 (e.g., "118.62EUR") is the total acquisition value
            
            // Try to extract from raw data (need multiline parsing)
            if ($isin) {
                // Pattern to find the SUBB block for this ISIN and extract line 4 value
                $subbPattern = '/:70C::SUBB\/\/.*?[\r\n]+\d[\r\n]+\d[^\r\n]*[\r\n]+\d\s+([\d\.]+)EUR\s*' . preg_quote($isin, '/') . '/s';
                if (preg_match($subbPattern, $this->rawData, $subbMatch)) {
                    $acquisitionValue = floatval($subbMatch[1]);
                    if ($acquisitionValue > 0) {
                        $holding->setAcquisitionPrice($acquisitionValue);
                    }
                }
            }
            
            // Fallback: Standard format from :70E::HOLD//
            // The :70E::HOLD field provides the per-unit acquisition price (Einstandskurs).
            // Format: :70E::HOLD//[n]STK\r\n[line_no][price]+[currency]
            // In multiline format, continuation lines start with a line sequence number (e.g., "2")
            // which gets incorrectly concatenated with the price during cleanup.
            // We parse from raw data first to handle multiline correctly.
            $acquisitionIsPerUnit = false;
            if ($holding->getAcquisitionPrice() === null) {
                $parsedAcqPrice = null;
                $parsedAcqUnits = 1;
                $parsedAcqCurrency = null;

                // Try multiline format from raw block first:
                // :70E::HOLD//1STK\r\n220,459803+EUR → line "2" is prefix, actual price is 20,459803
                if (preg_match('/:70E::HOLD\/\/(\d*)STK\s*[\r\n]+\d([\d]+[,.][\d]+)[+-]([A-Z]{3})/', $rawBlock, $iwn)) {
                    $parsedAcqUnits = !empty($iwn[1]) ? (int) $iwn[1] : 1;
                    $parsedAcqPrice = floatval(str_replace(',', '.', $iwn[2]));
                    $parsedAcqCurrency = $iwn[3];
                }
                // Single-line format (no continuation line): :70E::HOLD//1STK23,968293+EUR
                elseif (preg_match('/:70E::HOLD\/\/(\d*)STK([\d]+[,.][\d]+)[+-]([A-Z]{3})/s', $block, $iwn)) {
                    $parsedAcqUnits = !empty($iwn[1]) ? (int) $iwn[1] : 1;
                    $parsedAcqPrice = floatval(str_replace(',', '.', $iwn[2]));
                    $parsedAcqCurrency = $iwn[3];
                }

                if ($parsedAcqPrice !== null) {
                    // Price is per $parsedAcqUnits units, normalize to per-unit
                    $holding->setAcquisitionPrice($parsedAcqPrice / $parsedAcqUnits);
                    $acquisitionIsPerUnit = true;
                    if ($parsedAcqCurrency !== null && $holding->getCurrency() === null) {
                        $holding->setCurrency($parsedAcqCurrency);
                    }
                }
            }

            // === Current Price ===
            // Standard format: :90B::MRKT//ACTU/EUR76,06 or :90A::
            if (preg_match('/:90(.)::(.*?):/sm', $block, $iwn)) {
                if ($iwn[1] == 'B') {
                    // Currency
                    preg_match('/^.{11}(.{3})/sm', $iwn[2], $r);
                    if (isset($r[1])) {
                        $holding->setCurrency($r[1]);
                    }
                    // Price
                    preg_match('/^.{14}(.*)/sm', $iwn[2], $r);
                    if (isset($r[1])) {
                        $holding->setPrice(floatval(str_replace(',', '.', $r[1])));
                    }
                } elseif ($iwn[1] == 'A') {
                    $holding->setCurrency('%');
                    // Price
                    preg_match('/^.{11}(.*)/sm', $iwn[2], $r);
                    if (isset($r[1])) {
                        $holding->setPrice(floatval(str_replace(',', '.', $r[1])) / 100);
                    }
                }
            }

            // === Amount (Menge) ===
            // Format: :93B::AGGR//UNIT/2666,000
            if (preg_match('/:93B::(.*?):/sm', $block, $iwn)) {
                // Amount
                preg_match('/^.{11}(.*)/sm', $iwn[1], $r);
                if (isset($r[1])) {
                    $holding->setAmount(floatval(str_replace(',', '.', $r[1])));
                }
            }

            // Convert per-unit acquisition price to total acquisition value
            // processDepotResult() expects getAcquisitionPrice() to return the TOTAL value.
            // Baader :70C::SUBB already provides total; standard :70E::HOLD provides per-unit.
            if ($acquisitionIsPerUnit && $holding->getAcquisitionPrice() !== null
                && $holding->getAmount() !== null && $holding->getAmount() > 0) {
                $holding->setAcquisitionPrice($holding->getAcquisitionPrice() * $holding->getAmount());
            }

            // === Total Value (Gesamtwert) ===
            // Baader format: :19A::HOLD//EUR12,42
            if (preg_match('/:19A::HOLD\/\/([A-Z]{3})([\d,\.]+)/sm', $block, $iwn)) {
                $value = floatval(str_replace(',', '.', $iwn[2]));
                $holding->setValue($value);
                if ($holding->getCurrency() === null) {
                    $holding->setCurrency($iwn[1]);
                }
                
                // If we have value and amount but no price, calculate price
                if ($holding->getPrice() === null && $holding->getAmount() !== null && $holding->getAmount() > 0) {
                    $holding->setPrice($value / $holding->getAmount());
                }
            }

            // Calculate value if we have amount and price but no value yet
            if ($holding->getValue() === null && $holding->getAmount() !== null && $holding->getPrice() !== null) {
                if ($holding->getCurrency() === '%') {
                    $holding->setValue($holding->getPrice() / 100);
                } else {
                    $holding->setValue($holding->getPrice() * $holding->getAmount());
                }
            }

            // === Date ===
            // :98A::PRIC//20210304
            // :98C::STAT//20250104140541
            if (preg_match('/:98([AC])::(.*?):/sm', $block, $iwn)) {
                preg_match('/^.{6}(.{8})/sm', $iwn[2], $r);
                if (isset($r[1])) {
                    $holding->setDate($this->getDate($r[1]));
                    $time = new \DateTime();
                    if ($iwn[1] == 'C') {
                        // 98C has a time component
                        preg_match('/^.{14}(\d\d)(\d\d)(\d\d)/sm', $iwn[2], $r);
                        if (isset($r[1], $r[2], $r[3])) {
                            $time->setTime((int)$r[1], (int)$r[2], (int)$r[3]);
                        }
                    } else {
                        $time->setTime(0, 0);
                    }
                    $holding->setTime($time);
                }
            }

            $result->addHolding($holding);
        }
        return $result;
    }

    protected function getDate(string $val): \DateTime
    {
        preg_match('/(\d{4})(\d{2})(\d{2})/', $val, $m);
        try {
            return new \DateTime($m[1] . '-' . $m[2] . '-' . $m[3]);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Invalid date: $val", 0, $e);
        }
    }
}
