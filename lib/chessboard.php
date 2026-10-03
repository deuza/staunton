<?php

/**
 * chessboard.php - functions shared by the input form and the generator.
 *
 * This file holds the whole geometry of the PDF template, along with the
 * functions that validate and convert the FEN placement field. None of
 * these functions writes to disk: everything is produced on the fly, as
 * the specification requires.
 */

declare(strict_types=1);

// This file is an include, never an entry point: without the constant set
// by index.php or generate.php, it stops at once. The lock therefore does
// not depend on any web server configuration.
if (!defined('CHB_ENTRY')) {
    http_response_code(403);
    exit(1);
}

// -----------------------------------------------------------------------
// PDF template geometry, in PostScript points (1 pt = 1/72").
// -----------------------------------------------------------------------

/** One centimetre, in points. */
const CHB_CM = 72.0 / 2.54;

/** Width and height of a portrait A4 page. */
const CHB_PAGE_W = 21.0 * CHB_CM;
const CHB_PAGE_H = 29.7 * CHB_CM;

/** Side of a square, then side of the whole board. */
const CHB_SQUARE = 2.4 * CHB_CM;
const CHB_BOARD  = 8.0 * CHB_SQUARE;

/**
 * Top left corner of the board, with TCPDF's origin at the top left of
 * the page.
 *
 * The board is centred horizontally, and raised 1.5 cm above the vertical
 * centre of the page: the space freed below it holds the side to move,
 * the annotation block and the FEN.
 */
const CHB_BOARD_LEFT = (CHB_PAGE_W - CHB_BOARD) / 2.0;
const CHB_BOARD_TOP  = (CHB_PAGE_H - CHB_BOARD) / 2.0 - 1.5 * CHB_CM;

/** Grey levels of the squares: 0.80 grey for the dark ones, white for the light ones. */
const CHB_GREY_DARK  = 204;   // 0.80 x 255
const CHB_GREY_LIGHT = 255;

/**
 * Maximum length accepted for the free annotation.
 *
 * The figure is measured, not estimated. Between the top of the notes
 * block and the FEN line there are 96.6 pt left. The actual line spacing
 * is 13.75 pt: the 11 pt font size multiplied by TCPDF's cell height
 * ratio (K_CELL_HEIGHT_RATIO, 1.25 in the shipped configuration). Seven
 * lines take 96.25 pt and fit, an eighth one overlaps the FEN.
 *
 * MultiCell() breaks lines on spaces: a word that does not fit at the end
 * of a line moves whole to the next one. The worst case is therefore not
 * a run of at signs (the widest glyph in Helvetica, 11.16 pt), which fills
 * every line to the end and fits in seven lines up to 288 characters, but
 * a run of words of about half a line each, which leaves half of every
 * line empty. An exhaustive search over every sequence of words made of
 * at signs, checked with TCPDF's getNumLines(), shows that 195 characters
 * are enough to force an eighth line, taking the "Position / notes: "
 * prefix into account. 194 characters always fit in seven lines, however
 * the text breaks into words.
 *
 * This limit only rests on the font, the font size, the board width and
 * TCPDF's line breaking logic. If any of them changes, chb_output_pdf()
 * notices: it measures the text before writing it, and refuses beyond
 * seven lines rather than overlap the FEN.
 *
 * In ordinary English, at about 5 pt per character, these 194 characters
 * fit on two lines.
 */
const CHB_NOTES_MAX = 194;

/**
 * Maximum size of the raw annotation, in bytes.
 *
 * A UTF-8 code point takes at most four bytes: 194 characters therefore
 * take at most 776 bytes, even when the browser sends a line break as CRLF
 * (two bytes for one counted character). Beyond that, the input does not
 * come from the form.
 */
const CHB_NOTES_BYTES_MAX = 4 * CHB_NOTES_MAX;

// -----------------------------------------------------------------------
// FEN validation and conversion
// -----------------------------------------------------------------------

/**
 * Validates the placement field of a FEN.
 *
 * The function accepts a full six-field FEN, as copied from Lichess or an
 * engine, as well as the bare placement produced by board.fen(). In the
 * first case it cuts at the first space.
 *
 * It cleans nothing: it validates against an allow list. The difference
 * matters. Cleaning an input means filtering against a deny list, and a
 * deny list can be bypassed, by double encoding, a null byte or a stream
 * wrapper. An allow list cannot fail that way: anything not explicitly
 * allowed simply does not exist.
 *
 * @param  string      $fen Raw string, for instance "8/8/8/8/8/8/8/8".
 * @return string|null The valid placement, or null if it is not valid.
 */
function chb_validate_placement(string $fen): ?string
{
    // Size guard, before any processing: we are not going to run regular
    // expressions over a string of several megabytes.
    if (strlen($fen) > 200) {
        return null;
    }

    $fen = trim($fen);

    // A full FEN has six fields separated by spaces. Only the first one
    // describes the position, the other five hold the side to move, the
    // castling rights, the en passant square and the move counters. We
    // therefore accept a string pasted from Lichess as well as the bare
    // placement produced by board.fen().
    $space = strpos($fen, ' ');
    if ($space !== false) {
        $fen = substr($fen, 0, $space);
    }

    // A sensible placement never exceeds 71 characters
    // (8 ranks of 8 characters + 7 separators).
    if ($fen === '' || strlen($fen) > 71) {
        return null;
    }

    // Strict allow list, not filtering: pieces, compression digits,
    // separator. Nothing else exists.
    //
    // The anchors are \A and \z, never ^ and $: the latter would tolerate
    // a trailing line feed, the classic way into multiline injection.
    if (preg_match('#\A[pnbrqkPNBRQK1-8/]+\z#', $fen) !== 1) {
        return null;
    }

    // Two consecutive digits are not FEN: "44" reads as "8" and the rank
    // total comes out right, but chess.js and Lichess reject the string.
    // The "/" separator breaks the run, "8/8" stays valid.
    if (preg_match('#[1-8]{2}#', $fen) === 1) {
        return null;
    }

    $ranks = explode('/', $fen);
    if (count($ranks) !== 8) {
        return null;
    }

    foreach ($ranks as $rank) {
        $squares = 0;

        foreach (str_split($rank) as $c) {
            if (ctype_digit($c)) {
                // "0" is excluded by the allow list, and two consecutive
                // digits by the check that follows it: each digit thus
                // stands alone for its run of empty squares.
                $squares += (int) $c;
            } else {
                $squares++;
            }
        }

        // Every rank must describe exactly eight squares.
        if ($squares !== 8) {
            return null;
        }
    }

    return $fen;
}

/**
 * Reads the side to move from the second field of a full FEN.
 *
 * Same rule as the paste field of the input page (app.js): the field is
 * taken only if it is exactly "w" or "b", and only if the placement that
 * precedes it is valid. Anything else gives null and is simply ignored:
 * the placement stays usable, the side to move comes from elsewhere.
 *
 * Like the placement, the string goes through an allow list, not a
 * filter: one or more spaces after the placement, then "w" or "b",
 * followed by a space or by the end of the string. "W", "white", a
 * fullwidth letter, a tag, a null byte or a line feed glued to the letter
 * cannot match.
 *
 * @param  string      $fen Raw string, for instance "8/8/8/8/8/8/8/8 b - - 0 1".
 * @return string|null "w", "b", or null if the string gives no valid side to move.
 */
function chb_fen_turn(string $fen): ?string
{
    // Same size guard as chb_validate_placement(), before any processing.
    if (strlen($fen) > 200) {
        return null;
    }

    if (chb_validate_placement($fen) === null) {
        return null;
    }

    if (preg_match('#\A[^ ]+ +([wb])(?: |\z)#', trim($fen), $match) !== 1) {
        return null;
    }

    return $match[1];
}

/**
 * Settles the side to move of a request.
 *
 * In this order: the turn parameter if it is exactly "w" or "b", then the
 * second field of a full FEN, then White. An explicit choice therefore
 * always wins over what the FEN says.
 *
 * @param  string $requested Value of the turn parameter, "" when missing.
 * @param  string $fen       Raw value of the fen parameter.
 * @return string "w" or "b".
 */
function chb_resolve_turn(string $requested, string $fen): string
{
    if ($requested === 'w' || $requested === 'b') {
        return $requested;
    }

    return chb_fen_turn($fen) ?? 'w';
}

/**
 * Converts a placement field into an indexed grid.
 *
 * @param  string $fen Placement already validated by chb_validate_placement().
 * @return array<int, array<int, string|null>> grid[row][column], where row 0
 *         is rank 8 (top of the board) and column 0 is file "a". Each
 *         square is null or a code such as "wK"/"bP".
 */
function chb_placement_to_grid(string $fen): array
{
    $grid = [];

    foreach (explode('/', $fen) as $i => $rank) {
        $row = [];

        foreach (str_split($rank) as $c) {
            if (ctype_digit($c)) {
                // A run of empty squares.
                for ($n = (int) $c; $n > 0; $n--) {
                    $row[] = null;
                }
            } else {
                // Upper case = white, lower case = black, as FEN requires.
                $colour = ($c === strtoupper($c)) ? 'w' : 'b';
                $row[]  = $colour . strtoupper($c);
            }
        }

        $grid[$i] = $row;
    }

    return $grid;
}

/**
 * Settles the orientation of a diagram.
 *
 * Black is at the bottom when the board was flipped on the input page, or
 * when Black is to move; White is at the bottom otherwise. Only the exact
 * value "black" counts as a flip: allow list, as everywhere else.
 *
 * @param  string $orientation Orientation of the board on the input page.
 * @param  string $turn        Side to move, "w" or "b".
 * @return bool True to draw the board with Black at the bottom.
 */
function chb_black_at_bottom(string $orientation, string $turn): bool
{
    return $orientation === 'black' || $turn === 'b';
}

/**
 * Puts a grid in display order.
 *
 * Unchanged with White at the bottom; turned by half a turn with Black at
 * the bottom, so that rank 1 is at the top and file "h" on the left. The
 * colour of a square only depends on its place on the display, which half
 * a turn preserves: the bottom left square stays dark, a1 seen from White,
 * h8 seen from Black.
 *
 * @param  array<int, array<int, string|null>> $grid          Grid from chb_placement_to_grid().
 * @param  bool                                $blackAtBottom True to put Black at the bottom.
 * @return array<int, array<int, string|null>> Grid in display order, row 0 at the top.
 */
function chb_orient_grid(array $grid, bool $blackAtBottom): array
{
    if (!$blackAtBottom) {
        return $grid;
    }

    return array_map(static fn (array $row): array => array_reverse($row), array_reverse($grid));
}

/**
 * Coordinates in display order.
 *
 * @param  bool $blackAtBottom True to put Black at the bottom.
 * @return array{0: list<string>, 1: list<string>} The file letters, left to
 *         right, and the rank numbers, top to bottom.
 */
function chb_board_labels(bool $blackAtBottom): array
{
    $files = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
    $ranks = ['8', '7', '6', '5', '4', '3', '2', '1'];

    return $blackAtBottom ? [array_reverse($files), array_reverse($ranks)] : [$files, $ranks];
}

/**
 * Builds a full FEN from the placement field alone.
 *
 * board.fen() only returns the placement. For the string printed at the
 * bottom of the page to be pasted as is into Arena, Lichess or an engine,
 * the five missing fields have to be added.
 *
 * Castling rights are deliberately set to "-": this generator lays out
 * free diagrams, nothing guarantees that the rooks and the king stand on
 * their initial squares.
 *
 * @param  string $placement Valid placement.
 * @param  string $turn      "w" or "b".
 * @return string Full six-field FEN.
 */
function chb_full_fen(string $placement, string $turn): string
{
    $turn = ($turn === 'b') ? 'b' : 'w';

    return $placement . ' ' . $turn . ' - - 0 1';
}

/**
 * Cleans the free annotation typed by the user.
 *
 * Control characters, which belong neither in a PDF nor in an HTML page,
 * are removed, whitespace is normalised, then the text is truncated.
 *
 * Deliberately written without mbstring: PCRE already counts code points
 * in /u mode, and this spares one more dependency to install.
 *
 * @param  string $notes Raw text from the form.
 * @return string Sanitised text, possibly empty.
 */
function chb_clean_notes(string $notes): string
{
    // Size guard, before any processing, mirroring the FEN one: we do not
    // run a series of regular expressions over several megabytes.
    // generate.php already refuses such a request with a 400; the function
    // still returns an empty annotation, to stay safe whatever the caller.
    if (strlen($notes) > CHB_NOTES_BYTES_MAX) {
        return '';
    }

    // Essential guard: faced with a string that is not valid UTF-8, any
    // regular expression in /u mode fails silently and returns null. An
    // empty pattern in /u mode only matches a valid string, which makes it
    // a cheap encoding test.
    if (preg_match('##u', $notes) !== 1) {
        return '';
    }

    // Control characters removed. No /u mode here: these bytes cannot
    // occur in the middle of a multibyte UTF-8 sequence, whose
    // continuation bytes range from 0x80 to 0xBF.
    $notes = (string) preg_replace('#[\x00-\x1F\x7F]+#', ' ', $notes);

    // C1 block controls (U+0080 to U+009F): same treatment, a space.
    // These are multibyte in UTF-8, hence the /u mode.
    $notes = (string) preg_replace('#[\x{0080}-\x{009F}]+#u', ' ', $notes);

    // Format characters (Unicode category Cf): right-to-left override
    // (U+202E), zero width space (U+200B), byte order mark (U+FEFF), soft
    // hyphen (U+00AD), emoji zero width joiner (U+200D)... They are not
    // displayed but act on the text. They are removed with nothing in
    // their place: the text stays what the user saw, "word\u{200B}word"
    // becomes "wordword" again. Most of them would come out as "?" in the
    // PDF anyway, since Helvetica does not know them.
    $notes = (string) preg_replace('#\p{Cf}+#u', '', $notes);

    // Whitespace normalisation, no-break space included.
    $notes = (string) preg_replace('#[\s\x{00A0}]+#u', ' ', $notes);
    $notes = trim($notes);

    // Truncation on characters, not on bytes: cutting in the middle of an
    // accented character would produce invalid UTF-8.
    if (preg_match('#\A.{0,' . CHB_NOTES_MAX . '}#u', $notes, $match) === 1) {
        $notes = $match[0];
    }

    return $notes;
}

/**
 * Reads a request parameter and guarantees a string.
 *
 * "fen[]=x" in a request turns $_POST['fen'] into an array. A (string)
 * cast would make it the string "Array", along with an "Array to string
 * conversion" warning emitted before the headers: with display_errors
 * on, TCPDF would then refuse to send the PDF. An array is never a form
 * input: it is read as an empty string, and the validation that follows
 * rejects or ignores it.
 *
 * @param  array<mixed> $source  $_GET or $_POST.
 * @param  string       $name    Parameter name.
 * @param  string       $default Value when the parameter is missing.
 * @return string The parameter if it is a string, $default if it is
 *                missing, "" if it is of any other type.
 */
function chb_param(array $source, string $name, string $default = ''): string
{
    if (!array_key_exists($name, $source)) {
        return $default;
    }

    $value = $source[$name];

    return is_string($value) ? $value : '';
}

/**
 * Returns the file to load to get TCPDF, or null.
 *
 * In this order:
 *
 *  1. the STAUNTON_TCPDF environment variable, set by SetEnv in the Apache
 *     vhost, fastcgi_param under nginx, or in the shell. If it is set but
 *     wrong, null is returned rather than silently falling back on another
 *     TCPDF: it is a configuration error;
 *  2. the Debian or Ubuntu php-tcpdf package;
 *  3. a Composer vendor/autoload.php at the root of the project. This is
 *     the FreeBSD case, whose ports tree does not contain TCPDF
 *     (composer require tecnickcom/tcpdf).
 */
function chb_tcpdf_path(): ?string
{
    // getenv() first: it reflects putenv() and the process environment;
    // $_SERVER as a fallback, for the SAPIs that only expose there the
    // variables passed on by the web server.
    $forced = getenv('STAUNTON_TCPDF');
    if ($forced === false) {
        $forced = $_SERVER['STAUNTON_TCPDF'] ?? '';
    }
    if (is_string($forced) && $forced !== '') {
        return (is_file($forced) && is_readable($forced)) ? $forced : null;
    }

    $candidates = [
        '/usr/share/php/tcpdf/tcpdf.php',
        dirname(__DIR__) . '/vendor/autoload.php',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Returns the absolute path of a piece SVG.
 *
 * @param  string      $code chessboard.js code, for instance "wK".
 * @return string|null Path of the file, or null if it is missing.
 */
function chb_piece_path(string $code): ?string
{
    // Guard: only the twelve expected codes are accepted, never a string
    // coming from the network, otherwise we would open a directory
    // traversal.
    if (preg_match('#\A[wb][KQRBNP]\z#', $code) !== 1) {
        return null;
    }

    $path = __DIR__ . '/../assets/pieces/' . $code . '.svg';

    return is_readable($path) ? $path : null;
}
