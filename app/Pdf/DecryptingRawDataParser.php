<?php

namespace App\Pdf;

use Smalot\PdfParser\RawData\RawDataParser;

/**
 * smalot/pdfparser's raw reader, decrypting each stream before its filters
 * when the trailer names an /Encrypt dictionary. Strings outside streams
 * (title, author) stay encrypted; the text does not need them.
 */
class DecryptingRawDataParser extends RawDataParser
{
    /** The file's handler, built at the first stream; false when not encrypted. */
    private StandardSecurityHandler|false|null $handler = null;

    /** The "number_generation" of the object whose streams are being decoded. */
    private ?string $currentObject = null;

    /**
     * Parse a file, starting without a handler.
     *
     * @return array<int, mixed>
     */
    public function parseData(string $data): array
    {
        $this->handler = null;

        return parent::parseData($data);
    }

    /**
     * Read an object, remembering it as current; restored after, since the reader recurses.
     *
     * @param  array<string, mixed>  $xref
     * @return array<int, mixed>
     */
    protected function getIndirectObject(string $pdfData, array $xref, string $objRef, int $offset = 0, bool $decoding = true): array
    {
        $previous = $this->currentObject;
        $this->currentObject = $objRef;

        try {
            return parent::getIndirectObject($pdfData, $xref, $objRef, $offset, $decoding);
        } finally {
            $this->currentObject = $previous;
        }
    }

    /**
     * Decrypt the current object's stream before its filters; never a cross-reference stream.
     *
     * @param  array<string, mixed>  $xref
     * @param  array<int, array<int, mixed>>  $sdic
     * @return array<int, mixed>
     */
    protected function decodeStream(string $pdfData, array $xref, array $sdic, string $stream): array
    {
        $handler = $this->handler($pdfData, $xref);

        // Encrypted file, known object, not an xref stream: decrypt.
        if ($handler !== false && $this->currentObject !== null && ! self::isCrossReferenceStream($sdic)) {
            [$number, $generation] = array_map(intval(...), explode('_', $this->currentObject));
            $stream = $handler->decrypt(self::declaredLength($sdic, $stream), $number, $generation);
        }

        return parent::decodeStream($pdfData, $xref, $sdic, $stream);
    }

    /**
     * The file's handler from /Encrypt and the trailer's first /ID, or false.
     *
     * @param  array<string, mixed>  $xref
     */
    private function handler(string $pdfData, array $xref): StandardSecurityHandler|false
    {
        // Already decided.
        if ($this->handler !== null) {
            return $this->handler;
        }

        // No trailer yet (xref streams come first): undecided.
        if (! isset($xref['trailer'])) {
            return false;
        }

        $reference = $xref['trailer']['encrypt'] ?? null;

        // No /Encrypt: not encrypted.
        if (! is_string($reference) || ! isset($xref['xref'][$reference])) {
            return $this->handler = false;
        }

        $object = $this->getIndirectObject($pdfData, $xref, $reference, (int) $xref['xref'][$reference], false);
        $dictionary = self::toArray($object[0] ?? ['null', 'null']);

        // An unreadable /Encrypt dictionary.
        if (! is_array($dictionary) || $dictionary === []) {
            throw new \RuntimeException(__('The encryption dictionary of this PDF could not be read.'));
        }

        return $this->handler = StandardSecurityHandler::fromEncryptDictionary($dictionary, self::bytes((string) ($xref['trailer']['id'][0] ?? '')));
    }

    /**
     * Whether a stream dictionary is that of a cross-reference stream.
     *
     * @param  array<int, array<int, mixed>>  $sdic
     */
    private static function isCrossReferenceStream(array $sdic): bool
    {
        // A /Type followed by /XRef.
        foreach ($sdic as $k => $element) {
            if ($element[0] === '/' && $element[1] === 'Type' && ($sdic[$k + 1][1] ?? null) === 'XRef') {
                return true;
            }
        }

        return false;
    }

    /**
     * The stream cut to its declared numeric /Length, where the encrypted bytes end.
     *
     * @param  array<int, array<int, mixed>>  $sdic
     */
    private static function declaredLength(array $sdic, string $stream): string
    {
        // A /Length followed by a number.
        foreach ($sdic as $k => $element) {
            if ($element[0] === '/' && $element[1] === 'Length' && ($sdic[$k + 1][0] ?? null) === 'numeric') {
                return substr($stream, 0, (int) $sdic[$k + 1][1]);
            }
        }

        return $stream;
    }

    /**
     * A raw element of the reader as a plain value: names and numbers as
     * strings, strings as bytes, dictionaries as arrays keyed by name.
     *
     * @param  array<int, mixed>  $element
     */
    private static function toArray(array $element): mixed
    {
        // By the element's type.
        return match ($element[0]) {
            '<<' => self::dictionary($element[1]),
            '[' => array_map(self::toArray(...), $element[1]),
            '(' => self::unescape((string) $element[1]),
            '<' => (string) hex2bin(strlen((string) $element[1]) % 2 === 1 ? $element[1].'0' : (string) $element[1]),
            'boolean' => $element[1] === 'true',
            'numeric' => is_numeric($element[1]) ? +$element[1] : 0,
            default => $element[1],
        };
    }

    /**
     * A dictionary's name / value elements as an array keyed by name.
     *
     * @param  array<int, array<int, mixed>>  $elements  the names and values of a dictionary, in turn
     * @return array<string, mixed>
     */
    private static function dictionary(array $elements): array
    {
        $dictionary = [];

        // Each name with the value after it.
        for ($i = 0; $i + 1 < count($elements); $i += 2) {
            if ($elements[$i][0] === '/') {
                $dictionary[(string) $elements[$i][1]] = self::toArray($elements[$i + 1]);
            }
        }

        return $dictionary;
    }

    /** The bytes of a trailer /ID string, given as hex digits or as a literal. */
    private static function bytes(string $value): string
    {
        return preg_match('/^[0-9A-Fa-f]+$/', $value) === 1 && strlen($value) % 2 === 0 ? (string) hex2bin($value) : self::unescape($value);
    }

    /** Undo a literal string's escapes: backslash sequences, octal codes, line continuations. */
    private static function unescape(string $literal): string
    {
        return (string) preg_replace_callback('/\\\\(?:([0-7]{1,3})|(\r\n|\r|\n)|(.))/s', function (array $match): string {
            // An octal code, a line continuation (nothing), or a single escaped character.
            [, $octal, $newline, $character] = [...$match, '', '', ''];

            // Octal code.
            if ($octal !== '') {
                return chr((int) octdec($octal) & 255);
            }

            return $newline !== '' ? '' : match ($character) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
                default => $character,
            };
        }, $literal);
    }
}
