<?php

/**
 * generate.php - produces the diagram on the fly.
 *
 * No write to disk, no session, no database: the position arrives by POST,
 * the document goes back out in the output stream.
 *
 * Expected fields:
 *   fen    FEN placement field produced by board.fen(), or a full FEN
 *   turn   w or b; when it is missing, the second field of a full FEN
 *   orientation  white or black, the orientation of the input board
 *   notes  free annotation
 *   format pdf or html
 */

declare(strict_types=1);

// Only index.php and generate.php are entry points: the files in lib/
// require this constant and refuse to run without it.
define('CHB_ENTRY', true);

require_once __DIR__ . '/lib/chessboard.php';

/**
 * Stops processing on a request error.
 *
 * @param  string $message Explanation meant for the user.
 * @param  int    $status  HTTP status code, 400 by default.
 * @return never
 */
function chb_refuse(string $message, int $status = 400)
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message, "\n";
    exit;
}

// app.js calls generate.php/diagram.pdf or generate.php/diagram.html, so
// that browsers propose that name when saving the document. Nothing else
// may follow the name of the script: allow list, as everywhere else.
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if (!is_string($pathInfo) || !in_array($pathInfo, ['', '/diagram.pdf', '/diagram.html'], true)) {
    chb_refuse('Not found.', 404);
}

// The position must come from a POST: a generation URL makes no sense
// here, the URL parameters of the input page already play that part.
// Any other method gets a 405 and the Allow header that goes with it.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    chb_refuse('Method not allowed. Please use the input form.', 405);
}

$placement = chb_validate_placement(chb_param($_POST, 'fen'));

if ($placement === null) {
    chb_refuse('Invalid or missing position.');
}

// An entirely empty board is perfectly legitimate: it is the original
// blank template, the one you fill in with a pen.

// The form always sends turn; the second field of a full FEN only serves
// when it is missing (see chb_resolve_turn()).
$turn  = chb_resolve_turn(chb_param($_POST, 'turn'), chb_param($_POST, 'fen'));
$notes = chb_param($_POST, 'notes');

// Size guard: the form field cannot exceed this size (see
// CHB_NOTES_BYTES_MAX). Say so, rather than produce a diagram whose
// annotation has vanished without explanation.
if (strlen($notes) > CHB_NOTES_BYTES_MAX) {
    chb_refuse('Annotation too large. Please use the input form.');
}
$notes  = chb_clean_notes($notes);
$format = chb_param($_POST, 'format', 'pdf');

// Black at the bottom when the board was flipped on the input page, or when
// Black is to move; White at the bottom otherwise.
$blackAtBottom = chb_black_at_bottom(chb_param($_POST, 'orientation'), $turn);

switch ($format) {
    case 'html':
        require_once __DIR__ . '/lib/output-html.php';
        chb_output_html($placement, $turn, $notes, false, $blackAtBottom);
        break;

    case 'pdf':
        require_once __DIR__ . '/lib/output-pdf.php';
        // The annotation limit makes this refusal theoretical: it only
        // triggers if the layout changed without CHB_NOTES_MAX being
        // measured again (see lib/chessboard.php).
        try {
            chb_output_pdf($placement, $turn, $notes, false, $blackAtBottom);
        } catch (LengthException $e) {
            chb_refuse('Annotation too long to fit below the diagram. Please shorten it.');
        }
        break;

    default:
        chb_refuse('Unknown output format.');
}
