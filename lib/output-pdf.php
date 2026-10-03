<?php

/**
 * output-pdf.php - renders the diagram as an A4 PDF with TCPDF.
 *
 * The template: 2.4 cm squares, 0.80 grey for the dark squares, a1 dark,
 * coordinates on all four sides, then below the board the side to move,
 * the annotation block and the FEN.
 *
 * Nothing is written to disk: the PDF goes straight to the HTTP output
 * stream.
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

// TCPDF is looked up by chb_tcpdf_path(): STAUNTON_TCPDF, then the Debian
// package, then Composer (see lib/chessboard.php). When it cannot be found,
// say so clearly rather than let PHP fail on a require.
$tcpdfPath = chb_tcpdf_path();
if ($tcpdfPath === null) {
    http_response_code(500);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "TCPDF not found. Install php-tcpdf, or give its path in the\n",
         "STAUNTON_TCPDF environment variable (see README).\n";
    exit(1);
}
require_once $tcpdfPath;

// Only TCPDF 6 is supported. TCPDF 7 keeps the name and the public API,
// but runs on another engine and no longer provides the TCPDF_STATIC
// class nor the internals that ChessboardPdf relies on (see below). A
// Composer installation without a version constraint picks it up.
//
// TCPDF 6.8 and later also need the PHP curl extension: their classes
// refer to curl constants, and without the extension the first use of
// TCPDF_STATIC stops on an Error, caught here.
//
// In both cases, say so clearly rather than fail on a fatal error. The
// technical detail goes to the PHP error log, not to the visitor.
$tcpdfVersion = null;
$tcpdfProblem = '';
if (class_exists('TCPDF') && class_exists('TCPDF_STATIC')) {
    try {
        $tcpdfVersion = TCPDF_STATIC::getTCPDFVersion();
    } catch (Error $e) {
        error_log('staunton: TCPDF cannot run: ' . $e->getMessage());
        $tcpdfProblem = str_contains($e->getMessage(), 'CURL')
            ? "TCPDF cannot run: TCPDF 6.8 and later need the PHP curl extension\n"
                . "(php-curl, or php85-curl on FreeBSD).\n"
            : "TCPDF cannot run: see the PHP error log.\n";
    }
}
if ($tcpdfProblem === '' && ($tcpdfVersion === null || version_compare($tcpdfVersion, '7.0.0', '>='))) {
    $tcpdfProblem = "Unsupported TCPDF version: Staunton requires TCPDF 6\n"
        . "(with Composer: tecnickcom/tcpdf:^6.9.2, see README).\n";
}
if ($tcpdfProblem !== '') {
    http_response_code(500);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $tcpdfProblem;
    exit(1);
}

/** Cell height used to lay out the coordinate labels. */
const CHB_PDF_LABEL_H = 13.0;

/** Font size of the annotation line, in points. */
const CHB_PDF_NOTES_PT = 11.0;

/** Font size of the FEN printed at the bottom of the page, in points. */
const CHB_PDF_FEN_PT = 10.0;

/**
 * Cell height passed to MultiCell() for the annotation, in points.
 *
 * It is not the line spacing: TCPDF takes the larger of this value and the
 * font size multiplied by its cell height ratio, that is
 * 11 x 1.25 = 13.75 pt. The constant only serves to place the first line
 * vertically, in the SetXY() that precedes the MultiCell().
 */
const CHB_PDF_NOTES_H = 13.2;

/** Number of annotation lines that fit between the board and the FEN. */
const CHB_PDF_NOTES_LINES = 7;

/** Font size of the side-to-move line, in points. */
const CHB_PDF_TURN_PT = 10.0;

/**
 * Distance between the bottom of the page and the top of the FEN line.
 *
 * 40 pt, or 1.41 cm, keeps the line out of the non-printable area of home
 * printers, and leaves room for seven annotation lines. This is the value
 * that sets CHB_NOTES_MAX.
 */
const CHB_PDF_FEN_MARGIN = 40.0;

/**
 * TCPDF stripped of its promotional link.
 *
 * At the bottom of the last page, in Close(), TCPDF slips in a 1 point
 * "Powered by TCPDF" in invisible rendering mode, along with a link
 * annotation to tcpdf.org. Nothing shows on screen or in print, but the
 * text comes out on extraction and the link stays clickable in any
 * document you distribute.
 *
 * The property that controls this behaviour is protected and has no
 * public accessor: the only clean way to switch it off is to inherit.
 *
 * TCPDF also writes its address in the Producer field, in two places: the
 * information dictionary and the XMP packet. The string comes from a
 * static method that cannot be overridden; it is therefore removed on the
 * fly, in _out(), through which every object of the document passes. The
 * document produced thus contains no link at all, neither to tcpdf.org
 * nor to the machine that generated it.
 */
final class ChessboardPdf extends TCPDF
{
    /**
     * Address that TCPDF appends to its name in the Producer field.
     */
    private const PRODUCER_LINK = ' (http://www.tcpdf.org)';
    /**
     * @param string $orientation P or L.
     * @param string $unit        Unit of measure, points here.
     * @param string $format      Page format.
     */
    public function __construct(string $orientation, string $unit, string $format)
    {
        parent::__construct($orientation, $unit, $format, true, 'UTF-8', false);

        $this->tcpdflink = false;
    }

    /**
     * Writes a fragment of the document, rid of TCPDF's address.
     *
     * Only structure objects are affected (state other than 2): the page
     * content, and therefore the annotation typed in, is never touched.
     *
     * @param string $s Fragment to write (TCPDF documents it as a string,
     *                  without declaring the type).
     */
    protected function _out($s): void
    {
        // is_string() stays as a guard: the parameter has no native type.
        // @phpstan-ignore function.alreadyNarrowedType
        if ($this->state !== 2 && is_string($s) && str_contains($s, 'Producer')) {
            $producer = TCPDF_STATIC::getTCPDFProducer();
            $plain    = str_replace(self::PRODUCER_LINK, '', $producer);

            // Information dictionary: the string is encoded there by
            // _textstring(), in UTF-16BE with escaped parentheses. It is
            // therefore encoded the same way to find it. The document is
            // never encrypted, the object number has no effect.
            //
            // XMP packet: the string appears there as escaped XML.
            $s = str_replace(
                [$this->_textstring($producer), TCPDF_STATIC::_escapeXML($producer)],
                [$this->_textstring($plain), TCPDF_STATIC::_escapeXML($plain)],
                $s
            );

            // The XMP stream got shorter: its declared length must follow,
            // otherwise the reader would report a damaged file.
            $s = (string) preg_replace_callback(
                '#/Length \d+ >> stream\n(.*)\nendstream#s',
                static fn (array $m): string =>
                    '/Length ' . strlen($m[1]) . " >> stream\n" . $m[1] . "\nendstream",
                $s
            );
        }

        parent::_out($s);
    }
}

/**
 * Produces the PDF and sends it to the browser.
 *
 * @param string $placement FEN placement field, already validated.
 * @param string $turn      "w" or "b".
 * @param string $notes     Free annotation, already sanitised.
 * @param bool   $return    true to get the bytes back instead of sending
 *                          them (useful for tests outside a web server).
 * @return string The PDF bytes if $return is true, "" otherwise.
 * @throws LengthException If the annotation needs more than
 *                         CHB_PDF_NOTES_LINES lines. With the limit of
 *                         CHB_NOTES_MAX characters this does not happen: it
 *                         means an assumption of the computation has
 *                         changed (font, font size, board width, line
 *                         breaking by another version of TCPDF).
 */
function chb_output_pdf(
    string $placement,
    string $turn,
    string $notes,
    bool $return = false,
    bool $blackAtBottom = false
): string {
    // The grid and the coordinates in display order (see chb_orient_grid()).
    $grid = chb_orient_grid(chb_placement_to_grid($placement), $blackAtBottom);

    [$files, $ranks] = chb_board_labels($blackAtBottom);

    // Document in points, the unit of the template geometry.
    $pdf = new ChessboardPdf('P', 'pt', 'A4');

    // Metadata: constants, and nothing else. Never let data coming from
    // the user in (SetSubject($notes), SetKeywords(), SetAuthor()...):
    // ChessboardPdf::_out() rewrites with a regular expression every
    // object outside the page streams that contains "Producer", and free
    // text in those objects would make that rewriting remotely
    // triggerable. lib/test.php checks it.
    $pdf->SetCreator('staunton');
    $pdf->SetTitle('diagram');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetCellPadding(0);
    $pdf->AddPage();

    $left   = CHB_BOARD_LEFT;
    $top    = CHB_BOARD_TOP;
    $right  = $left + CHB_BOARD;
    $bottom = $top + CHB_BOARD;

    // -------------------------------------------------------------------
    // The 64 squares
    // -------------------------------------------------------------------
    // The grid is in display order, top to bottom. Parity is computed on
    // the place on the display: the bottom left square is dark, a1 seen
    // from White and h8 seen from Black, as on a real chessboard.
    for ($row = 0; $row < 8; $row++) {
        // $rank counts the rows from the bottom of the display.
        $rank = 7 - $row;

        for ($column = 0; $column < 8; $column++) {
            $shade = (($column + $rank) % 2 === 0) ? CHB_GREY_DARK : CHB_GREY_LIGHT;

            $pdf->SetFillColor($shade, $shade, $shade);
            $pdf->Rect(
                $left + $column * CHB_SQUARE,
                $top + $row * CHB_SQUARE,
                CHB_SQUARE,
                CHB_SQUARE,
                'F'
            );
        }
    }

    // -------------------------------------------------------------------
    // Outer frame
    // -------------------------------------------------------------------
    $pdf->SetLineStyle(['width' => 1.2, 'color' => [0, 0, 0]]);
    $pdf->Rect($left, $top, CHB_BOARD, CHB_BOARD, 'D');

    // -------------------------------------------------------------------
    // The pieces
    // -------------------------------------------------------------------
    // The Cburnett SVGs are drawn in a 45-unit square, with the drawing
    // slightly smaller than the frame. They are laid on 86% of the
    // square, centred, to recover the visual breathing room of the screen.
    $size   = CHB_SQUARE * 0.86;
    $offset = (CHB_SQUARE - $size) / 2.0;

    foreach ($grid as $row => $squares) {
        foreach ($squares as $column => $code) {
            if ($code === null) {
                continue;
            }

            $svg = chb_piece_path($code);
            if ($svg === null) {
                // Piece missing from the asset set: skip it rather than
                // make the whole page fail.
                continue;
            }

            $pdf->ImageSVG(
                $svg,
                $left + $column * CHB_SQUARE + $offset,
                $top + $row * CHB_SQUARE + $offset,
                $size,
                $size,
                '',
                '',
                '',
                0,
                false
            );
        }
    }

    // -------------------------------------------------------------------
    // Coordinates on all four sides
    // -------------------------------------------------------------------
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(0, 0, 0);

    // TCPDF positions text by the top left corner of a cell, whereas the
    // template is defined by baselines. The offsets below place the
    // baselines 0.6 cm below the board and 0.35 cm above it, and the
    // digits 0.35 cm from its sides.
    $baselineToTop = CHB_PDF_LABEL_H / 2.0 + 11.0 * 0.35;

    foreach ($files as $i => $letter) {
        $x = $left + $i * CHB_SQUARE;

        // Below the board, baseline at 0.6 cm.
        $pdf->SetXY($x, $bottom + 0.6 * CHB_CM - $baselineToTop);
        $pdf->Cell(CHB_SQUARE, CHB_PDF_LABEL_H, $letter, 0, 0, 'C');

        // Above, mirrored, baseline at 0.35 cm.
        $pdf->SetXY($x, $top - 0.35 * CHB_CM - $baselineToTop);
        $pdf->Cell(CHB_SQUARE, CHB_PDF_LABEL_H, $letter, 0, 0, 'C');
    }

    // Width of the gutter reserved for the digits, on either side.
    $gutter = 14.0;

    for ($i = 0; $i < 8; $i++) {
        // $i = 0 is the top row: rank 8 seen from White, rank 1 from Black.
        $y      = $top + $i * CHB_SQUARE + (CHB_SQUARE - CHB_PDF_LABEL_H) / 2.0;
        $number = $ranks[$i];

        // On the left, right edge of the digit 0.35 cm from the board.
        $pdf->SetXY($left - 0.35 * CHB_CM - $gutter, $y);
        $pdf->Cell($gutter, CHB_PDF_LABEL_H, $number, 0, 0, 'R');

        // On the right, left edge of the digit 0.35 cm from the board.
        $pdf->SetXY($right + 0.35 * CHB_CM, $y);
        $pdf->Cell($gutter, CHB_PDF_LABEL_H, $number, 0, 0, 'L');
    }

    // -------------------------------------------------------------------
    // Side to move
    // -------------------------------------------------------------------
    // The information is in the FEN, but nobody reads a FEN at a glance,
    // and a diagram without the side to move is ambiguous. The line sits
    // between the bottom coordinates and the annotation block, aligned
    // with the right edge of the board.
    //
    // The dot is a circle, following the convention of problem
    // collections: filled for Black, empty and outlined for White.
    $turnLine = ($turn === 'b') ? 'Black to move' : 'White to move';

    $pdf->SetFont('helvetica', '', CHB_PDF_TURN_PT);
    $pdf->SetTextColor(0, 0, 0);

    $turnWidth    = $pdf->GetStringWidth($turnLine);
    $turnBaseline = $bottom + 1.45 * CHB_CM;   // text baseline

    // Dot, sized on the height of the capitals.
    $side = 7.5;
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineStyle(['width' => 0.6, 'color' => [0, 0, 0]]);

    if ($turn === 'b') {
        $pdf->SetFillColor(0, 0, 0);
        $style = 'DF';
    } else {
        $pdf->SetFillColor(255, 255, 255);
        $style = 'DF';
    }

    // A 7.5 pt circle, 5 pt from the text, resting on the baseline.
    $radius = $side / 2.0;
    $pdf->Circle(
        $right - $turnWidth - 5.0 - $radius,
        $turnBaseline - $radius,
        $radius,
        0,
        360,
        $style
    );

    $pdf->SetXY(
        $right - $turnWidth,
        $turnBaseline - (CHB_PDF_LABEL_H / 2.0 + CHB_PDF_TURN_PT * 0.35)
    );
    $pdf->Cell($turnWidth, CHB_PDF_LABEL_H, $turnLine, 0, 0, 'R');

    // -------------------------------------------------------------------
    // Annotation line
    // -------------------------------------------------------------------
    // Without an annotation, a blank line ready to be filled in with a pen.
    $pdf->SetFont('helvetica', '', CHB_PDF_NOTES_PT);
    $pdf->SetTextColor(102, 102, 102);   // 0.40 x 255

    $notesLine = ($notes === '')
        ? 'Position / notes: ______________________________________'
        : 'Position / notes: ' . $notes;

    // MultiCell and not Cell: a long annotation wraps instead of running
    // off the page. The first baseline sits 2.3 cm below the board, and
    // the block grows downwards, towards the FEN line.
    //
    // Automatic page breaks are disabled from the creation of the
    // document: even an absurd input could not produce a second page. The
    // limit of CHB_NOTES_MAX characters guarantees that the block never
    // touches the FEN; it is checked here all the same, with the same line
    // breaking logic as MultiCell(), and the document is refused rather
    // than produced unreadable if an assumption of the computation changed.
    $lines = $pdf->getNumLines($notesLine, CHB_BOARD);
    if ($lines > CHB_PDF_NOTES_LINES) {
        // LengthException, a standard PHP exception: TCPDF never throws
        // it, so generate.php can catch it without risking hiding another
        // error.
        throw new LengthException(sprintf(
            'Annotation of %d lines, at most %d fit below the diagram.',
            $lines,
            CHB_PDF_NOTES_LINES
        ));
    }

    $pdf->SetXY(
        $left,
        $bottom + 2.3 * CHB_CM - (CHB_PDF_NOTES_H / 2.0 + CHB_PDF_NOTES_PT * 0.35)
    );
    $pdf->MultiCell(CHB_BOARD, CHB_PDF_NOTES_H, $notesLine, 0, 'L', false, 1);

    // -------------------------------------------------------------------
    // FEN at the bottom of the page
    // -------------------------------------------------------------------
    // In black and monospaced: this string is copied by hand or read off a
    // photocopy, it must stay sharp. Monospacing above all avoids mixing
    // up the digit 1, lower case l and upper case I.
    $pdf->SetFont('courier', '', CHB_PDF_FEN_PT);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetXY($left, CHB_PAGE_H - CHB_PDF_FEN_MARGIN);
    // The prefix takes five characters. Even with the longest possible
    // FEN, 81 characters, the line is 516 pt of the board's 544 pt.
    $pdf->Cell(CHB_BOARD, 14.0, 'FEN: ' . chb_full_fen($placement, $turn), 0, 0, 'L');

    // -------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------
    if ($return) {
        return $pdf->Output('diagram.pdf', 'S');
    }

    // "I" shows the PDF in the browser, with a suggested file name should
    // the user choose to save it.
    $pdf->Output('diagram.pdf', 'I');

    return '';
}
