<?php

namespace App\Pdf;

use Smalot\PdfParser\Document;
use Smalot\PdfParser\Page;

/**
 * The Markdown of a PDF, read from where its text sits on the page and
 * how big it is (Page::getDataTm with the font size), since a PDF has no
 * structure of its own. Text pieces on one baseline make a line (a raised
 * small piece, a footnote mark, stays in its line); a wide gap inside a
 * line splits it into cells; lines whose cells line up in columns make a
 * table; a line printed bigger than the body is a heading, the biggest
 * ones at the top the title; a page number at the edge is dropped; the
 * other lines flow into paragraphs, broken at an indent, a blank line or
 * a change of size. Made for press releases saved from Word; a PDF laid
 * out in columns or with figures would come out in reading order only.
 *
 * @phpstan-type Piece array{x: float, y: float, size: float, text: string, index: int}
 * @phpstan-type Cell array{x: float, text: string}
 * @phpstan-type Line array{page: int, y: float, x: float, end: float, size: float, cells: list<Cell>, text: string, pieces: list<Piece>, bullet: bool}
 */
final class PdfMarkdown
{
    /** A line at least this much bigger than the body is a heading. */
    private const HEADING_MIN_RATIO = 1.1;

    private const HEADING_MAX_CHARS = 80;

    /** Points from the top or bottom edge of the page where page numbers live. */
    private const EDGE = 60.0;

    /** A page number: "1", "1/2", "- 3 -", "2 / 5". */
    private const PAGE_NUMBER_PATTERN = '/^[\s\d\/\-－‐ー]+$/u';

    /** What a printed date looks like when it stands on a line of its own near the top: 2026年8月26日, 2026-08-26, 26.08.2026, August 26, 2026. */
    private const DATE_TEXT_PATTERN = '/\d{4}[年.\/-]\d{1,2}[月.\/-]\d{1,2}|\b\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4}\b|\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.? \d{1,2},? \d{4}\b|\b\d{1,2}\.? (jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.? \d{4}\b/iu';

    private const DATE_LINE_MAX_CHARS = 40;

    /** How many lines from the top of the first page the title and the date are looked for. */
    private const HEAD_LINES = 15;

    /** A line that ends like this ended a sentence, so an indented next line starts a paragraph (and not a hanging note). */
    private const SENTENCE_END = '/[。．.!?！？」』）)】]$/u';

    /** A line that starts like this starts a paragraph of its own: notes, bracketed section names, bullets. */
    private const PARAGRAPH_START = '/^[※【＜＞■●◆◇○▼▲・]/u';

    /** A piece that is only a bullet: a symbol-font glyph (private use area, Word's Wingdings bullets) or a bullet character. */
    private const BULLET_PATTERN = '/^[\x{E000}-\x{F8FF}•●○■□◆◇▪▫‣・]$/u';

    /** Symbol-font glyphs anywhere else have no text: they are dropped. */
    private const PRIVATE_USE_PATTERN = '/[\x{E000}-\x{F8FF}]/u';

    /** Control characters other than tab and newline, which a database column cannot hold. */
    private const CONTROL_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

    /**
     * @return array{title: string, date: ?string, body: string} the title as printed (empty when none was found), the date line as printed, the body as Markdown
     */
    public function __invoke(Document $document, ?string $title = null): array
    {
        $lines = [];

        foreach ($document->getPages() as $page) {
            $lines = [...$lines, ...self::lines($page)];
        }

        if ($lines === []) {
            return ['title' => '', 'date' => null, 'body' => ''];
        }

        $bodySize = self::bodySize($lines);
        $headingSizes = self::headingSizes($lines, $bodySize);

        [$lines, $heading] = self::takeTitle($lines, $title, $headingSizes);
        [$lines, $date] = self::takeDate($lines);

        return ['title' => $heading, 'date' => $date, 'body' => self::markdown($lines, $bodySize, $headingSizes)];
    }

    /**
     * The lines of one page, top to bottom, each with its cells left to
     * right; page numbers at the edges left out.
     *
     * @return list<Line>
     */
    private static function lines(Page $page): array
    {
        $pieces = [];

        foreach ($page->getDataTm() as $index => $data) {
            [$matrix, $text] = $data;
            $size = abs((float) ($matrix[0] ?: $matrix[3])) * (float) ($data[3] ?? 1);
            // A font whose encoding the parser does not know leaves stray bytes: they are no text, and would not store as UTF-8.
            $text = (string) preg_replace(self::CONTROL_PATTERN, '', mb_scrub($text, 'UTF-8'));

            if ($text === '' || $size <= 0) {
                continue;
            }

            $pieces[] = ['x' => (float) $matrix[4], 'y' => (float) $matrix[5], 'size' => $size, 'text' => $text, 'index' => $index];
        }

        // Top to bottom; pieces on the same baseline keep the order they were drawn in.
        usort($pieces, fn (array $a, array $b): int => $b['y'] <=> $a['y'] ?: $a['index'] <=> $b['index']);

        $lines = [];
        $current = null;

        foreach ($pieces as $piece) {
            // A piece joins the line when their baselines are close for the bigger of the two: a footnote mark sits a little above its line.
            if ($current !== null && abs($piece['y'] - $current['y']) <= 0.6 * max($piece['size'], $current['size'])) {
                $current['pieces'][] = $piece;

                if ($piece['size'] > $current['size']) {
                    $current['size'] = $piece['size'];
                    $current['y'] = $piece['y'];
                }

                continue;
            }

            if ($current !== null) {
                $lines[] = $current;
            }

            $current = ['y' => $piece['y'], 'size' => $piece['size'], 'pieces' => [$piece]];
        }

        if ($current !== null) {
            $lines[] = $current;
        }

        $height = (float) ($page->getDetails()['MediaBox'][3] ?? 842);
        $number = $page->getPageNumber();
        $result = [];

        foreach ($lines as $line) {
            $line = self::cells($line, $number);

            if ($line['text'] === '') {
                continue;
            }

            // A page number: digits alone at the top or bottom edge.
            if (($line['y'] < self::EDGE || $line['y'] > $height - self::EDGE) && preg_match(self::PAGE_NUMBER_PATTERN, $line['text']) === 1) {
                continue;
            }

            $result[] = $line;
        }

        return $result;
    }

    /**
     * The pieces of a line left to right, run together into cells: a new
     * cell starts where the gap before a piece is wider than two
     * characters. The pen keeps the estimated end of what was written so far,
     * since a piece only says where it starts.
     *
     * @param  array{y: float, size: float, pieces: list<Piece>}  $line
     * @return Line
     */
    private static function cells(array $line, int $page): array
    {
        $pieces = $line['pieces'];
        usort($pieces, fn (array $a, array $b): int => $a['x'] <=> $b['x'] ?: $a['index'] <=> $b['index']);

        // A bullet in front of the text makes the line a list item; the glyph itself is not text.
        $bullet = false;

        if (count($pieces) > 1 && preg_match(self::BULLET_PATTERN, $pieces[0]['text']) === 1) {
            $bullet = true;
            array_shift($pieces);
        }

        $cells = [];
        $text = '';
        $cellX = 0.0;
        $pen = null;

        foreach ($pieces as $piece) {
            $piece['text'] = (string) preg_replace(self::PRIVATE_USE_PATTERN, '', $piece['text']);
            $gap = $pen === null ? 0.0 : $piece['x'] - $pen;

            if ($text !== '' && $gap > 2 * $line['size']) {
                // Only spaces so far: no cell yet.
                if (self::oneLine($text) !== '') {
                    $cells[] = ['x' => $cellX, 'text' => self::oneLine($text)];
                }

                $text = '';
            }

            if ($text === '') {
                $cellX = $piece['x'];
            } elseif ($gap > 0.25 * $piece['size'] && preg_match('/[A-Za-z0-9]$/', $text) === 1 && preg_match('/^[A-Za-z0-9]/', $piece['text']) === 1) {
                // Two Latin words drawn apart without a space between them.
                $text .= ' ';
            }

            $text .= $piece['text'];
            $pen = max($pen ?? $piece['x'], $piece['x']) + self::width($piece['text'], $piece['size']);
        }

        if (self::oneLine($text) !== '') {
            $cells[] = ['x' => $cellX, 'text' => self::oneLine($text)];
        }

        return [
            'page' => $page,
            'y' => $line['y'],
            'x' => $cells[0]['x'] ?? 0.0,
            'end' => (float) $pen,
            'size' => $line['size'],
            'cells' => $cells,
            'text' => implode(' ', array_column($cells, 'text')),
            'pieces' => $pieces,
            'bullet' => $bullet,
        ];
    }

    /**
     * How wide a text is drawn, roughly: a full-width character is as wide
     * as it is tall, a Latin one about half of that.
     */
    private static function width(string $text, float $size): float
    {
        $width = 0.0;

        foreach (mb_str_split($text) as $character) {
            $width += $character === ' ' ? 0.3 : (strlen($character) === 1 ? 0.5 : (mb_strwidth($character) >= 2 ? 1.0 : 0.6));
        }

        return $width * $size;
    }

    /**
     * The size of the body text: the size most of the characters are printed in.
     *
     * @param  list<Line>  $lines
     */
    private static function bodySize(array $lines): float
    {
        $characters = [];

        foreach ($lines as $line) {
            $key = (string) round($line['size'], 1);
            $characters[$key] = ($characters[$key] ?? 0) + mb_strlen($line['text']);
        }

        arsort($characters);

        return (float) array_key_first($characters);
    }

    /**
     * The sizes headings are printed in, biggest first: every size clearly
     * bigger than the body. The position in this list is the heading level.
     *
     * @param  list<Line>  $lines
     * @return list<float>
     */
    private static function headingSizes(array $lines, float $bodySize): array
    {
        $sizes = [];

        foreach ($lines as $line) {
            $size = round($line['size'], 1);

            if ($size >= self::HEADING_MIN_RATIO * $bodySize && ! in_array($size, $sizes, true)) {
                $sizes[] = $size;
            }
        }

        rsort($sizes);

        return $sizes;
    }

    /**
     * The heading level of a line, or null when it reads as body text.
     *
     * @param  Line  $line
     * @param  list<float>  $headingSizes
     */
    private static function headingLevel(array $line, array $headingSizes): ?int
    {
        $rank = array_search(round($line['size'], 1), $headingSizes, true);

        if ($rank === false || count($line['cells']) > 1 || mb_strlen($line['text']) > self::HEADING_MAX_CHARS || preg_match('/[。．]$/u', $line['text']) === 1) {
            return null;
        }

        return min(6, $rank + 2);
    }

    /**
     * The title: the lines at the top of the first page that together say
     * what the update list said (spaces aside), taken out; without a
     * match, the biggest lines at the top when they are bigger than the
     * body, else what the update list said.
     *
     * @param  list<Line>  $lines
     * @param  list<float>  $headingSizes
     * @return array{0: list<Line>, 1: string}
     */
    private static function takeTitle(array $lines, ?string $title, array $headingSizes): array
    {
        $listed = self::oneLine((string) $title);
        $target = self::squeeze($listed);
        $head = min(count($lines), self::HEAD_LINES);

        if ($target !== '') {
            foreach (range(0, $head - 1) as $start) {
                $accumulated = '';

                for ($end = $start; $end < $head; $end++) {
                    $accumulated .= self::squeeze($lines[$end]['text']);

                    if (! str_starts_with($target, $accumulated)) {
                        break;
                    }

                    // Enough of the title to be it (the page may print it without a last word).
                    if (mb_strlen($accumulated) >= 0.8 * mb_strlen($target)) {
                        array_splice($lines, $start, $end - $start + 1);

                        return [$lines, $listed];
                    }
                }
            }
        }

        // No update-list title, or a page that prints another one: the biggest lines at the top.
        if ($listed === '' && $headingSizes !== []) {
            foreach (range(0, $head - 1) as $start) {
                if (round($lines[$start]['size'], 1) !== $headingSizes[0]) {
                    continue;
                }

                $end = $start;

                while ($end + 1 < $head && round($lines[$end + 1]['size'], 1) === $headingSizes[0]) {
                    $end++;
                }

                $printed = self::oneLine(implode(' ', array_column(array_slice($lines, $start, $end - $start + 1), 'text')));
                array_splice($lines, $start, $end - $start + 1);

                return [$lines, $printed];
            }
        }

        return [$lines, $listed];
    }

    /**
     * The date: the first short line near the top that reads as one, taken out.
     *
     * @param  list<Line>  $lines
     * @return array{0: list<Line>, 1: ?string}
     */
    private static function takeDate(array $lines): array
    {
        foreach (array_slice($lines, 0, self::HEAD_LINES, true) as $index => $line) {
            if (mb_strlen($line['text']) <= self::DATE_LINE_MAX_CHARS && preg_match(self::DATE_TEXT_PATTERN, $line['text']) === 1) {
                array_splice($lines, $index, 1);

                return [$lines, $line['text']];
            }
        }

        return [$lines, null];
    }

    /**
     * The body: tables where lines have cells in columns, headings where
     * the print is bigger, paragraphs of the rest.
     *
     * @param  list<Line>  $lines
     * @param  list<float>  $headingSizes
     */
    private static function markdown(array $lines, float $bodySize, array $headingSizes): string
    {
        $blocks = [];
        $paragraph = [];
        $previous = null;
        $margin = self::margin($lines);
        $pitch = self::pitch($lines);
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // A run of lines with cells in columns is a table.
            $tableEnd = self::tableEnd($lines, $i, $bodySize);

            if ($tableEnd !== null) {
                self::flush($blocks, $paragraph);
                $blocks[] = self::table(array_slice($lines, $i, $tableEnd - $i + 1), $bodySize);
                $i = $tableEnd;
                $previous = null;

                continue;
            }

            $level = self::headingLevel($line, $headingSizes);

            if ($level !== null) {
                self::flush($blocks, $paragraph);
                $blocks[] = str_repeat('#', $level).' '.$line['text'];
                $previous = null;

                continue;
            }

            if ($previous !== null && self::startsParagraph($line, $previous, $margin, $pitch)) {
                self::flush($blocks, $paragraph);
            }

            $paragraph[] = $line;
            $previous = $line;
        }

        self::flush($blocks, $paragraph);

        // List items that follow one another make one list.
        return (string) preg_replace('/^(- [^\n]*)\n\n(?=- )/m', "$1\n", implode("\n\n", $blocks));
    }

    /**
     * Whether a line starts a paragraph rather than continuing the one
     * before it: a bullet, a blank line's worth of space above it, another
     * size of print, an indent after a sentence ended (a hanging note keeps its
     * indent without one), another left edge than the line before (a
     * centred or right-aligned line; a hanging line after a comma, or
     * after a line that reached the right edge, is not one), a line before
     * it that started well inside the page (centred or right-aligned, so
     * on its own), a line before it left short (justified text fills every
     * line but the last of a paragraph, so a sentence ending before the
     * right edge ends it too), or a mark that opens notes and sections.
     *
     * @param  Line  $line
     * @param  Line  $previous
     * @param  array{left: float, right: float}  $margin
     */
    private static function startsParagraph(array $line, array $previous, array $margin, float $pitch): bool
    {
        $samePage = $line['page'] === $previous['page'];
        $indented = $line['x'] > $previous['x'] + 0.7 * $line['size'];
        $sentenceEnded = preg_match(self::SENTENCE_END, $previous['text']) === 1;
        $filled = ($previous['end'] - $margin['left']) / max(1.0, $margin['right'] - $margin['left']);
        $otherEdge = abs($line['x'] - $previous['x']) > 2 * $line['size'] && $filled < 0.92 && preg_match('/[、，,]$/u', $previous['text']) !== 1;

        return $line['bullet']
            || ($samePage && 1.6 * $pitch < $previous['y'] - $line['y'])
            || abs($line['size'] - $previous['size']) > 0.5
            || ($indented && $sentenceEnded)
            || $otherEdge
            || 0.3 * ($margin['right'] - $margin['left']) < $previous['x'] - $margin['left']
            || $filled < 0.6
            || ($sentenceEnded && $filled < 0.92)
            || preg_match(self::PARAGRAPH_START, $line['text']) === 1;
    }

    /**
     * Where body lines start and end: the usual left edge, and the right
     * edge that nine lines in ten stay within (the estimate of a line's
     * end can overshoot, so the farthest one is not trusted).
     *
     * @param  list<Line>  $lines
     * @return array{left: float, right: float}
     */
    private static function margin(array $lines): array
    {
        $starts = array_count_values(array_map(fn (array $line): int => (int) round($line['x']), $lines));
        arsort($starts);
        $ends = array_column($lines, 'end');
        sort($ends);

        return ['left' => (float) array_key_first($starts), 'right' => $ends[(int) floor(0.9 * (count($ends) - 1))]];
    }

    /**
     * The usual distance between one line and the next.
     *
     * @param  list<Line>  $lines
     */
    private static function pitch(array $lines): float
    {
        $gaps = [];

        foreach ($lines as $i => $line) {
            if ($i > 0 && $lines[$i - 1]['page'] === $line['page'] && $lines[$i - 1]['y'] > $line['y']) {
                $gaps[] = $lines[$i - 1]['y'] - $line['y'];
            }
        }

        if ($gaps === []) {
            return 14.0;
        }

        sort($gaps);

        return $gaps[intdiv(count($gaps), 2)];
    }

    /**
     * Close the paragraph being collected: its lines run together, with a
     * space only between two Latin words; one that began with a bullet is
     * a list item.
     *
     * @param  list<string>  $blocks
     * @param  list<Line>  $paragraph
     */
    private static function flush(array &$blocks, array &$paragraph): void
    {
        $text = '';

        foreach ($paragraph as $line) {
            if ($text !== '' && preg_match('/[A-Za-z0-9,.;:)]$/', $text) === 1 && preg_match('/^[A-Za-z0-9(]/', $line['text']) === 1) {
                $text .= ' ';
            }

            $text .= $line['text'];
        }

        if ($text !== '') {
            $blocks[] = ($paragraph[0]['bullet'] ? '- ' : '').$text;
        }

        $paragraph = [];
    }

    /**
     * The index of the last line of the table starting at a line, or null
     * when no table starts there: at least two lines with two or more
     * cells, taken together with the lines between and after them that
     * belong to it although they have one cell: a value that wrapped
     * (its cell in a column other than the first), or a short label in
     * the first column with more of the table within two lines.
     *
     * @param  list<Line>  $lines
     */
    private static function tableEnd(array $lines, int $start, float $bodySize): ?int
    {
        if (count($lines[$start]['cells']) < 2) {
            return null;
        }

        $columns = array_column($lines[$start]['cells'], 'x');
        $first = min($columns);
        $multiCell = 1;
        $end = $start;
        $count = count($lines);

        for ($i = $start + 1; $i < $count; $i++) {
            $cells = $lines[$i]['cells'];

            if (count($cells) >= 2) {
                foreach ($cells as $cell) {
                    if (self::column($columns, $cell['x'], $bodySize) === null) {
                        $columns[] = $cell['x'];
                    }
                }

                $multiCell++;
                $end = $i;

                continue;
            }

            // A label as wide as its column leaves no gap before the value: cut at the columns known so far, it may still be a row.
            if (self::isRow(self::cellsAtColumns($lines[$i], $columns, $bodySize)['cells'], $columns, $bodySize)) {
                $multiCell++;
                $end = $i;

                continue;
            }

            $column = self::column($columns, $cells[0]['x'], $bodySize);

            if ($column === null) {
                break;
            }

            // A wrapped value is part of the table wherever it stands; a label only when the table goes on after it.
            if (abs($columns[$column] - $first) > 1.5 * $bodySize) {
                $end = $i;

                continue;
            }

            $goesOn = false;

            for ($ahead = $i + 1; $ahead <= min($i + 2, $count - 1); $ahead++) {
                $aheadCells = $lines[$ahead]['cells'];
                $aheadColumn = self::column($columns, $aheadCells[0]['x'], $bodySize);
                $goesOn = $goesOn || count($aheadCells) >= 2 || ($aheadColumn !== null && abs($columns[$aheadColumn] - $first) > 1.5 * $bodySize);
            }

            if (mb_strlen($cells[0]['text']) > 20 || ! $goesOn) {
                break;
            }
        }

        return $multiCell >= 2 ? $end : null;
    }

    /**
     * Whether cells cut at the columns of a table make a row of it: two or
     * more, each in its own column from the first on in order, the first
     * short enough for a label.
     *
     * @param  list<Cell>  $cells
     * @param  list<float>  $columns
     */
    private static function isRow(array $cells, array $columns, float $bodySize): bool
    {
        if (count($cells) < 2 || mb_strlen($cells[0]['text']) > 20) {
            return false;
        }

        $previous = -1;

        foreach ($cells as $index => $cell) {
            $column = self::columnOfCell($columns, $cell['x'], $bodySize, $index === 0);

            if ($column === null || $column <= $previous || ($index === 0 && $column !== 0)) {
                return false;
            }

            $previous = $column;
        }

        return true;
    }

    /**
     * The column a cell belongs to, the first column taken more loosely
     * for a first cell (a centred label starts where its length puts it).
     *
     * @param  list<float>  $columns
     */
    private static function columnOfCell(array $columns, float $x, float $bodySize, bool $first): ?int
    {
        $column = self::column($columns, $x, $bodySize);

        if ($column === null && $first && $columns !== [] && abs(min($columns) - $x) <= 2.5 * $bodySize) {
            return (int) array_search(min($columns), $columns, true);
        }

        return $column;
    }

    /**
     * The column a cell belongs to: the one whose left edge is closest,
     * when it is closer than a character and a half.
     *
     * @param  list<float>  $columns
     */
    private static function column(array $columns, float $x, float $bodySize): ?int
    {
        $best = null;

        foreach ($columns as $index => $column) {
            if (abs($column - $x) <= 1.5 * $bodySize && ($best === null || abs($column - $x) < abs($columns[$best] - $x))) {
                $best = $index;
            }
        }

        return $best;
    }

    /**
     * The lines of a table as a Markdown table. The cells of the first
     * column anchor the rows; every other cell joins the row whose anchor
     * is nearest in height, so a label centred beside two lines of value
     * gets both. As with the HTML tables, the first row serves as header.
     *
     * @param  list<Line>  $lines
     */
    private static function table(array $lines, float $bodySize): string
    {
        // The columns are where the lines with two or more cells start theirs.
        $columns = [];

        foreach ($lines as $line) {
            foreach (count($line['cells']) >= 2 ? $line['cells'] : [] as $cell) {
                if (self::column($columns, $cell['x'], $bodySize) === null) {
                    $columns[] = $cell['x'];
                }
            }
        }

        sort($columns);
        $columns = self::mergeColumns($columns, $lines, $bodySize);

        // With the columns known, a line is cut again wherever a piece starts in one: a label as wide as its column leaves no gap before the value.
        $lines = array_map(fn (array $line): array => self::cellsAtColumns($line, $columns, $bodySize), $lines);

        // Rows are anchored by the first column; a table without one anchors every line.
        $anchors = [];

        foreach ($lines as $line) {
            if (self::columnOfCell($columns, $line['cells'][0]['x'], $bodySize, true) === 0) {
                $anchors[] = $line['y'];
            }
        }

        if ($anchors === []) {
            $anchors = array_column($lines, 'y');
        }

        $rows = array_fill(0, count($anchors), array_fill(0, count($columns), ''));

        foreach ($lines as $line) {
            foreach ($line['cells'] as $index => $cell) {
                // A cell in no column goes to the nearest one.
                $column = self::columnOfCell($columns, $cell['x'], $bodySize, $index === 0) ?? self::nearest($columns, $cell['x']);
                $row = 0;

                foreach ($anchors as $index => $anchor) {
                    if (abs($anchor - $line['y']) < abs($anchors[$row] - $line['y'])) {
                        $row = $index;
                    }
                }

                $rows[$row][$column] = self::oneLine($rows[$row][$column].(preg_match('/[A-Za-z0-9]$/', $rows[$row][$column]) === 1 ? ' ' : '').$cell['text']);
            }
        }

        $markdown = [];

        foreach ($rows as $index => $row) {
            $markdown[] = '| '.implode(' | ', array_map(fn (string $cell): string => str_replace('|', '\\|', $cell), $row)).' |';

            if ($index === 0) {
                $markdown[] = '|'.str_repeat('---|', count($columns));
            }
        }

        return implode("\n", $markdown);
    }

    /**
     * The cells of a line cut at the columns of its table: a new cell
     * starts at every piece that starts in a column other than the one
     * the text so far is in.
     *
     * @param  Line  $line
     * @param  list<float>  $columns
     * @return Line
     */
    private static function cellsAtColumns(array $line, array $columns, float $bodySize): array
    {
        $cells = [];
        $text = '';
        $cellX = 0.0;
        $column = null;

        foreach ($line['pieces'] as $piece) {
            $at = self::column($columns, $piece['x'], $bodySize);

            if ($text !== '' && $at !== null && $at !== $column && self::oneLine($text) !== '') {
                $cells[] = ['x' => $cellX, 'text' => self::oneLine($text)];
                $text = '';
            }

            if (self::oneLine($text) === '') {
                $cellX = $piece['x'];
                $column = $at ?? $column;
            }

            $text .= $piece['text'];
        }

        if (self::oneLine($text) !== '') {
            $cells[] = ['x' => $cellX, 'text' => self::oneLine($text)];
        }

        return [...$line, 'cells' => $cells];
    }

    /**
     * Two neighbouring columns that never share a line are one column
     * whose cells are aligned differently (a centred heading over
     * left-aligned values): the right one is dropped.
     *
     * @param  list<float>  $columns
     * @param  list<Line>  $lines
     * @return list<float>
     */
    private static function mergeColumns(array $columns, array $lines, float $bodySize): array
    {
        for ($index = 0; $index + 1 < count($columns);) {
            $shareALine = false;

            foreach ($lines as $line) {
                $at = array_map(fn (array $cell): ?int => self::column($columns, $cell['x'], $bodySize), $line['cells']);
                $shareALine = $shareALine || (in_array($index, $at, true) && in_array($index + 1, $at, true));
            }

            if ($shareALine) {
                $index++;
            } else {
                array_splice($columns, $index + 1, 1);
            }
        }

        return $columns;
    }

    /**
     * The index of the column closest to a position.
     *
     * @param  list<float>  $columns
     */
    private static function nearest(array $columns, float $x): int
    {
        $best = 0;

        foreach ($columns as $index => $column) {
            if (abs($column - $x) < abs($columns[$best] - $x)) {
                $best = $index;
            }
        }

        return $best;
    }

    /**
     * Text with its whitespace collapsed to single spaces, none before a
     * closing mark (justification leaves one).
     */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace(['/\s+/u', '/ (?=[、。，．）」』])/u'], [' ', ''], $text));
    }

    /**
     * Text with no whitespace at all, to compare what is printed with what was listed.
     */
    private static function squeeze(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }
}
