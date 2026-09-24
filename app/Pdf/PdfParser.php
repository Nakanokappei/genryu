<?php

namespace App\Pdf;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * smalot/pdfparser that also reads secured PDFs: its encryption check is
 * off and its raw reader decrypts each stream (DecryptingRawDataParser).
 */
class PdfParser extends Parser
{
    /**
     * Configure the library and swap in the decrypting raw reader.
     *
     * @param  array<string, mixed>  $cfg
     */
    public function __construct(array $cfg = [], ?Config $config = null)
    {
        $config ??= new Config;
        $config->setIgnoreEncryption(true);
        // Font sizes with each positioned text, which PdfMarkdown reads headings from.
        $config->setDataTmFontInfoHasToBeIncluded(true);

        parent::__construct($cfg, $config);

        $this->rawDataParser = new DecryptingRawDataParser($cfg, $config);
    }
}
