<?php

namespace App\Pdf;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * smalot/pdfparser reading secured PDFs as well: the library refuses a
 * file with an /Encrypt dictionary, so that check is switched off and
 * the raw reader is replaced by one that decrypts each stream with the
 * file's key (App\Pdf\DecryptingRawDataParser). Used by
 * App\Actions\ReadDocument for the text of a PDF document.
 */
class PdfParser extends Parser
{
    /**
     * @param  array<string, mixed>  $cfg
     */
    public function __construct(array $cfg = [], ?Config $config = null)
    {
        $config ??= new Config;
        $config->setIgnoreEncryption(true);
        // Each positioned text (Page::getDataTm) comes with its font size: App\Pdf\PdfMarkdown reads headings and tables from it.
        $config->setDataTmFontInfoHasToBeIncluded(true);

        parent::__construct($cfg, $config);

        $this->rawDataParser = new DecryptingRawDataParser($cfg, $config);
    }
}
