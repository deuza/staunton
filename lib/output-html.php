<?php

/**
 * output-html.php - renders the diagram as a static HTML page.
 *
 * The page produced is self-contained: the piece SVGs are inlined in the
 * document, there is neither an external style sheet nor an external
 * image. You can therefore save it as is, send it by email or print it
 * without taking anything else along.
 *
 * Nor does it contain the slightest link to the machine that produced it:
 * no favicon, no href, no src. Once saved or forwarded, a relative link
 * would resolve to nothing, and an absolute link would give away the
 * server address without adding anything to the diagram.
 *
 * Two layout choices, both dictated by printing:
 *
 *  - the board is a table and not a CSS grid, because it is the only
 *    model every rendering engine handles identically, the oldest ones
 *    included;
 *  - every dimension is written out, without calc(). Not every print
 *    engine resolves calc() combined with var(), and an unresolved
 *    dimension blows up the size of the squares.
 *
 * The single source of truth is therefore the constants below.
 */

declare(strict_types=1);

// This file is an include, never an entry point: without the constant set
// by index.php or generate.php, it stops at once. The lock therefore does
// not depend on any web server configuration.
if (!defined('CHB_ENTRY')) {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/chessboard.php';

/** Side of a square, in centimetres. Identical to the PDF template. */
const CHB_SQUARE_CM = 2.4;

/** Width of the gutter that holds the coordinates, in centimetres. */
const CHB_GUTTER_CM = 0.6;

/** Share of the square taken by the drawing of a piece. */
const CHB_PIECE_RATIO = 0.86;

/**
 * Formats a length in centimetres for CSS.
 *
 * @param  float $cm Length.
 * @return string For instance "2.4cm" or "2.064cm".
 */
function chb_cm(float $cm): string
{
    return rtrim(rtrim(number_format($cm, 3, '.', ''), '0'), '.') . 'cm';
}

/**
 * Loads a piece SVG and prepares it for inlining.
 *
 * The XML declaration and any DTD, which have no business in the middle of
 * an HTML document, are removed, then the fixed dimensions of the SVG file
 * are replaced with a viewBox, so that the piece follows the size the
 * style sheet gives it.
 *
 * @param  string $code chessboard.js code, for instance "wK".
 * @return string SVG markup ready to insert, or "" if the piece is missing.
 */
function chb_inline_svg(string $code): string
{
    $path = chb_piece_path($code);
    if ($path === null) {
        return '';
    }

    $svg = (string) file_get_contents($path);

    // XML declaration and DTD removed.
    $svg = (string) preg_replace('#<\?xml.*?\?>\s*#s', '', $svg);
    $svg = (string) preg_replace('#<!DOCTYPE.*?>\s*#s', '', $svg);

    // The Cburnett SVGs are drawn in a 45-unit square.
    $svg = (string) preg_replace(
        '#<svg\b[^>]*>#',
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 45 45" class="piece">',
        $svg,
        1
    );

    return $svg;
}

/**
 * Produces the HTML page and sends it to the browser.
 *
 * @param  string $placement FEN placement field, already validated.
 * @param  string $turn      "w" or "b".
 * @param  string $notes     Free annotation, already sanitised.
 * @param  bool   $return    true to get the markup back instead of sending it.
 * @return string The HTML markup if $return is true, "" otherwise.
 */
function chb_output_html(
    string $placement,
    string $turn,
    string $notes,
    bool $return = false,
    bool $blackAtBottom = false
): string {
    // The grid and the coordinates in display order (see chb_orient_grid()).
    $grid = chb_orient_grid(chb_placement_to_grid($placement), $blackAtBottom);
    $fen  = chb_full_fen($placement, $turn);

    [$files, $ranks] = chb_board_labels($blackAtBottom);

    // Dimensions computed once, then injected as is into the CSS.
    $cSquare = chb_cm(CHB_SQUARE_CM);
    $cGutter = chb_cm(CHB_GUTTER_CM);
    $cPiece  = chb_cm(CHB_SQUARE_CM * CHB_PIECE_RATIO);
    $cSheet  = chb_cm(8 * CHB_SQUARE_CM + 2 * CHB_GUTTER_CM);

    // -------------------------------------------------------------------
    // 10x10 table: the board, framed by its coordinates.
    // -------------------------------------------------------------------
    $header = '<tr><td class="corner"></td>';
    foreach ($files as $letter) {
        $header .= '<td class="file-label">' . $letter . '</td>';
    }
    $header .= '<td class="corner"></td></tr>';

    $rows   = [];
    $rows[] = $header;

    // The eight rows, from the top of the display down.
    foreach ($grid as $row => $squares) {
        // $rank counts the rows from the bottom of the display (0 for the
        // bottom row): with $column, it sets the colour of the square.
        $rank   = 7 - $row;
        $number = $ranks[$row];

        $tr = '<tr><td class="rank-label">' . $number . '</td>';

        foreach ($squares as $column => $code) {
            // Same parity rule as in the PDF: the bottom left square is dark.
            $classes = ['square', (($column + $rank) % 2 === 0) ? 'dark' : 'light'];

            // The outer frame is carried by the edge squares:
            // border-collapse merges the segments into a continuous line.
            if ($row === 0) {
                $classes[] = 'edge-top';
            }
            if ($row === 7) {
                $classes[] = 'edge-bottom';
            }
            if ($column === 0) {
                $classes[] = 'edge-left';
            }
            if ($column === 7) {
                $classes[] = 'edge-right';
            }

            $piece = ($code === null) ? '' : chb_inline_svg($code);

            $tr .= '<td class="' . implode(' ', $classes) . '">' . $piece . '</td>';
        }

        $tr .= '<td class="rank-label">' . $number . '</td></tr>';

        $rows[] = $tr;
    }

    // Last row, mirroring the first one.
    $rows[] = $header;

    $board = implode("\n", $rows);

    // -------------------------------------------------------------------
    // Annotation line, identical to the PDF one.
    // -------------------------------------------------------------------
    $notesLine = ($notes === '')
        ? 'Position / notes: ______________________________________'
        : 'Position / notes: ' . $notes;

    $notesLine = htmlspecialchars($notesLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Who is to move. The information is in the FEN, but nobody reads a
    // FEN at a glance, and a diagram without the side to move is ambiguous.
    $turnLine   = ($turn === 'b') ? 'Black to move' : 'White to move';
    $turnClass  = ($turn === 'b') ? 'dot-black' : 'dot-white';
    $escapedFen = htmlspecialchars('FEN: ' . $fen, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // -------------------------------------------------------------------
    // Document assembly
    // -------------------------------------------------------------------
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>diagram</title>
<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 1.2cm;
    background: #fff;
    color: #000;
    font-family: Helvetica, Arial, sans-serif;
}

/* Single centred block, the reference for everything else. */
.sheet { width: $cSheet; margin: 0 auto; }

.board { border-collapse: collapse; }
.board td { padding: 0; margin: 0; }

.square {
    width:  $cSquare;
    height: $cSquare;
    text-align: center;
    vertical-align: middle;
}

.light { background: #fff; }
.dark  { background: #ccc; }

/* Outer frame of the board. */
.edge-top    { border-top:    1.2pt solid #000; }
.edge-bottom { border-bottom: 1.2pt solid #000; }
.edge-left   { border-left:   1.2pt solid #000; }
.edge-right  { border-right:  1.2pt solid #000; }

.corner { width: $cGutter; height: $cGutter; }

.file-label {
    width:  $cSquare;
    height: $cGutter;
    text-align: center;
    vertical-align: middle;
    font-size: 11pt;
}

.rank-label {
    width:  $cGutter;
    height: $cSquare;
    text-align: center;
    vertical-align: middle;
    font-size: 11pt;
}

.piece {
    display: block;
    width:  $cPiece;
    height: $cPiece;
    margin: 0 auto;
}

/* The indent aligns the text with the left edge of the board, not with
   the coordinates gutter, exactly as in the PDF. */
.notes {
    padding-left: $cGutter;
    padding-right: $cGutter;
    margin: 1.1cm 0 0;
    font-size: 11pt;
    line-height: 1.35;
    color: #666;

    /* MultiCell forcibly breaks a word that is too long in the PDF.
       Without this rule the HTML would not, and an annotation without
       spaces would run off the page on a single line. */
    overflow-wrap: break-word;
    overflow-wrap: anywhere;
}

/* Side to move, aligned with the right edge of the board. The dot is a
   circle, following the convention of problem collections: filled for
   Black, empty and outlined for White. */
.turn {
    padding-right: $cGutter;
    margin: 0.55cm 0 0;
    font-size: 10pt;
    text-align: right;
}

.dot {
    display: inline-block;
    width: 0.30cm;
    height: 0.30cm;
    margin-right: 0.18cm;
    border: 0.8pt solid #000;
    border-radius: 50%;
    vertical-align: baseline;
}

.dot-black { background: #000; }
.dot-white { background: #fff; }

.fen {
    padding-left: $cGutter;
    margin: 0.6cm 0 0;
    font-family: "DejaVu Sans Mono", Courier, monospace;
    font-size: 10pt;
    color: #000;
    word-break: break-all;
}

/*
 * Narrow side margins: the sheet is already $cSheet wide, which leaves
 * only a few millimetres on each side of an A4. Set the print dialog to
 * default or no margins, otherwise the browser will scale the page down
 * and a square will no longer measure $cSquare.
 */
@page { size: A4 portrait; margin: 0.8cm 0.3cm; }

@media print {
    body { padding: 0; }
    /* Without this, several browsers drop the grey fills. */
    .dark,
    .dot-black {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
</head>
<body>

<div class="sheet">

<table class="board">
$board
</table>

<p class="turn"><span class="dot $turnClass"></span>$turnLine</p>

<p class="notes">$notesLine</p>
<p class="fen">$escapedFen</p>

</div>

</body>
</html>
HTML;

    if ($return) {
        return $html;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;

    return '';
}
