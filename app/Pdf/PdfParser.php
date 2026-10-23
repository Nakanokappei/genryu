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
    /** The most bytes one compressed stream may inflate to. */
    public const DECODE_MEMORY_LIMIT = 32 * 1024 * 1024;

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
        // A compressed stream inflates to at most this, so a decompression bomb cannot exhaust the worker's memory.
        $config->setDecodeMemoryLimit(self::DECODE_MEMORY_LIMIT);
        // Images are not read from a PDF; their bytes are not kept.
        $config->setRetainImageContent(false);

        parent::__construct($cfg, $config);

        $this->rawDataParser = new DecryptingRawDataParser($cfg, $config);
    }
}
