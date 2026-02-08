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
        
        foreach ($blocks[1] as $block) {
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
            elseif ($isin && preg_match('/:35B:.*?ISIN\s*' . preg_quote($isin, '/') . '(.+?)(?=:\d)/s', $block, $nameMatch)) {
                $nameContent = trim($nameMatch[1]);
                // The name content might be "ShortNameFullName" concatenated
                // Try to find a reasonable split point or just use the whole thing
                // Usually the full name is longer, so we take the latter part if it looks like duplicate
                if (strlen($nameContent) > 10) {
                    // Check if it looks like two names concatenated (e.g., "IMII-MJECPA E. DLAIMII-MSCI J.ESG Cl.Par.Al.ETF")
                    // The pattern often repeats the start of the name
                    $midpoint = (int)(strlen($nameContent) / 2);
                    $firstHalf = substr($nameContent, 0, $midpoint);
                    $secondHalf = substr($nameContent, $midpoint);
                    
                    // If second half contains recognizable fund name patterns, use it
                    if (preg_match('/(ETF|Fund|Index|UCITS|Acc|Dis|EUR|USD)/i', $secondHalf)) {
                        $name = trim($secondHalf);
                    } else {
                        $name = $nameContent;
                    }
                } else {
                    $name = $nameContent;
                }
                $holding->setName($name);
                $holding->setWKN('');
            }
            
            // Fallback: Try to get name from raw data by finding the FIN block
            if (($holding->getName() === null || $holding->getName() === '') && $isin) {
                // Search in raw data for proper multiline parsing
                $pattern = '/:16R:FIN.*?:35B:.*?ISIN\s*' . preg_quote($isin, '/') . '[\r\n]+([^\r\n:]+)[\r\n]+([^\r\n:]+)/s';
                if (preg_match($pattern, $this->rawData, $rawMatch)) {
                    // rawMatch[2] should be the full name (third line)
                    $name = trim($rawMatch[2]);
                    if (!empty($name)) {
                        $holding->setName($name);
                    }
                }
            }

            // === Acquisition Price (Einstandskurs) ===
            // Standard format: :70E::HOLD//1STK23,968293+EUR
            // Baader format:   :70E::HOLD//1STK++++20250916+24,438794042+EUR (may be on multiple lines)
            
            // First try standard format (number directly after STK)
            if (preg_match('/:70E::HOLD\/\/\d*STK(\d+),(\d+)\+([A-Z]{3})/s', $block, $iwn)) {
                $holding->setAcquisitionPrice((float) ($iwn[1] . '.' . $iwn[2]));
                if ($holding->getCurrency() === null) {
                    $holding->setCurrency($iwn[3]);
                }
            }
            // Try Baader format: price is after several + signs and optional date
            // Pattern: STK followed by +s, optional 8-digit date, +s, then price, +, currency
            elseif (preg_match('/:70E::HOLD\/\/\d*STK[\+\s]*(?:\d{8})?[\+\s]*([\d]+[,\.][\d]+)\+([A-Z]{3})/s', $block, $iwn)) {
                $price = str_replace(',', '.', $iwn[1]);
                $holding->setAcquisitionPrice((float) $price);
                if ($holding->getCurrency() === null) {
                    $holding->setCurrency($iwn[2]);
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
