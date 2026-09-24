<?php

namespace App\Pdf;

use Smalot\PdfParser\Document;
use Smalot\PdfParser\Page;

/**
 * The Markdown of a PDF, read from the position and size of its text:
 * pieces on a baseline make a line, wide gaps split it into cells, cells
 * in columns make a table, bigger print a heading, the rest paragraphs.
 * Made for single-column documents such as press releases saved from Word.
 *
 * @phpstan-type Piece array{x: float, y: float, size: float, text: string, index: int}
 * @phpstan-type Cell array{x: float, text: string}
 * @phpstan-type Line array{page: int, y: float, x: float, end: float, size: float, cells: list<Cell>, text: string, pieces: list<Piece>, bullet: bool}
 */
final class PdfMarkdown
{
    /** A line at least this much bigger than the body is a heading. */
    private const HEADING_MIN_RATIO = 1.1;

    /** A longer line is never a heading. */
    private const HEADING_MAX_CHARS = 80;

    /** Points from the top or bottom edge of the page where page numbers live. */
    private const EDGE = 60.0;

    /** A page number: "1", "1/2", "- 3 -", "2 / 5". */
    private const PAGE_NUMBER_PATTERN = '/^[\s\d\/\-－‐ー]+$/u';

    /** A printed date: 2026年8月26日, 2026-08-26, 26.08.2026, August 26, 2026. */
    private const DATE_TEXT_PATTERN = '/\d{4}[年.\/-]\d{1,2}[月.\/-]\d{1,2}|\b\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4}\b|\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.? \d{1,2},? \d{4}\b|\b\d{1,2}\.? (jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.? \d{4}\b/iu';

    /** A longer line is never the date line. */
    private const DATE_LINE_MAX_CHARS = 40;

    /** How many lines from the top the title and the date are looked for in. */
    private const HEAD_LINES = 15;

    /** The end of a sentence: an indented line after it starts a paragraph. */
    private const SENTENCE_END = '/[。．.!?！？」』）)】]$/u';

    /** Marks that start a paragraph: notes, bracketed section names, bullets. */
    private const PARAGRAPH_START = '/^[※【＜＞■●◆◇○▼▲・]/u';

    /** A piece that is only a bullet: a private-use glyph (Word's Wingdings) or a bullet character. */
    private const BULLET_PATTERN = '/^[\x{E000}-\x{F8FF}•●○■□◆◇▪▫‣・]$/u';

    /** Private-use glyphs elsewhere, which are dropped. */
    private const PRIVATE_USE_PATTERN = '/[\x{E000}-\x{F8FF}]/u';

    /** Control characters other than tab and newline, which the database cannot store. */
    private const CONTROL_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

    /**
     * Read the title, the date line and the body of a PDF.
     *
     * @return array{title: string, date: ?string, body: string} the title as printed (empty when none was found), the date line as printed, the body as Markdown
     */
    public function __invoke(Document $document, ?string $title = null): array
    {
        $lines = [];

        // The lines of every page, in order.
        foreach ($document->getPages() as $page) {
            $lines = [...$lines, ...self::lines($page)];
        }

        // No text at all.
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
     * The lines of one page, top to bottom, with page numbers left out.
     *
     * @return list<Line>
     */
    private static function lines(Page $page): array
    {
        $pieces = [];

        // Each positioned text with its size.
        foreach ($page->getDataTm() as $index => $data) {
            [$matrix, $text] = $data;
            $size = abs((float) ($matrix[0] ?: $matrix[3])) * (float) ($data[3] ?? 1);
            // Stray bytes from an unknown font encoding are dropped.
            $text = (string) preg_replace(self::CONTROL_PATTERN, '', mb_scrub($text, 'UTF-8'));

            // Nothing drawn.
            if ($text === '' || $size <= 0) {
                continue;
            }

            $pieces[] = ['x' => (float) $matrix[4], 'y' => (float) $matrix[5], 'size' => $size, 'text' => $text, 'index' => $index];
        }

        // Top to bottom; pieces on the same baseline keep the order they were drawn in.
        usort($pieces, fn (array $a, array $b): int => $b['y'] <=> $a['y'] ?: $a['index'] <=> $b['index']);

        $lines = [];
        $current = null;

        // Group the pieces into lines.
        foreach ($pieces as $piece) {
            // Same line when the baselines are close for the bigger size, so a footnote mark stays in its line.
            if ($current !== null && abs($piece['y'] - $current['y']) <= 0.6 * max($piece['size'], $current['size'])) {
                $current['pieces'][] = $piece;

                // The line takes the size and baseline of its biggest piece.
                if ($piece['size'] > $current['size']) {
                    $current['size'] = $piece['size'];
                    $current['y'] = $piece['y'];
                }

                continue;
            }

            // Close the line before and start a new one.
            if ($current !== null) {
                $lines[] = $current;
            }

            $current = ['y' => $piece['y'], 'size' => $piece['size'], 'pieces' => [$piece]];
        }

        // Close the last line.
        if ($current !== null) {
            $lines[] = $current;
        }

        $height = (float) ($page->getDetails()['MediaBox'][3] ?? 842);
        $number = $page->getPageNumber();
        $result = [];

        // Cut each line into cells, dropping empty lines and page numbers.
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
     * The pieces of a line, left to right, run into cells: a gap wider than
     * two characters starts a new cell. The pen estimates where the text so
     * far ends, since a piece gives only its start.
     *
     * @param  array{y: float, size: float, pieces: list<Piece>}  $line
     * @return Line
     */
    private static function cells(array $line, int $page): array
    {
        $pieces = $line['pieces'];
        usort($pieces, fn (array $a, array $b): int => $a['x'] <=> $b['x'] ?: $a['index'] <=> $b['index']);

        // A leading bullet makes a list item; the glyph is dropped.
        $bullet = false;

        if (count($pieces) > 1 && preg_match(self::BULLET_PATTERN, $pieces[0]['text']) === 1) {
            $bullet = true;
            array_shift($pieces);
        }

        $cells = [];
        $text = '';
        $cellX = 0.0;
        $pen = null;

        // Run the pieces together, cutting at wide gaps.
        foreach ($pieces as $piece) {
            $piece['text'] = (string) preg_replace(self::PRIVATE_USE_PATTERN, '', $piece['text']);
            $gap = $pen === null ? 0.0 : $piece['x'] - $pen;

            // A wide gap closes the cell (unless it is only spaces).
            if ($text !== '' && $gap > 2 * $line['size']) {
                if (self::oneLine($text) !== '') {
                    $cells[] = ['x' => $cellX, 'text' => self::oneLine($text)];
                }

                $text = '';
            }

            // A new cell starts here, or a space goes between two Latin words.
            if ($text === '') {
                $cellX = $piece['x'];
            } elseif ($gap > 0.25 * $piece['size'] && preg_match('/[A-Za-z0-9]$/', $text) === 1 && preg_match('/^[A-Za-z0-9]/', $piece['text']) === 1) {
                $text .= ' ';
            }

            $text .= $piece['text'];
            $pen = max($pen ?? $piece['x'], $piece['x']) + self::width($piece['text'], $piece['size']);
        }

        // Close the last cell.
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

    /** A text's rough drawn width: full-width characters 1 em, Latin 0.5, space 0.3. */
    private static function width(string $text, float $size): float
    {
        $width = 0.0;

        // Add up each character's width.
        foreach (mb_str_split($text) as $character) {
            $width += $character === ' ' ? 0.3 : (strlen($character) === 1 ? 0.5 : (mb_strwidth($character) >= 2 ? 1.0 : 0.6));
        }

        return $width * $size;
    }

    /**
     * The body size: the size most characters are printed in.
     *
     * @param  list<Line>  $lines
     */
    private static function bodySize(array $lines): float
    {
        $characters = [];

        // Count the characters per size.
        foreach ($lines as $line) {
            $key = (string) round($line['size'], 1);
            $characters[$key] = ($characters[$key] ?? 0) + mb_strlen($line['text']);
        }

        arsort($characters);

        return (float) array_key_first($characters);
    }

    /**
     * The heading sizes, biggest first (the index is the level): every size
     * at least HEADING_MIN_RATIO of the body's.
     *
     * @param  list<Line>  $lines
     * @return list<float>
     */
    private static function headingSizes(array $lines, float $bodySize): array
    {
        $sizes = [];

        // Each distinct size big enough.
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

        // Not a heading size, several cells, too long, or ending a sentence: body text.
        if ($rank === false || count($line['cells']) > 1 || mb_strlen($line['text']) > self::HEADING_MAX_CHARS || preg_match('/[。．]$/u', $line['text']) === 1) {
            return null;
        }

        return min(6, $rank + 2);
    }

    /**
     * Take out the title: the top lines that spell the listed title (spaces
     * aside); with no listed title, the biggest lines at the top; else the
     * listed title is returned and nothing taken.
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

        // Look for consecutive top lines that spell the listed title.
        if ($target !== '') {
            foreach (range(0, $head - 1) as $start) {
                $accumulated = '';

                for ($end = $start; $end < $head; $end++) {
                    $accumulated .= self::squeeze($lines[$end]['text']);

                    // No longer a prefix of the title.
                    if (! str_starts_with($target, $accumulated)) {
                        break;
                    }

                    // 80% is enough: the page may print it without a last word.
                    if (mb_strlen($accumulated) >= 0.8 * mb_strlen($target)) {
                        array_splice($lines, $start, $end - $start + 1);

                        return [$lines, $listed];
                    }
                }
            }
        }

        // No listed title: the first run of lines in the biggest size.
        if ($listed === '' && $headingSizes !== []) {
            foreach (range(0, $head - 1) as $start) {
                // Skip to the first line in the biggest size.
                if (round($lines[$start]['size'], 1) !== $headingSizes[0]) {
                    continue;
                }

                $end = $start;

                // Extend over the lines that follow in that size.
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
     * Take out the date: the first short line near the top that reads as one.
     *
     * @param  list<Line>  $lines
     * @return array{0: list<Line>, 1: ?string}
     */
    private static function takeDate(array $lines): array
    {
        // The first top line short enough and date-like.
        foreach (array_slice($lines, 0, self::HEAD_LINES, true) as $index => $line) {
            if (mb_strlen($line['text']) <= self::DATE_LINE_MAX_CHARS && preg_match(self::DATE_TEXT_PATTERN, $line['text']) === 1) {
                array_splice($lines, $index, 1);

                return [$lines, $line['text']];
            }
        }

        return [$lines, null];
    }

    /**
     * The body as Markdown: tables, headings, then paragraphs and list items.
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

        // Each line becomes part of a table, a heading, or a paragraph.
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

            // A heading line.
            if ($level !== null) {
                self::flush($blocks, $paragraph);
                $blocks[] = str_repeat('#', $level).' '.$line['text'];
                $previous = null;

                continue;
            }

            // A new paragraph closes the one before.
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
     * Whether a line starts a paragraph: a bullet; a blank line above; another
     * size; an indent after a sentence end; another left edge (not after a
     * comma or a full line); a previous line starting well inside the page
     * (centred or right-aligned) or left short (justified text fills every
     * line but a paragraph's last); or a PARAGRAPH_START mark.
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
     * The body's left edge (the most common start) and right edge (the 90th
     * percentile of line ends, since the end estimate can overshoot).
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

        // Gaps between consecutive lines on the same page.
        foreach ($lines as $i => $line) {
            if ($i > 0 && $lines[$i - 1]['page'] === $line['page'] && $lines[$i - 1]['y'] > $line['y']) {
                $gaps[] = $lines[$i - 1]['y'] - $line['y'];
            }
        }

        // No gaps: a typical pitch.
        if ($gaps === []) {
            return 14.0;
        }

        sort($gaps);

        return $gaps[intdiv(count($gaps), 2)];
    }

    /**
     * Close the paragraph: its lines joined (a space only between Latin
     * words), a list item when it began with a bullet.
     *
     * @param  list<string>  $blocks
     * @param  list<Line>  $paragraph
     */
    private static function flush(array &$blocks, array &$paragraph): void
    {
        $text = '';

        // Join the lines.
        foreach ($paragraph as $line) {
            if ($text !== '' && preg_match('/[A-Za-z0-9,.;:)]$/', $text) === 1 && preg_match('/^[A-Za-z0-9(]/', $line['text']) === 1) {
                $text .= ' ';
            }

            $text .= $line['text'];
        }

        // Add the block when there is text.
        if ($text !== '') {
            $blocks[] = ($paragraph[0]['bullet'] ? '- ' : '').$text;
        }

        $paragraph = [];
    }

    /**
     * The last line of a table starting at a line, or null: at least two
     * multi-cell lines, with the one-cell lines that belong to them (a
     * wrapped value outside the first column, or a short first-column label
     * with more table within two lines).
     *
     * @param  list<Line>  $lines
     */
    private static function tableEnd(array $lines, int $start, float $bodySize): ?int
    {
        // A table starts with a multi-cell line.
        if (count($lines[$start]['cells']) < 2) {
            return null;
        }

        $columns = array_column($lines[$start]['cells'], 'x');
        $first = min($columns);
        $multiCell = 1;
        $end = $start;
        $count = count($lines);

        // Extend the table line by line.
        for ($i = $start + 1; $i < $count; $i++) {
            $cells = $lines[$i]['cells'];

            // A multi-cell line: a row, adding any new columns.
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

            // In no column: the table has ended.
            if ($column === null) {
                break;
            }

            // A wrapped value is part of the table wherever it stands; a label only when the table goes on after it.
            if (abs($columns[$column] - $first) > 1.5 * $bodySize) {
                $end = $i;

                continue;
            }

            $goesOn = false;

            // Whether the table goes on within two lines.
            for ($ahead = $i + 1; $ahead <= min($i + 2, $count - 1); $ahead++) {
                $aheadCells = $lines[$ahead]['cells'];
                $aheadColumn = self::column($columns, $aheadCells[0]['x'], $bodySize);
                $goesOn = $goesOn || count($aheadCells) >= 2 || ($aheadColumn !== null && abs($columns[$aheadColumn] - $first) > 1.5 * $bodySize);
            }

            // Too long for a label, or the table does not go on.
            if (mb_strlen($cells[0]['text']) > 20 || ! $goesOn) {
                break;
            }
        }

        return $multiCell >= 2 ? $end : null;
    }

    /**
     * Whether cells make a row: two or more, in increasing columns from the
     * first, the first short enough for a label.
     *
     * @param  list<Cell>  $cells
     * @param  list<float>  $columns
     */
    private static function isRow(array $cells, array $columns, float $bodySize): bool
    {
        // Too few cells, or the first too long.
        if (count($cells) < 2 || mb_strlen($cells[0]['text']) > 20) {
            return false;
        }

        $previous = -1;

        // Each cell in a later column than the one before, the first in column 0.
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
     * The column of a cell, the first column matched more loosely for a
     * first cell (a centred label).
     *
     * @param  list<float>  $columns
     */
    private static function columnOfCell(array $columns, float $x, float $bodySize, bool $first): ?int
    {
        $column = self::column($columns, $x, $bodySize);

        // A first cell within 2.5 characters of the first column.
        if ($column === null && $first && $columns !== [] && abs(min($columns) - $x) <= 2.5 * $bodySize) {
            return (int) array_search(min($columns), $columns, true);
        }

        return $column;
    }

    /**
     * The column whose left edge is closest, within 1.5 characters.
     *
     * @param  list<float>  $columns
     */
    private static function column(array $columns, float $x, float $bodySize): ?int
    {
        $best = null;

        // The closest within reach.
        foreach ($columns as $index => $column) {
            if (abs($column - $x) <= 1.5 * $bodySize && ($best === null || abs($column - $x) < abs($columns[$best] - $x))) {
                $best = $index;
            }
        }

        return $best;
    }

    /**
     * The lines of a table as a Markdown table: first-column cells anchor
     * the rows, every other cell joins the row nearest in height (so a label
     * centred beside two lines of value gets both); the first row is the header.
     *
     * @param  list<Line>  $lines
     */
    private static function table(array $lines, float $bodySize): string
    {
        // The columns are where the multi-cell lines start their cells.
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

        // Cut the lines again at the columns: a label as wide as its column leaves no gap.
        $lines = array_map(fn (array $line): array => self::cellsAtColumns($line, $columns, $bodySize), $lines);

        // Rows are anchored by first-column cells.
        $anchors = [];

        foreach ($lines as $line) {
            if (self::columnOfCell($columns, $line['cells'][0]['x'], $bodySize, true) === 0) {
                $anchors[] = $line['y'];
            }
        }

        // None: every line is a row.
        if ($anchors === []) {
            $anchors = array_column($lines, 'y');
        }

        $rows = array_fill(0, count($anchors), array_fill(0, count($columns), ''));

        // Put each cell into its row and column.
        foreach ($lines as $line) {
            foreach ($line['cells'] as $index => $cell) {
                // A cell in no column goes to the nearest one.
                $column = self::columnOfCell($columns, $cell['x'], $bodySize, $index === 0) ?? self::nearest($columns, $cell['x']);
                $row = 0;

                // The row whose anchor is nearest in height.
                foreach ($anchors as $index => $anchor) {
                    if (abs($anchor - $line['y']) < abs($anchors[$row] - $line['y'])) {
                        $row = $index;
                    }
                }

                $rows[$row][$column] = self::oneLine($rows[$row][$column].(preg_match('/[A-Za-z0-9]$/', $rows[$row][$column]) === 1 ? ' ' : '').$cell['text']);
            }
        }

        $markdown = [];

        // Rows as Markdown, a separator after the header.
        foreach ($rows as $index => $row) {
            $markdown[] = '| '.implode(' | ', array_map(fn (string $cell): string => str_replace('|', '\\|', $cell), $row)).' |';

            if ($index === 0) {
                $markdown[] = '|'.str_repeat('---|', count($columns));
            }
        }

        return implode("\n", $markdown);
    }

    /**
     * A line's cells cut at the table's columns: a piece starting in another
     * column starts a new cell.
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

        // Run the pieces together, cutting at a change of column.
        foreach ($line['pieces'] as $piece) {
            $at = self::column($columns, $piece['x'], $bodySize);

            if ($text !== '' && $at !== null && $at !== $column && self::oneLine($text) !== '') {
                $cells[] = ['x' => $cellX, 'text' => self::oneLine($text)];
                $text = '';
            }

            // A cell starts here.
            if (self::oneLine($text) === '') {
                $cellX = $piece['x'];
                $column = $at ?? $column;
            }

            $text .= $piece['text'];
        }

        // Close the last cell.
        if (self::oneLine($text) !== '') {
            $cells[] = ['x' => $cellX, 'text' => self::oneLine($text)];
        }

        return [...$line, 'cells' => $cells];
    }

    /**
     * Merge neighbouring columns that never share a line (a centred heading
     * over left-aligned values) by dropping the right one.
     *
     * @param  list<float>  $columns
     * @param  list<Line>  $lines
     * @return list<float>
     */
    private static function mergeColumns(array $columns, array $lines, float $bodySize): array
    {
        // Each neighbouring pair in turn.
        for ($index = 0; $index + 1 < count($columns);) {
            $shareALine = false;

            // Whether any line has cells in both.
            foreach ($lines as $line) {
                $at = array_map(fn (array $cell): ?int => self::column($columns, $cell['x'], $bodySize), $line['cells']);
                $shareALine = $shareALine || (in_array($index, $at, true) && in_array($index + 1, $at, true));
            }

            // Keep both and move on, or drop the right one.
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

        // The closest.
        foreach ($columns as $index => $column) {
            if (abs($column - $x) < abs($columns[$best] - $x)) {
                $best = $index;
            }
        }

        return $best;
    }

    /** Whitespace collapsed to single spaces, none before a closing mark. */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace(['/\s+/u', '/ (?=[、。，．）」』])/u'], [' ', ''], $text));
    }

    /** Text without whitespace, to compare printed and listed titles. */
    private static function squeeze(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }
}
