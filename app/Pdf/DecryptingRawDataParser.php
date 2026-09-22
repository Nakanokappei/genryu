<?php

namespace App\Pdf;

use Smalot\PdfParser\RawData\RawDataParser;

/**
 * The raw reader of smalot/pdfparser, which decodes every stream as it
 * reads the objects, with one step added in front: when the trailer
 * names an /Encrypt dictionary, the stream is decrypted with the file's
 * key (App\Pdf\StandardSecurityHandler) before its filters are undone.
 * Object streams are decrypted as a whole, so the objects inside them
 * come out plain; the strings of top-level dictionaries (title, author)
 * stay encrypted, which does not matter for the text.
 */
class DecryptingRawDataParser extends RawDataParser
{
    /** The handler of the file being read, built at the first stream; false once it is known the file is not encrypted. */
    private StandardSecurityHandler|false|null $handler = null;

    /** The "number_generation" of the object whose streams are being decoded. */
    private ?string $currentObject = null;

    /**
     * A new file starts without a handler.
     *
     * @return array<int, mixed>
     */
    public function parseData(string $data): array
    {
        $this->handler = null;

        return parent::parseData($data);
    }

    /**
     * Remember which object is being read while its parts are decoded;
     * the reader recurses into other objects for indirect lengths and
     * filters, so the previous one is put back afterwards.
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
     * Decrypt the stream of the current object before the filters are
     * applied; cross-reference streams are never encrypted.
     *
     * @param  array<string, mixed>  $xref
     * @param  array<int, array<int, mixed>>  $sdic
     * @return array<int, mixed>
     */
    protected function decodeStream(string $pdfData, array $xref, array $sdic, string $stream): array
    {
        $handler = $this->handler($pdfData, $xref);

        if ($handler !== false && $this->currentObject !== null && ! self::isCrossReferenceStream($sdic)) {
            [$number, $generation] = array_map(intval(...), explode('_', $this->currentObject));
            $stream = $handler->decrypt(self::declaredLength($sdic, $stream), $number, $generation);
        }

        return parent::decodeStream($pdfData, $xref, $sdic, $stream);
    }

    /**
     * The handler for this file, from its /Encrypt dictionary and the
     * first string of the trailer's /ID.
     *
     * @param  array<string, mixed>  $xref
     */
    private function handler(string $pdfData, array $xref): StandardSecurityHandler|false
    {
        if ($this->handler !== null) {
            return $this->handler;
        }

        // The cross-reference streams are decoded before the trailer is known: nothing to decide yet.
        if (! isset($xref['trailer'])) {
            return false;
        }

        $reference = $xref['trailer']['encrypt'] ?? null;

        if (! is_string($reference) || ! isset($xref['xref'][$reference])) {
            return $this->handler = false;
        }

        $object = $this->getIndirectObject($pdfData, $xref, $reference, (int) $xref['xref'][$reference], false);
        $dictionary = self::toArray($object[0] ?? ['null', 'null']);

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
        foreach ($sdic as $k => $element) {
            if ($element[0] === '/' && $element[1] === 'Type' && ($sdic[$k + 1][1] ?? null) === 'XRef') {
                return true;
            }
        }

        return false;
    }

    /**
     * The stream cut to its declared /Length, as the encrypted bytes end
     * there; the parent does the same cut for the decoded bytes.
     *
     * @param  array<int, array<int, mixed>>  $sdic
     */
    private static function declaredLength(array $sdic, string $stream): string
    {
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
     * @param  array<int, array<int, mixed>>  $elements  the names and values of a dictionary, in turn
     * @return array<string, mixed>
     */
    private static function dictionary(array $elements): array
    {
        $dictionary = [];

        for ($i = 0; $i + 1 < count($elements); $i += 2) {
            if ($elements[$i][0] === '/') {
                $dictionary[(string) $elements[$i][1]] = self::toArray($elements[$i + 1]);
            }
        }

        return $dictionary;
    }

    /**
     * The bytes of a trailer /ID string, which the reader hands over as
     * hex digits or as the raw literal.
     */
    private static function bytes(string $value): string
    {
        return preg_match('/^[0-9A-Fa-f]+$/', $value) === 1 && strlen($value) % 2 === 0 ? (string) hex2bin($value) : self::unescape($value);
    }

    /**
     * A literal string as the reader hands it over, its escapes still in
     * place: backslash sequences, octal codes and line continuations.
     */
    private static function unescape(string $literal): string
    {
        return (string) preg_replace_callback('/\\\\(?:([0-7]{1,3})|(\r\n|\r|\n)|(.))/s', function (array $match): string {
            // An octal code, a line continuation (nothing), or a single escaped character.
            [, $octal, $newline, $character] = [...$match, '', '', ''];

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
