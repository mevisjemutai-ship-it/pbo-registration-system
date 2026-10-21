<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Laying out a document that Excel opens properly
|--------------------------------------------------------------------------
|
| Both exports are HTML served as application/vnd.ms-excel. Excel reads
| that HTML, but it reads very little of the CSS, and the three things it
| ignores are the three things the previous layout depended on.
|
| 1. ONE CELL PER <td>, AND display:block MEANS NOTHING.
|
|    The old layout put a label and its value in the same cell, stacked
|    with display:block. A browser shows two lines. Excel shows one cell
|    containing both strings run together: "CountyBaringo",
|    "Registration Number20397", and the whole masthead - authority,
|    title and organization name - crushed into cell A1.
|
|    So a label and its value now go in SEPARATE CELLS. That is also how
|    a person expects a spreadsheet to behave: they can sort it, filter
|    it, and copy a column of values out.
|
| 2. COLUMN WIDTHS COME FROM <col width>, IN PIXELS.
|
|    A percentage width tells Excel nothing, so every column came out the
|    same narrow default and the content was squeezed. The widths below
|    are pixels, and they add up to the printable width of A4 portrait.
|
| 3. EXCEL RETYPES ANYTHING THAT LOOKS NUMERIC.
|
|    This was corrupting data, not just appearance: the telephone number
|    0111053310 was arriving in the register as 111053310, because Excel
|    read it as a number and dropped the leading zero. Postal codes, ID
|    numbers and KRA PINs are all at risk the same way.
|
|    Every value cell therefore carries mso-number-format:'\@', which
|    tells Excel to keep it as text exactly as written.
|
| Both exports share this file. They were drifting apart - a fix applied
| to one and forgotten in the other is how the register kept printing
| dashes after they had been removed from the record.
|
*/


/** Columns in the grid. 12 divides into halves, thirds, quarters, sixths. */
const XL_GRID = 12;

/**
 * Width of one column, in pixels.
 *
 * 12 x 58 = 696, which is the printable width of A4 portrait at the
 * half-inch margins set below (8.27in - 1in = 7.27in, ~698px at 96dpi).
 * Wider than this and the last column prints on a second sheet.
 */
const XL_COL_WIDTH = 58;


/** Escape for HTML. */
function xl(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


/** Is there anything here worth a cell? */
function xlFilled(mixed $value): bool
{
    return trim((string) $value) !== '';
}


/**
 * The column definition every table repeats.
 *
 * Excel builds one worksheet grid from these. Every table must declare
 * the same columns or Excel merges and splits them to fit each table in
 * turn, and the sections come out at different widths.
 */
function xlGrid(): string
{
    $html = '<colgroup>';

    for ($i = 0; $i < XL_GRID; $i++) {
        $html .= '<col width="' . XL_COL_WIDTH . '" style="width:'
            . round(XL_COL_WIDTH * 0.75, 2) . 'pt">';
    }

    return $html . '</colgroup>';
}


/**
 * A value cell: text, kept as text.
 *
 * $class is for alignment and weight; the inline style is what stops
 * Excel turning a phone number into a number.
 */
function xlCell(mixed $value, int $span, string $class = 'v'): string
{
    return '<td colspan="' . $span . '" class="' . $class
        . '" style="mso-number-format:\'\\@\'">'
        . nl2br(xl(trim((string) $value)))
        . '</td>';
}


/** A label cell. Labels never hold data, so they need no text format. */
function xlLabel(string $text, int $span): string
{
    return '<td colspan="' . $span . '" class="l">' . xl($text) . '</td>';
}


/** A column heading. */
function xlHeading(string $text, int $span, string $class = ''): string
{
    return '<th colspan="' . $span . '"'
        . ($class !== '' ? ' class="' . $class . '"' : '')
        . '>' . xl($text) . '</th>';
}


/**
 * Pad a row back out to the full grid.
 *
 * A row that stops short leaves Excel to guess, and it guesses by
 * splitting a column somewhere - which throws every section below it out
 * of alignment. The padding cell is borderless and empty.
 */
function xlPad(int $used): string
{
    $short = XL_GRID - $used;

    return $short > 0
        ? '<td colspan="' . $short . '" class="pad"></td>'
        : '';
}


/**
 * One field as a label cell and a value cell, or nothing at all.
 *
 * Nothing at all is deliberate. A labelled box with an empty cell beside
 * it reads as a field somebody failed to complete, when usually it does
 * not apply: most organizations have no second telephone number.
 */
function xlField(string $label, mixed $value, int $labelSpan = 2, int $valueSpan = 4): string
{
    if (!xlFilled($value)) {
        return '';
    }

    return xlLabel($label, $labelSpan) . xlCell($value, $valueSpan);
}


/**
 * Lay fields out as label/value pairs, skipping the empty ones.
 *
 * $perRow pairs per row; the remainder of the grid is padded. Two pairs
 * per row gives each value four columns, which fits an address.
 *
 * @param array<string, mixed> $fields
 */
function xlFieldRows(array $fields, int $perRow = 2): array
{
    $pairWidth = intdiv(XL_GRID, $perRow);
    $labelSpan = max(2, intdiv($pairWidth, 3));
    $valueSpan = $pairWidth - $labelSpan;

    $pairs = [];

    foreach ($fields as $label => $value) {
        $pair = xlField((string) $label, $value, $labelSpan, $valueSpan);

        if ($pair !== '') {
            $pairs[] = $pair;
        }
    }

    if ($pairs === []) {
        return ['html' => '', 'count' => 0];
    }

    $html = '';

    foreach (array_chunk($pairs, $perRow) as $row) {
        $html .= '<tr>' . implode('', $row)
            . xlPad(count($row) * $pairWidth) . '</tr>';
    }

    return ['html' => $html, 'count' => count($pairs)];
}


/** Just the markup, for the common case. */
function xlFields(array $fields, int $perRow = 2): string
{
    return xlFieldRows($fields, $perRow)['html'];
}


/**
 * A numbered section band.
 *
 * The number, the title and the subtitle are three cells, not three
 * spans in one: in Excel a span is invisible and they would run together
 * as "1Organization DetailsHeadquarters address and contact particulars",
 * which is what the old masthead did.
 */
function xlBand(string $number, string $title, string $subtitle = ''): string
{
    $html = '<table class="band">' . xlGrid()
        . '<tr class="band-row">'
        . '<td colspan="1" class="band-no">' . xl($number) . '</td>'
        . '<td colspan="' . ($subtitle !== '' ? 5 : 11) . '" class="band-title">'
        . xl($title) . '</td>';

    if ($subtitle !== '') {
        $html .= '<td colspan="6" class="band-sub">' . xl($subtitle) . '</td>';
    }

    return $html . '</tr></table>';
}


/** A quieter heading inside a section. */
function xlSub(string $title, string $note = ''): string
{
    $noteSpan = $note !== '' ? 4 : 0;

    return '<tr class="sub">'
        . '<td colspan="' . (XL_GRID - $noteSpan) . '" class="sub-title">'
        . xl($title) . '</td>'
        . ($note !== ''
            ? '<td colspan="' . $noteSpan . '" class="sub-note">' . xl($note) . '</td>'
            : '')
        . '</tr>';
}


/** Vertical space that survives the trip into Excel. */
function xlGap(): string
{
    return '<table class="gap">' . xlGrid()
        . '<tr><td colspan="' . XL_GRID . '"></td></tr></table>';
}


/**
 * The stylesheet both exports share.
 *
 * Excel reads borders, background colours, bold and alignment from this
 * and ignores nearly everything else; the rest is for the print view,
 * which is ordinary HTML in a browser.
 */
function xlStyles(): string
{
    return <<<'CSS'
@page { size: A4 portrait; margin: 0.5in; }

body {
    font-family: Calibri, Arial, Helvetica, sans-serif;
    font-size: 10pt;
    color: #1b2b38;
    margin: 0;
    padding: 0;
    background: #ffffff;
}

table {
    width: 696px;
    border-collapse: collapse;
    table-layout: fixed;
    margin: 0;
}

td, th {
    border: 1px solid #dfe7ed;
    padding: 6px 8px;
    vertical-align: middle;
    word-wrap: break-word;
    overflow-wrap: break-word;
    font-size: 10pt;
}

/* Column headings. */
th {
    background: #eef3f7;
    color: #41535f;
    font-size: 8.5pt;
    font-weight: bold;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: .05em;
}

th.left { text-align: left; }

/* Label cell: quiet, right against the value it names. */
td.l {
    background: #f7fafc;
    color: #41535f;
    font-size: 8.5pt;
    font-weight: bold;
    text-align: right;
    text-transform: uppercase;
    letter-spacing: .04em;
}

/* Value cell. */
td.v {
    font-weight: bold;
    color: #16242f;
    text-align: left;
}

td.c { text-align: center; }
td.plain { font-weight: normal; }

/* Padding cell: there for the grid, not for the reader. */
td.pad {
    border-color: #ffffff;
    background: #ffffff;
}

/* ---- masthead ---- */

.cover td {
    background: #0f3c5f;
    color: #ffffff;
    border-color: #0f3c5f;
    text-align: center;
}

.cover-authority {
    font-size: 8.5pt;
    font-weight: bold;
    letter-spacing: .12em;
    color: #9dc4e0;
    padding: 10px 8px 2px;
}

.cover-title {
    font-size: 18pt;
    font-weight: bold;
    padding: 2px 8px;
}

.cover-name {
    font-size: 12pt;
    color: #d9e8f3;
    padding: 2px 8px 10px;
}

/* ---- section bands ---- */

.band-row td {
    background: #0f3c5f;
    color: #ffffff;
    border-color: #0f3c5f;
    padding: 8px 10px;
}

.band-no {
    font-size: 11pt;
    font-weight: bold;
    color: #9dc4e0;
    text-align: center;
}

.band-title {
    font-size: 12pt;
    font-weight: bold;
    text-align: left;
}

.band-sub {
    font-size: 8.5pt;
    color: #b8d4e8;
    text-align: right;
}

.sub td {
    background: #f1f6fa;
    border-top: 2px solid #c79418;
    color: #41535f;
    font-size: 9pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.sub-title { text-align: left; }
.sub-note { text-align: right; font-weight: normal; text-transform: none; }

.gap td {
    border: 0;
    height: 10px;
    padding: 0;
    background: #ffffff;
}

.footnote td {
    border: 0;
    padding: 10px 2px 0;
    color: #8a97a2;
    font-size: 8pt;
    text-align: left;
}
CSS;
}
