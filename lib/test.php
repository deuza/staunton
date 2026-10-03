<?php

/**
 * test.php - regression checks, in pure php-cli.
 *
 * No dependency: no Composer, no Node, no jsdom. Run it from the root of
 * the project:
 *
 *     php lib/test.php
 *
 * It returns 0 if everything passes, 1 otherwise, which makes it usable
 * as is in a git hook or a scheduled task.
 *
 * It only runs from the command line: called by a web server, it answers
 * 403 without producing anything, whatever the configuration.
 */

declare(strict_types=1);

// Command line only: served over HTTP, this file would become a load
// amplifier (two PDFs per request). No other SAPI is allowed, not even
// php-cgi or phpdbg.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

// test.php plays the part of an entry point here.
define('CHB_ENTRY', true);

require_once __DIR__ . '/chessboard.php';

/**
 * Keeps the running count of the checks, in static variables rather than
 * in global ones, so that static analysis can follow them.
 *
 * @param  bool|null $passed Outcome of a check to count, null to only read.
 * @return array{0: int, 1: int} Number of checks, number of failures.
 */
function tally(?bool $passed = null): array
{
    /** @var int $total */
    static $total = 0;
    /** @var int $failures */
    static $failures = 0;

    if ($passed !== null) {
        $total++;
        if (!$passed) {
            $failures++;
        }
    }

    return [$total, $failures];
}

/**
 * Compares an actual value with the expected one.
 *
 * @param string $label    What is being checked.
 * @param mixed  $actual   Value produced by the code.
 * @param mixed  $expected Reference value.
 */
function check(string $label, $actual, $expected): void
{
    $a = var_export($actual, true);
    $e = var_export($expected, true);

    tally($a === $e);

    if ($a === $e) {
        printf("  ok     %s\n", $label);
        return;
    }

    printf("  FAIL   %s\n         got      %s\n         expected %s\n", $label, $a, $e);
}

/** Prints a section title. */
function section(string $title): void
{
    printf("\n%s\n", $title);
}

// The browser dependencies come first: without them, the outputs lack
// their pieces and dozens of checks would fail for that one reason. It is
// said once, and the suite stops there.
$expectedAssets = ['jquery-3.7.1.min.js', 'chessboard-1.0.0.min.js', 'chessboard-1.0.0.min.css', 'chess-1.4.0.js'];
foreach (['wK', 'wQ', 'wR', 'wB', 'wN', 'wP', 'bK', 'bQ', 'bR', 'bB', 'bN', 'bP'] as $code) {
    $expectedAssets[] = "pieces/$code.svg";
}
$missingAssets = array_values(array_filter(
    $expectedAssets,
    static fn (string $path): bool => !is_file(dirname(__DIR__) . '/assets/' . $path)
));
if ($missingAssets !== []) {
    printf(
        "assets/ lacks %d of its %d files (%s%s).\n"
            . "Run sh fetch-assets.sh from the root of the project, then this suite again.\n",
        count($missingAssets),
        count($expectedAssets),
        $missingAssets[0],
        count($missingAssets) > 1 ? ', ...' : ''
    );
    exit(1);
}

// =======================================================================
section('Placement validation: legitimate cases');
// =======================================================================

check(
    'empty board',
    chb_validate_placement('8/8/8/8/8/8/8/8'),
    '8/8/8/8/8/8/8/8'
);

check(
    'starting position',
    chb_validate_placement('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR'),
    'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR'
);

// A full FEN copied from Lichess must go through: it is the format most
// often pasted, and the one printed at the bottom of the page.
check(
    'full FEN, six fields',
    chb_validate_placement('8/8/8/4k3/8/8/8/4K3 w - - 0 1'),
    '8/8/8/4k3/8/8/8/4K3'
);

check(
    'leading and trailing spaces',
    chb_validate_placement('   8/8/8/8/8/8/8/8 b - - 0 1   '),
    '8/8/8/8/8/8/8/8'
);

// =======================================================================
section('Placement validation: invalid structures');
// =======================================================================

check('rank of nine squares', chb_validate_placement('9/8/8/8/8/8/8/8'), null);
check('seven ranks', chb_validate_placement('8/8/8/8/8/8/8'), null);
check('nine ranks', chb_validate_placement('8/8/8/8/8/8/8/8/8'), null);
check('incomplete rank', chb_validate_placement('7/8/8/8/8/8/8/8'), null);
check('empty string', chb_validate_placement(''), null);
check('unknown piece', chb_validate_placement('xnbqkbnr/8/8/8/8/8/8/8'), null);
check('5 kB string', chb_validate_placement(str_repeat('8/', 3000)), null);

// =======================================================================
section('Placement validation: hostile payloads');
// =======================================================================

// The function does not clean, it validates against an allow list. These
// payloads are therefore not filtered, they simply do not exist in the
// allowed alphabet. That does not make them enough to watch over the
// alphabet, though: see the next section.
$hostile = [
    'simple traversal'       => '../../etc/passwd',
    'deep traversal'         => '../../../home/user/.bash_profile',
    'doubled traversal'      => '....//....//etc/passwd',
    'encoded traversal'      => '..%2f..%2fetc%2fpasswd',
    'double encoding'        => '%252e%252e%252fetc%252fpasswd',
    'absolute path'          => '/etc/passwd',
    'Windows backslash'      => '..\\..\\windows\\system32',
    'php wrapper'            => 'php://filter/convert.base64-encode/resource=index.php',
    'data wrapper'           => 'data://text/plain;base64,PD9waHA=',
    'file wrapper'           => 'file:///etc/passwd',
    'tilde'                  => '~/.ssh/id_rsa',
    'shell substitution'     => '$(cat /etc/passwd)',
    'backtick'               => '`id`',
    'null byte'              => "8/8/8/8/8/8/8/8\x00../../etc/passwd",
    'trailing line feed'     => "8/8/8/8/8/8/8/8\n../../etc/passwd",
];

foreach ($hostile as $name => $payload) {
    check($name, chb_validate_placement($payload), null);
}

// Special case: everything after the first space is dropped, so the
// payload disappears and the valid placement remains. This is the intended
// behaviour, not a bypass.
check(
    'injection after a space: the payload is dropped',
    chb_validate_placement('8/8/8/8/8/8/8/8 ; cat /etc/passwd'),
    '8/8/8/8/8/8/8/8'
);

// =======================================================================
section('Placement validation: alphabet, anchors and size');
// =======================================================================

// The alphabet is checked before the structure, and every payload in the
// previous block is rejected for several reasons at once: several
// characters outside the alphabet, and a structure that is not eight
// ranks of eight squares. Weakening a single one of these barriers
// therefore never changes their verdict: widening the character class
// would go unnoticed. To watch over the alphabet, a payload must have a
// single flaw, one character grafted into a placement whose structure is
// valid. "7X/8/8/8/8/8/8/8" has eight files as soon as X counts as one
// square.
//
// The alphabet is finite: rather than imagine payloads, all 256 bytes are
// tried. Only the twelve piece codes can pass: the digit 1 would make
// eight squares (7 + 1 = 8), but "71" puts two digits side by side, which
// is not FEN (see the consecutive digits section).
$accepted = [];
for ($byte = 0; $byte < 256; $byte++) {
    if (chb_validate_placement('7' . chr($byte) . '/8/8/8/8/8/8/8') !== null) {
        $accepted[] = chr($byte);
    }
}
sort($accepted);

$expected = str_split('BKNPQRbknpqr');
sort($expected);

check('the 256 bytes in a piece slot', $accepted, $expected);

// Same sweep after an 8: the rank is already full, only a 0 (which counts
// for no square) could let the structure through.
$accepted = [];
for ($byte = 0; $byte < 256; $byte++) {
    if (chb_validate_placement('8' . chr($byte) . '/8/8/8/8/8/8/8') !== null) {
        $accepted[] = chr($byte);
    }
}

check('the 256 bytes after a full rank', $accepted, []);

// The anchors. trim() runs before the cut at the first space: a line feed
// placed just before that space therefore ends up at the end of the
// string when the regular expression runs. With ^ and $ it would be
// tolerated, then counted as one square by the square count (7 + 1 = 8).
// It is the only case where the two anchors differ. The "trailing line
// feed" entry of the previous block puts the line feed in the middle of
// the string: it does not tell them apart.
check(
    'trailing line feed left by the cut at the space',
    chb_validate_placement("8/8/8/8/8/8/8/7\n w - - 0 1"),
    null
);

// The size guard. The 71 character limit only covers what comes before
// the first space: without the 200 byte guard, a valid placement followed
// by any amount of data would be accepted.
check(
    'valid placement followed by 300 bytes',
    chb_validate_placement('8/8/8/8/8/8/8/8 ' . str_repeat('x', 300)),
    null
);

// Multibyte sequences: what the byte by byte sweep does not cover. They
// would become dangerous the day the class switched to /u mode with
// Unicode categories.
$multibyte = [
    'fullwidth slash U+FF0F'        => "7K\u{FF0F}8/8/8/8/8/8/8",
    'fullwidth full stop U+FF0E'    => "7\u{FF0E}/8/8/8/8/8/8/8",
    'Cyrillic K U+041A'             => "7\u{041A}/8/8/8/8/8/8/8",
    'overlong slash C0 AF'          => "7K\xC0\xAF8/8/8/8/8/8/8",
    'overlong full stop C0 AE'      => "7\xC0\xAE/8/8/8/8/8/8/8",
    'leading byte order mark'       => "\u{FEFF}8/8/8/8/8/8/8/8",
    'no-break space then payload'   => "8/8/8/8/8/8/8/8\u{00A0}../../etc/passwd",
];

foreach ($multibyte as $name => $payload) {
    check($name, chb_validate_placement($payload), null);
}

// The placement is written back into index.php, in a value attribute and
// in a data-* attribute. These two payloads are a reminder of why quotes
// and angle brackets have no business in the alphabet.
check(
    'breaking out of an HTML attribute',
    chb_validate_placement('7"><script>alert(1)</script>/8/8/8/8/8/8/8'),
    null
);
check(
    'breaking out of a script block',
    chb_validate_placement('7</script><script>alert(1)</script>/8/8/8/8/8/8/8'),
    null
);

// Behaviour of trim(): a null byte or whitespace at the edge is removed,
// not rejected. It is harmless (the string never touches the disk) but
// worth knowing, and pinning down.
check(
    'trailing null byte removed by trim',
    chb_validate_placement("8/8/8/8/8/8/8/8\x00"),
    '8/8/8/8/8/8/8/8'
);

// =======================================================================
section('Placement validation: consecutive digits');
// =======================================================================

// A digit compresses a run of empty squares: two digits in a row are
// never FEN. "44" reads as "8", and the rank total comes out right, but
// chess.js and Lichess reject these strings; the FEN printed at the
// bottom of the diagram could then no longer be loaded again.
check('44 rejected', chb_validate_placement('44/8/8/8/8/8/8/8'), null);
check('11111111 rejected', chb_validate_placement('11111111/8/8/8/8/8/8/8'), null);
check('digits at the end of a rank', chb_validate_placement('8/8/8/8/8/8/8/K16'), null);
check('digits between pieces', chb_validate_placement('8/8/8/8/8/8/8/K15K'), null);

// The separator breaks the run: "8/8" does not put two digits side by side.
check('digits separated by a slash', chb_validate_placement('8/8/8/8/8/8/8/8'), '8/8/8/8/8/8/8/8');
check('digits separated by a piece', chb_validate_placement('8/8/8/8/8/8/8/3K4'), '8/8/8/8/8/8/8/3K4');

// =======================================================================
section('Piece path resolution');
// =======================================================================

// The only place in the code that touches the disk. Second allow list.
foreach (['wK', 'wQ', 'wR', 'wB', 'wN', 'wP', 'bK', 'bQ', 'bR', 'bB', 'bN', 'bP'] as $code) {
    check('piece ' . $code . ' present', chb_piece_path($code) !== null, true);
}

$hostileCodes = [
    '../../../etc/passwd',
    'wK/../../../etc/passwd',
    "wK\x00../../etc/passwd",
    '/etc/passwd',
    'php://filter/resource=index.php',
    'wKK', 'xK', 'wk', 'wX', '', '..', 'w/K', '%2e%2e%2fwK',
];

foreach ($hostileCodes as $i => $code) {
    check('hostile piece code ' . ($i + 1), chb_piece_path($code), null);
}

// =======================================================================
section('Grid conversion');
// =======================================================================

$grid = chb_placement_to_grid('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR');

check('a8: black rook', $grid[0][0], 'bR');
check('h8: black rook', $grid[0][7], 'bR');
check('e8: black king', $grid[0][4], 'bK');
check('d8: black queen', $grid[0][3], 'bQ');
check('a1: white rook', $grid[7][0], 'wR');
check('e1: white king', $grid[7][4], 'wK');
check('d4: empty square', $grid[4][3], null);
check('eight ranks', count($grid), 8);
check('eight files', count($grid[0]), 8);

// =======================================================================
section('Square parity');
// =======================================================================

// The rule, identical in the PDF output and in the HTML output: a square
// is dark when (column + rank) is even, where rank is 0 for the first
// rank, so that a1 is dark as on a real board.
$dark = static fn (int $column, int $rank): bool => (($column + $rank) % 2 === 0);

check('a1 is dark', $dark(0, 0), true);
check('h1 is light', $dark(7, 0), false);
check('a8 is light', $dark(0, 7), false);
check('h8 is dark', $dark(7, 7), true);
check('e4 is light', $dark(4, 3), false);
check('d4 is dark', $dark(3, 3), true);

// =======================================================================
section('Template geometry');
// =======================================================================

// Reference values of the template, to a hundredth of a point.
check('page width', round(CHB_PAGE_W, 2), 595.28);
check('page height', round(CHB_PAGE_H, 2), 841.89);
check('square side', round(CHB_SQUARE, 2), 68.03);
check('board side', round(CHB_BOARD, 2), 544.25);
check('left edge', round(CHB_BOARD_LEFT, 2), 25.51);
check('top edge', round(CHB_BOARD_TOP, 2), 106.3);
check('square grey', CHB_GREY_DARK, 204);

// =======================================================================
section('Full FEN assembly');
// =======================================================================

check(
    'White to move',
    chb_full_fen('8/8/8/8/8/8/8/8', 'w'),
    '8/8/8/8/8/8/8/8 w - - 0 1'
);

check(
    'Black to move',
    chb_full_fen('8/8/8/8/8/8/8/8', 'b'),
    '8/8/8/8/8/8/8/8 b - - 0 1'
);

check(
    'absurd side to move brought back to White',
    chb_full_fen('8/8/8/8/8/8/8/8', 'z'),
    '8/8/8/8/8/8/8/8 w - - 0 1'
);

// =======================================================================
section('Side to move read from a full FEN');
// =======================================================================

// Same rule as the paste field of the input page: the second field is
// taken only if it is exactly "w" or "b", and only if the placement before
// it is valid. Anything else is ignored, and the placement stays usable.
if (!function_exists('chb_fen_turn') || !function_exists('chb_resolve_turn')) {
    check('chb_fen_turn() and chb_resolve_turn() exist', false, true);
} else {
    $kings = '8/8/8/4k3/8/8/8/4K3';
    $turnCases = [
        'black to move'                     => ["$kings b - - 0 1", 'b'],
        'white to move'                     => ["$kings w - - 0 1", 'w'],
        'two fields only'                   => ["$kings b", 'b'],
        'several spaces'                    => ["$kings   b - - 0 1", 'b'],
        'surrounding spaces'                => ["  $kings b - - 0 1  ", 'b'],
        'trailing null byte removed by trim' => ["$kings b\x00", 'b'],
        'bare placement'                    => [$kings, null],
        'upper case'                        => ["$kings B - - 0 1", null],
        'whole word'                        => ["$kings black", null],
        'letter followed by a tag'          => ["$kings b<script>", null],
        'fullwidth letter U+FF42'           => ["$kings \u{FF42} - - 0 1", null],
        'null byte glued to the letter'     => ["$kings b\x00 - - 0 1", null],
        'line feed glued to the letter'     => ["$kings b\n- - 0 1", null],
        'tab as separator'                  => ["$kings\tb - - 0 1", null],
        'invalid placement'                 => ['9/8/8/8/8/8/8/8 b - - 0 1', null],
        'valid start, 300 bytes after'      => ["$kings b " . str_repeat('x', 300), null],
    ];
    foreach ($turnCases as $name => [$fen, $expectedTurn]) {
        check('FEN side to move: ' . $name, chb_fen_turn($fen), $expectedTurn);
    }

    // Order of precedence: an explicit turn wins, an invalid one is ignored.
    check('explicit turn wins over the FEN', chb_resolve_turn('w', "$kings b - - 0 1"), 'w');
    check('invalid turn ignored, FEN used', chb_resolve_turn('x', "$kings b - - 0 1"), 'b');
    check('nothing usable: White', chb_resolve_turn('', $kings), 'w');
}

// =======================================================================
section('Orientation of the diagram');
// =======================================================================

// Black at the bottom when the board was flipped on the input page, or
// when Black is to move. Only the exact value "black" counts as a flip.
$orientationCases = [
    'board as is, White to move'  => ['white', 'w', false],
    'flipped board, White to move' => ['black', 'w', true],
    'board as is, Black to move'  => ['white', 'b', true],
    'flipped board, Black to move' => ['black', 'b', true],
    'missing orientation'         => ['', 'w', false],
    'upper case'                  => ['BLACK', 'w', false],
    'trailing space'              => ['black ', 'w', false],
    'tag after the value'         => ['black<script>', 'w', false],
];
foreach ($orientationCases as $name => [$orientationValue, $turnValue, $expectedBottom]) {
    check('Black at the bottom: ' . $name, chb_black_at_bottom($orientationValue, $turnValue), $expectedBottom);
}

// Half a turn: a1 goes to the top right, h1 to the top left.
$turned = chb_orient_grid(chb_placement_to_grid('8/8/8/8/8/8/8/K6k'), true);
check('half a turn: a1 top right, h1 top left', [$turned[0][7], $turned[0][0], $turned[7][7]], ['wK', 'bK', null]);
check(
    'coordinates seen from Black',
    chb_board_labels(true),
    [['h', 'g', 'f', 'e', 'd', 'c', 'b', 'a'], ['1', '2', '3', '4', '5', '6', '7', '8']]
);
check(
    'coordinates seen from White',
    chb_board_labels(false),
    [['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], ['8', '7', '6', '5', '4', '3', '2', '1']]
);

// =======================================================================
section('Annotation cleaning');
// =======================================================================

check(
    'ordinary text intact',
    chb_clean_notes('Sicilian Defence, Najdorf'),
    'Sicilian Defence, Najdorf'
);

check(
    'multiple spaces normalised',
    chb_clean_notes('  too   many   spaces  '),
    'too many spaces'
);

check(
    'control characters replaced',
    chb_clean_notes("before\x01\x02after"),
    'before after'
);

// Unicode format characters (category Cf) are not displayed but act on
// the text: U+202E reverses the end of the line in the HTML output,
// U+200B silently splits or joins words. They are removed, with no space
// in their place, so that the text stays what the user saw. Control
// characters, on the other hand, separate two words: they are replaced
// with a space, the C1 block ones (U+0080 to U+009F) included.
$invisible = [
    'right-to-left override U+202E removed' => ["abc\u{202E}def", 'abcdef'],
    'embeddings U+202A to U+202D removed'   => ["a\u{202A}b\u{202B}c\u{202C}d\u{202D}e", 'abcde'],
    'isolates U+2066 to U+2069 removed'     => ["a\u{2066}b\u{2067}c\u{2068}d\u{2069}e", 'abcde'],
    'direction marks U+200E U+200F'         => ["a\u{200E}b\u{200F}c", 'abc'],
    'zero width space U+200B removed'       => ["word\u{200B}word", 'wordword'],
    'byte order mark U+FEFF'                => ["\u{FEFF}text", 'text'],
    'soft hyphen U+00AD'                    => ["ana\u{00AD}lysis", 'analysis'],
    'emoji zero width joiner U+200D removed' => ["\u{1F468}\u{200D}\u{1F469}", "\u{1F468}\u{1F469}"],
    'C1 control U+0090 replaced'            => ["before\u{0090}after", 'before after'],
    'C1 control U+0085 replaced'            => ["before\u{0085}after", 'before after'],
    'format character between two spaces'   => ["a \u{200B} b", 'a b'],
];
foreach ($invisible as $name => [$input, $expectedText]) {
    check($name, chb_clean_notes($input), $expectedText);
}

// Removed before truncation, invisible characters do not use up the
// limit: only what shows counts.
check(
    'invisible characters outside the limit',
    chb_clean_notes(str_repeat("\u{200B}", CHB_NOTES_MAX) . 'visible'),
    'visible'
);

// Size guard, mirroring the FEN one: 194 code points take at most
// 4 x 194 = 776 bytes in UTF-8. Beyond that, the input does not come from
// the form, and no regular expression must run over it. generate.php
// then refuses the request with a 400; the function returns an empty
// annotation, to stay safe whatever the caller.
check(
    'size: 776 bytes accepted and truncated',
    (int) preg_match_all('/./u', chb_clean_notes(str_repeat('a', 776))),
    CHB_NOTES_MAX
);
check('size: 777 bytes discarded', chb_clean_notes(str_repeat('a', 777)), '');
check(
    'size: 194 four-byte emoji intact',
    chb_clean_notes(str_repeat("\u{1F600}", CHB_NOTES_MAX)),
    str_repeat("\u{1F600}", CHB_NOTES_MAX)
);

check(
    'no-break space normalised',
    chb_clean_notes("before\u{00A0}\u{00A0}after"),
    'before after'
);

check(
    'accents preserved',
    chb_clean_notes('Café, naïve, Ægir, señor, échec et mat'),
    'Café, naïve, Ægir, señor, échec et mat'
);

// The function is deliberately written without mbstring, but it must
// count code points and not bytes.
$longAccented = str_repeat('é', CHB_NOTES_MAX + 50);
$truncated    = chb_clean_notes($longAccented);

check(
    'truncation at the limit, in characters',
    (int) preg_match_all('/./u', $truncated),
    CHB_NOTES_MAX
);

check(
    'truncation does not break UTF-8',
    preg_match('##u', $truncated),
    1
);

check(
    'invalid UTF-8 rejected as a whole',
    chb_clean_notes("\xC3\x28 broken"),
    ''
);

check(
    'tags left as they are, escaping happens on output',
    chb_clean_notes('<script>alert(1)</script>'),
    '<script>alert(1)</script>'
);

// The code is written without mbstring: no mb_*() call anywhere.
$mbCalls = [];
foreach (['index.php', 'generate.php', 'lib/chessboard.php', 'lib/output-html.php', 'lib/output-pdf.php'] as $source) {
    if (preg_match('#\bmb_[a-z_]+\s*\(#', (string) file_get_contents(dirname(__DIR__) . '/' . $source)) === 1) {
        $mbCalls[] = $source;
    }
}
check('no mbstring function called', $mbCalls, []);

// =======================================================================
section('Reading request parameters');
// =======================================================================

// "fen[]=x" in a request turns $_POST['fen'] into an array. Every
// parameter read therefore goes through chb_param(), which only returns
// strings. If the function is missing, this is reported as a single
// readable failure rather than letting PHP stop on a fatal error.
if (!function_exists('chb_param')) {
    check('chb_param() exists', false, true);
} else {
    $source = ['fen' => '8/8/8/8/8/8/8/8', 'list' => ['a', 'b'], 'nested' => [['x']], 'empty' => ''];
    check('string parameter returned as is', chb_param($source, 'fen'), '8/8/8/8/8/8/8/8');
    check('missing parameter: default value', chb_param($source, 'format', 'pdf'), 'pdf');
    check('missing parameter, no default: empty', chb_param($source, 'format'), '');
    check('array parameter: empty string', chb_param($source, 'list', 'pdf'), '');
    check('nested array parameter: empty', chb_param($source, 'nested'), '');
    check('empty parameter: no default value', chb_param($source, 'empty', 'pdf'), '');
}

// =======================================================================
section('HTML output');
// =======================================================================

require_once __DIR__ . '/output-html.php';

$output = chb_output_html('8/8/8/4k3/8/8/8/4K3', 'b', 'King endgame', true);

check('complete document', str_contains($output, '<!DOCTYPE html>'), true);
check('sixty-four squares', substr_count($output, '<td class="square'), 64);
check('two kings placed', substr_count($output, 'class="piece"'), 2);
check('annotation present', str_contains($output, 'King endgame'), true);
check('full FEN at the bottom', str_contains($output, '8/8/8/4k3/8/8/8/4K3 b - - 0 1'), true);
check('FEN preceded by its label', str_contains($output, 'FEN: 8/8/8/4k3/8/8/8/4K3'), true);

// The side to move is stated in words, and by a dot.
check('side to move stated', str_contains($output, 'Black to move'), true);
check('filled dot', str_contains($output, 'dot-black'), true);

$outputWhite = chb_output_html('8/8/8/8/8/8/8/8', 'w', '', true);
check('White to move stated', str_contains($outputWhite, 'White to move'), true);
check('empty dot', str_contains($outputWhite, 'dot-white'), true);
check('round dot', str_contains($output, 'border-radius: 50%'), true);

// The page is meant to be saved or forwarded: no link must point back to
// the machine that produced it.
check('no href', str_contains($output, 'href='), false);
check('no src', str_contains($output, 'src='), false);
check('no favicon', str_contains($output, 'favicon'), false);

// MultiCell breaks a word that is too long in the PDF. The HTML must do
// the same, otherwise an annotation without spaces runs off the page.
check('long words broken', str_contains($output, 'overflow-wrap: anywhere'), true);

// Escaping happens on output, not on cleaning.
$outputXss = chb_output_html('8/8/8/8/8/8/8/8', 'w', '<script>alert(1)</script>', true);
check('tag escaped', str_contains($outputXss, '&lt;script&gt;'), true);
check('tag not executable', str_contains($outputXss, '<script>alert(1)</script>'), false);

// Seen from Black: coordinates reversed, pieces turned by half a turn,
// and the bottom left square still dark (h8).
$htmlBlackSide = chb_output_html('8/8/8/8/8/8/8/K6k', 'w', '', true, true);
preg_match_all('#<td class="file-label">([a-h])</td>#', $htmlBlackSide, $fileLabels);
preg_match_all('#<td class="(square[^"]*)">(.*?)</td>#s', $htmlBlackSide, $cells);
check(
    'HTML seen from Black',
    [
        implode('', array_slice($fileLabels[1], 0, 8)),
        array_keys(array_filter($cells[2], static fn (string $c): bool => str_contains($c, '<svg'))),
        str_contains($cells[1][56] ?? '', 'dark'),
    ],
    ['hgfedcba', [0, 7], true]
);

// =======================================================================
section('Environment and TCPDF location');
// =======================================================================

// The suite runs on several systems: Debian Trixie and Bookworm
// (php-tcpdf package), FreeBSD (no TCPDF port, installation through
// Composer). What was found is printed, so that a failure report says
// straight away in which environment it was produced.
printf("  (info) PHP %s, %s\n", PHP_VERSION, PHP_OS_FAMILY);

// The code requires PHP 8.0 (str_contains, union types) and the ctype
// extension (ctype_digit). TCPDF reads the piece SVGs with the xml
// extension.
check('PHP 8.0 or later', PHP_VERSION_ID >= 80000, true);
check('ctype extension', extension_loaded('ctype'), true);
check('xml extension', extension_loaded('xml'), true);

// TCPDF is looked up by chb_tcpdf_path(): the STAUNTON_TCPDF environment
// variable if it is set, otherwise the Debian package, otherwise a
// Composer vendor/autoload.php at the root of the project.
$requirePdf = 'define("CHB_ENTRY", true); require ' . var_export(__DIR__ . '/output-pdf.php', true) . ';';

if (!function_exists('chb_tcpdf_path')) {
    check('chb_tcpdf_path() exists', false, true);
} else {
    $before = getenv('STAUNTON_TCPDF');

    // A variable that is set wins, and points to exactly the file wanted.
    $decoy = tempnam(sys_get_temp_dir(), 'staunton-tcpdf-');
    putenv('STAUNTON_TCPDF=' . $decoy);
    check('STAUNTON_TCPDF points to the file', chb_tcpdf_path(), $decoy);

    // A wrong variable does not silently fall back on another TCPDF: it is
    // a configuration error, which must show.
    putenv('STAUNTON_TCPDF=' . $decoy . '-missing');
    check('wrong STAUNTON_TCPDF: no fallback', chb_tcpdf_path(), null);
    @unlink($decoy);

    // Without the variable, automatic detection returns a readable file,
    // or null if TCPDF is installed nowhere.
    putenv('STAUNTON_TCPDF');
    $detected = chb_tcpdf_path();
    check('automatic detection consistent', $detected === null || is_readable($detected), true);

    if ($before !== false) {
        putenv('STAUNTON_TCPDF=' . $before);
    }

    // TCPDF not found: a clear message and a non-zero exit code, not a
    // "Failed opening required" fatal error.
    $pipes = [];
    $proc  = proc_open(
        [PHP_BINARY, '-r', 'define("CHB_ENTRY", true); require ' . var_export(__DIR__ . '/output-pdf.php', true) . ';'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['STAUNTON_TCPDF' => '/missing/path/tcpdf.php', 'PATH' => (string) getenv('PATH')]
    );
    $stdout = is_resource($proc)
        ? (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2])
        : '';
    $code   = is_resource($proc) ? proc_close($proc) : -1;
    check(
        'TCPDF not found: clear message',
        [str_contains($stdout, 'TCPDF not found'), str_contains($stdout, 'Failed opening'), $code !== 0],
        [true, false, true]
    );

    // TCPDF found but unusable: a clear message and a non-zero exit code
    // as well. Three fakes cover the tests made by output-pdf.php: a TCPDF
    // class without TCPDF_STATIC, a TCPDF_STATIC that reports version 7,
    // and one that stops on an undefined curl constant, as TCPDF 6.8 and
    // later do without the curl extension.
    $fakes = [
        'without TCPDF_STATIC'   => ["<?php\nclass TCPDF {}\n", 'Unsupported TCPDF version'],
        'version 7'              => [
            "<?php\nclass TCPDF {}\nclass TCPDF_STATIC {\n"
                . "    public static function getTCPDFVersion(): string { return '7.0.0'; }\n}\n",
            'Unsupported TCPDF version',
        ],
        'curl extension missing' => [
            "<?php\nclass TCPDF {}\nclass TCPDF_STATIC {\n    const TIMEOUT = CURLOPT_STAUNTON_TEST;\n"
                . "    public static function getTCPDFVersion(): string { return (string) self::TIMEOUT; }\n}\n",
            'need the PHP curl extension',
        ],
    ];
    foreach ($fakes as $name => [$source, $message]) {
        $fake = (string) tempnam(sys_get_temp_dir(), 'staunton-fake-tcpdf-');
        file_put_contents($fake, $source);
        [$out, $exit, $err] = run_command(
            [PHP_BINARY, '-r', $requirePdf],
            ['STAUNTON_TCPDF' => $fake, 'PATH' => (string) getenv('PATH')]
        );
        check(
            "unusable TCPDF refused with a clear message ($name)",
            [str_contains($out, $message), str_contains($out . $err, 'Fatal'), $exit !== 0],
            [true, false, true]
        );
        @unlink($fake);
    }
}

// =======================================================================
section('PDF output');
// =======================================================================

// TCPDF is a dependency of the project, but the test remains usable
// without it: its absence is reported rather than failing. A TCPDF that is
// present but unsupported is a failure; output-pdf.php stops on it, so it
// is first loaded in a separate process, and the PDF checks are skipped.
$tcpdfUsable = false;
if (function_exists('chb_tcpdf_path') && chb_tcpdf_path() !== null) {
    [$probe] = run_command([PHP_BINARY, '-r', $requirePdf . ' echo "usable";']);
    $tcpdfUsable = ($probe === 'usable');
    check('TCPDF found is supported', $tcpdfUsable, true);
}

if ($tcpdfUsable) {
    require_once __DIR__ . '/output-pdf.php';
    printf("  (info) TCPDF %s, %s\n", TCPDF_STATIC::getTCPDFVersion(), (string) chb_tcpdf_path());

    $pdfBlack = chb_output_pdf('8/8/8/4k3/8/8/8/4K3', 'b', 'King endgame', true);
    $pdfWhite = chb_output_pdf('8/8/8/8/8/8/8/8', 'w', '', true);

    check('PDF header', substr($pdfBlack, 0, 5), '%PDF-');
    // The /Pages node holds the count. Counting "/Type /Page" would be
    // wrong: the string also appears in "/Type /Pages".
    preg_match('#/Count\s+(\d+)#', $pdfBlack, $pages);
    check('a single page', $pages[1] ?? '?', '1');

    // Seen from Black: same template, the board turned by half a turn.
    $pdfBlackSide = chb_output_pdf('8/8/8/8/8/8/8/K6k', 'w', '', true, true);
    preg_match('#/Count\s+(\d+)#', $pdfBlackSide, $blackSidePages);
    check('PDF seen from Black', [substr($pdfBlackSide, 0, 5), $blackSidePages[1] ?? '?'], ['%PDF-', '1']);

    // The annotation is the only free text that reaches the content
    // stream. TCPDF escapes parentheses and backslashes; if that escaping
    // went away, these characters would close the string and the rest
    // would be read as PDF operators. Such a document would have a
    // different number of objects or pages.
    $pdfHostile = chb_output_pdf(
        '8/8/8/4k3/8/8/8/4K3',
        'b',
        chb_clean_notes("a) Tj ET \\) endstream endobj 999 0 obj << /Type /Catalog >> ((("),
        true
    );
    preg_match('#/Count\s+(\d+)#', $pdfHostile, $hostilePages);
    check('hostile annotation: a single page', $hostilePages[1] ?? '?', '1');
    check(
        'hostile annotation: same number of objects',
        substr_count($pdfHostile, ' 0 obj'),
        substr_count($pdfBlack, ' 0 obj')
    );
    check('hostile annotation: header intact', substr($pdfHostile, 0, 5), '%PDF-');

    // TCPDF slips a promotional link to its website in Close(). The
    // ChessboardPdf subclass switches it off; let us check it still does.
    check('no link annotation', substr_count($pdfBlack, '/URI'), 0);

    // TCPDF writes its address in the Producer, info and XMP. The
    // information dictionary is in UTF-16BE: the string is therefore
    // searched for in both forms.
    $utf16 = implode('', array_map(static fn (string $c): string => "\0" . $c, str_split('tcpdf.org')));
    check('no tcpdf.org address', str_contains($pdfBlack, 'tcpdf.org'), false);
    check('no address in UTF-16', str_contains($pdfBlack, $utf16), false);
    check('no address, blank sheet', str_contains($pdfWhite, $utf16), false);

    // The XMP packet got shorter: its declared length must follow.
    $xmpOk = preg_match('#/Subtype /XML /Length (\d+) >> stream\n(.*?)\nendstream#s', $pdfBlack, $xmp) === 1
        && (int) $xmp[1] === strlen($xmp[2]);
    check('consistent XMP length', $xmpOk, true);

    check('plausible size, with pieces', strlen($pdfBlack) > 5000, true);
    check('plausible size, blank sheet', strlen($pdfWhite) > 3000, true);

    // Metadata: no data coming from the user must get in. ChessboardPdf::
    // _out() rewrites the objects outside the page streams that contain
    // "Producer"; free text in those objects would make that rewriting
    // remotely triggerable. The metadata objects (information dictionary,
    // XMP packet), which are never compressed, are therefore isolated, and
    // the annotation is looked for in them, in UTF-8 as well as UTF-16BE.
    $marker   = 'MetadataMarker';
    $pdfMeta  = chb_output_pdf('8/8/8/4k3/8/8/8/4K3', 'w', $marker, true);
    $marker16 = implode('', array_map(static fn (string $c): string => "\0" . $c, str_split($marker)));
    preg_match_all('#\d+ 0 obj\b.*?endobj#s', $pdfMeta, $objects);
    $meta = array_values(array_filter(
        $objects[0],
        static fn (string $o): bool => str_contains($o, '/Producer') || str_contains($o, 'xmpmeta')
    ));
    $leak = false;
    foreach ($meta as $object) {
        $leak = $leak || str_contains($object, $marker) || str_contains($object, $marker16);
    }
    check('metadata found (info and XMP)', count($meta) >= 2, true);
    check('annotation absent from metadata', $leak, false);

    // Annotation height. Seven lines fit between the board and the FEN, an
    // eighth one overlaps it. MultiCell() breaks lines on spaces: a word
    // that does not fit at the end of a line moves whole to the next one.
    // Words of a little more than half a line therefore leave half of
    // every line empty, and it is this worst case, not the glyph width
    // alone, that sets CHB_NOTES_MAX.
    //
    // chb_output_pdf() measures the text before writing it and throws
    // LengthException beyond seven lines: this is the safety net that
    // protects the template should the font, the board width or TCPDF's
    // line breaking change.
    $renderRefused = static function (string $notes): bool {
        try {
            chb_output_pdf('8/8/8/8/8/8/8/8', 'w', $notes, true);
        } catch (LengthException $e) {
            return true;
        }
        return false;
    };
    $atSigns = static fn (int ...$words): string
        => implode(' ', array_map(static fn (int $n): string => str_repeat('@', $n), $words));

    // The worst case, found by an exhaustive search over every sequence of
    // words made of at signs (the widest glyph in Helvetica): 195
    // characters are enough to force an eighth line. Each word of 42 fills
    // most of a line, and the word of 7 that follows can no longer fit
    // behind it, and so on.
    $worstCase = $atSigns(42, 7, 42, 7, 42, 7, 42);
    check('measured worst case: 195 characters', (int) preg_match_all('/./u', $worstCase), 195);
    check('raw worst case refused on render', $renderRefused($worstCase), true);
    check('eleven words of 25 at signs refused', $renderRefused($atSigns(...array_fill(0, 11, 25))), true);

    // Without spaces, lines break on characters: 288 at signs make exactly
    // seven lines, 289 make eight. The safety net must not trigger too
    // early.
    check('288 at signs without spaces accepted', $renderRefused(str_repeat('@', 288)), false);
    check('289 at signs without spaces refused', $renderRefused(str_repeat('@', 289)), true);

    // Once cleaned, no input must reach the safety net any more.
    check(
        'cleaned worst case brought to the limit',
        (int) preg_match_all('/./u', chb_clean_notes($worstCase . '@')),
        CHB_NOTES_MAX
    );
    check('cleaned worst case accepted', $renderRefused(chb_clean_notes($worstCase)), false);
    check(
        'cleaned eleven words accepted',
        $renderRefused(chb_clean_notes($atSigns(...array_fill(0, 11, 25)))),
        false
    );
} else {
    printf("  (skipped) TCPDF missing or unsupported, PDF checks not run\n");
}

// =======================================================================
section('Asset checksums');
// =======================================================================

// The piece SVGs are inlined as is into the generated HTML page: an
// altered file would become code executed there. fetch-assets.sh pins the
// checksums of the sixteen files, checked against their upstream sources
// (npm for the libraries, the lichess repository at the pinned commit for
// the pieces). What is on disk is checked first, in pure PHP.
$root      = dirname(__DIR__);
$files     = $expectedAssets;
$script    = @file_get_contents($root . '/fetch-assets.sh');
$checksums = [];

if ($script === false) {
    printf("  (skipped) fetch-assets.sh not found: no pinned checksums to compare with\n");
} else {
    // The checksums sit in the here-document of pinned_checksums(), in the
    // sha256sum format: checksum, two spaces, path.
    if (preg_match("#^pinned_checksums\(\) \{\n    cat <<'EOF'\n(.*?)\nEOF\n#ms", $script, $block) === 1) {
        foreach (explode("\n", $block[1]) as $line) {
            if (preg_match('#\A([0-9a-f]{64})  (\S+)\z#', $line, $m) === 1) {
                $checksums[$m[2]] = $m[1];
            }
        }
    }
    $listed = array_keys($checksums);
    sort($listed);
    $expected = $files;
    sort($expected);
    check('pinned checksums cover the sixteen files', $listed, $expected);

    $altered = [];
    foreach ($checksums as $path => $checksum) {
        if (hash_file('sha256', $root . '/assets/' . $path) !== $checksum) {
            $altered[] = $path;
        }
    }
    check('assets match the pinned checksums', $altered, []);
}

// Then the fetch script itself, on a throwaway copy: every file is
// already there, so it downloads nothing and does not touch the network.
// It must accept intact files, stop on an altered file or on an
// incomplete list of checksums, and only go ahead regardless on explicit
// request.
if ($script !== false && is_executable('/bin/sh')) {
    $copy = sys_get_temp_dir() . '/staunton-test-' . bin2hex(random_bytes(6));
    mkdir($copy . '/assets/pieces', 0700, true);
    file_put_contents($copy . '/fetch-assets.sh', $script);
    foreach ($files as $path) {
        copy($root . '/assets/' . $path, $copy . '/assets/' . $path);
    }

    $runScript = static function (array $env) use ($copy): int {
        /** @var array<string, string> $fullEnv */
        $fullEnv = $env + ['PATH' => (string) getenv('PATH')];
        $pipes   = [];
        $proc    = proc_open(
            ['/bin/sh', 'fetch-assets.sh'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $copy,
            $fullEnv
        );
        return is_resource($proc) ? proc_close($proc) : -1;
    };

    check('script: intact files accepted', $runScript([]), 0);

    file_put_contents($copy . '/assets/pieces/wK.svg', '<svg onload="alert(1)"/>');
    check('script: altered SVG refused', $runScript([]) !== 0, true);
    check(
        'script: explicit bypass',
        $runScript(['STAUNTON_SKIP_CHECKSUMS' => '1']),
        0
    );

    // An incomplete list of checksums: the three tools only check the
    // lines present, so the script makes sure every expected file is listed.
    copy($root . '/assets/pieces/wK.svg', $copy . '/assets/pieces/wK.svg');
    $incomplete = (string) preg_replace('#^[0-9a-f]{64}  pieces/wR\.svg\n#m', '', $script, 1);
    file_put_contents($copy . '/fetch-assets.sh', $incomplete);
    check('script: incomplete checksum list refused', [$incomplete !== $script, $runScript([]) !== 0], [true, true]);
    file_put_contents($copy . '/fetch-assets.sh', $script);

    // The openssl branch, used when neither sha256sum nor shasum is
    // available: run with a PATH holding only the tools the script needs.
    // The file listed last must be checked as well as the others.
    $tools = [];
    $needed = ['curl', 'mkdir', 'wc', 'tr', 'cut', 'awk', 'cat', 'rm', 'openssl'];
    foreach ($needed as $tool) {
        $found = find_executable($tool);
        if ($found !== null) {
            $tools[$tool] = $found;
        }
    }
    $last = array_key_last($checksums);
    if (count($tools) === count($needed) && $last !== null) {
        $bin = $copy . '/bin';
        mkdir($bin, 0700);
        foreach ($tools as $tool => $target) {
            symlink($target, "$bin/$tool");
        }
        check('script, openssl branch: intact files accepted', $runScript(['PATH' => $bin]), 0);

        file_put_contents($copy . '/assets/' . $last, '<svg onload="alert(1)"/>');
        check('script, openssl branch: altered file listed last refused', $runScript(['PATH' => $bin]) !== 0, true);
        copy($root . '/assets/' . $last, $copy . '/assets/' . $last);
        foreach (array_keys($tools) as $tool) {
            @unlink("$bin/$tool");
        }
        @rmdir($bin);
    } else {
        printf("  (skipped) openssl branch: a tool is missing\n");
    }

    foreach ($files as $path) {
        @unlink($copy . '/assets/' . $path);
    }
    @unlink($copy . '/fetch-assets.sh');
    @rmdir($copy . '/assets/pieces');
    @rmdir($copy . '/assets');
    @rmdir($copy);
}

// =======================================================================
section('Input page');
// =======================================================================

$indexSource = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$appSource   = (string) file_get_contents(dirname(__DIR__) . '/app.js');
$styleSource = (string) file_get_contents(dirname(__DIR__) . '/style.css');

check(
    'title and heading',
    [str_contains($indexSource, '<title>Board editor</title>'), str_contains($indexSource, '<h1>Board editor</h1>')],
    [true, true]
);

// The help card starts closed, and app.js opens it at start-up on a wide
// screen. Its threshold must stay the one of the media query that moves
// the card above the board: otherwise the card would open in the narrow
// layout and bring the scrollbar back.
check('help card closed in the markup', str_contains($indexSource, '<details id="help">'), true);
preg_match("#matchMedia\('\(max-width: (\d+)px\)'\)#", $appSource, $jsThreshold);
preg_match('#@media \(max-width: (\d+)px\) \{\s*\.card-help#', $styleSource, $cssThreshold);
check(
    'help card threshold identical in app.js and style.css',
    isset($jsThreshold[1], $cssThreshold[1]) && $jsThreshold[1] === $cssThreshold[1],
    true
);

// The address of the generator ends with the name of the file, the one
// browsers propose when saving (see app.js); generate.php accepts exactly
// these two names after its own.
$generatorSource = (string) file_get_contents(dirname(__DIR__) . '/generate.php');
check(
    'file name at the end of the generator address',
    [
        str_contains($appSource, "'generate.php/diagram.' + format"),
        str_contains($generatorSource, "['', '/diagram.pdf', '/diagram.html']"),
    ],
    [true, true]
);

// The orientation of the board goes with the form, for the diagram to
// follow a flip.
check(
    'orientation of the board sent with the form',
    [
        str_contains($indexSource, '<input type="hidden" name="orientation" id="orientation" value="white">'),
        str_contains($appSource, "$('#orientation').val(board.orientation() === 'black' ? 'black' : 'white');"),
    ],
    [true, true]
);

// English plurals: zero takes the plural ("0 pieces"), only one is singular.
check('English plurals in app.js', preg_match("#> 1 \? 's'#", $appSource), 0);

// The board width constants come from measurements in a browser (see
// style.css): changing one of them means measuring again.
check(
    'board width constants unchanged since measured',
    [
        str_contains($styleSource, 'calc(80vh - 119px - 4.75rem)'),
        str_contains($styleSource, 'min(80vh - 162px - 5.75rem, 100vw - 451px - 1.7rem)'),
    ],
    [true, true]
);

// =======================================================================
section('Configuration files');
// =======================================================================

// docs/hardening.md reproduces the two configuration files shipped in
// docs/, so that they can be read there: the copies must not drift away
// from the files that get installed.
$hardening = @file_get_contents(dirname(__DIR__) . '/docs/hardening.md');
if ($hardening === false) {
    printf("  (skipped) docs/hardening.md not found\n");
} else {
    $shippedConfs = [
        'apache' => ['apache2/staunton.conf', '<Directory '],
        'nginx'  => ['nginx/staunton.conf', 'server {'],
    ];
    foreach ($shippedConfs as $lang => [$file, $marker]) {
        $docCopy = '';
        preg_match_all('#^```' . $lang . '\n(.*?)^```#ms', $hardening, $blocks);
        foreach ($blocks[1] as $block) {
            if (str_contains($block, $marker)) {
                $docCopy = $block;
                break;
            }
        }
        $shipped = @file_get_contents(dirname(__DIR__) . '/docs/' . $file);
        check(
            "docs/$file identical to its copy in docs/hardening.md",
            $shipped !== false && $shipped === $docCopy,
            true
        );
    }
}

// =======================================================================
section('Front-end compatibility with jQuery 4');
// =======================================================================

// jQuery 4.0.0 (17 January 2026) removes several utility functions that
// the language now provides itself. The project ships jQuery 3.7.1, with
// no known vulnerability, but app.js must not stand in the way of an
// upgrade: it must call none of the removed APIs, nor holdReady(),
// deprecated but still present in 4.0.0.
$appJs = (string) file_get_contents(dirname(__DIR__) . '/app.js');
$removed = [
    'trim', 'isArray', 'isFunction', 'isNumeric', 'isWindow', 'type',
    'now', 'parseJSON', 'camelCase', 'nodeName', 'unique', 'holdReady',
];
$found = [];
foreach ($removed as $api) {
    if (preg_match('#(?:\$|\bjQuery)\s*\.\s*' . $api . '\s*\(#', $appJs) === 1) {
        $found[] = $api;
    }
}
check('app.js readable', $appJs !== '', true);
check('no API removed in jQuery 4 called', $found, []);

// =======================================================================
section('Include lock');
// =======================================================================

// The files in lib/ are not entry points. The lib/.htaccess file is read
// neither by Apache in its default Debian configuration (AllowOverride
// None), nor by nginx: the lock is therefore enforced by PHP itself.
// index.php and generate.php set the CHB_ENTRY constant; without it,
// every include stops before handing control back, and test.php refuses
// to run outside the command line.

/**
 * Runs a command without going through a shell and returns its standard
 * output, its exit code and its error output.
 *
 * @param  list<string>               $command Program and arguments.
 * @param  array<string, string>|null $env     Environment, inherited if null.
 * @return array{0: string, 1: int, 2: string}
 */
function run_command(array $command, ?array $env = null): array
{
    $pipes = [];
    $proc  = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) {
        return ['', -1, ''];
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [$stdout, proc_close($proc), $stderr];
}

/**
 * Looks for a program in the PATH, as command -v does.
 *
 * @param  string      $name Program name.
 * @return string|null Absolute path of the program, or null if not found.
 */
function find_executable(string $name): ?string
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if ($dir !== '' && is_file("$dir/$name") && is_executable("$dir/$name")) {
            return "$dir/$name";
        }
    }

    return null;
}

// First stage, on the command line: each file is included in a fresh
// process, without the constant, then a marker is written. The lock must
// cut execution before the marker, with a non-zero code (a file that
// only defines functions returns 0 as well).
foreach (['chessboard.php', 'output-html.php', 'output-pdf.php'] as $include) {
    [$stdout, $code] = run_command([
        PHP_BINARY,
        '-r',
        'require ' . var_export(__DIR__ . '/' . $include, true) . '; echo "marker";',
    ]);
    check("include without the constant blocked: $include", [$stdout, $code !== 0], ['', true]);
}

/**
 * Sends a minimal HTTP/1.0 request and returns the status code, the body
 * and the headers of the response, or [0, '', ''] if the server does not
 * answer. A raw
 * socket is enough, and depends neither on the curl extension nor on
 * allow_url_fopen.
 *
 * @return array{0: int, 1: string, 2: string}
 */
function http_response(int $port, string $method, string $path, string $body = ''): array
{
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
    if ($socket === false) {
        return [0, '', ''];
    }

    $request = "$method $path HTTP/1.0\r\nHost: 127.0.0.1:$port\r\n";
    if ($body !== '') {
        $request .= "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n";
    }
    fwrite($socket, $request . "\r\n" . $body);

    $raw = (string) stream_get_contents($socket);
    fclose($socket);

    $code = preg_match('#\AHTTP/\d\.\d (\d{3})#', $raw, $m) === 1 ? (int) $m[1] : 0;
    $end  = strpos($raw, "\r\n\r\n");

    return [
        $code,
        $end === false ? '' : substr($raw, $end + 4),
        $end === false ? $raw : substr($raw, 0, $end),
    ];
}

/** Like http_response(), but only returns the status code. */
function http_status(int $port, string $method, string $path, string $body = ''): int
{
    return http_response($port, $method, $path, $body)[0];
}

/**
 * Sends a minimal HTTP/1.0 request to a full URL, without following any
 * redirection, and returns the status code, the body and the headers of
 * the response, or [0, '', ''] if the server does not answer.
 *
 * @param  string $url    http:// or https:// URL.
 * @param  string $method HTTP method.
 * @param  string $body   Form-encoded body, for a POST.
 * @return array{0: int, 1: string, 2: string}
 */
function url_response(string $url, string $method = 'GET', string $body = ''): array
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['host'])) {
        return [0, '', ''];
    }

    $https = ($parts['scheme'] ?? 'http') === 'https';
    $port  = $parts['port'] ?? ($https ? 443 : 80);
    $path  = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $host  = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

    $errno  = 0;
    $errstr = '';
    $socket = @fsockopen(($https ? 'ssl://' : '') . $parts['host'], $port, $errno, $errstr, 5.0);
    if ($socket === false) {
        return [0, '', ''];
    }
    stream_set_timeout($socket, 30);

    $request = "$method $path HTTP/1.0\r\nHost: $host\r\nConnection: close\r\n";
    if ($method === 'POST') {
        $request .= "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n";
    }
    fwrite($socket, $request . "\r\n" . $body);
    $raw = (string) stream_get_contents($socket);
    fclose($socket);

    $code = preg_match('#\AHTTP/\d\.\d (\d{3})#', $raw, $m) === 1 ? (int) $m[1] : 0;
    $end  = strpos($raw, "\r\n\r\n");

    return [
        $code,
        $end === false ? '' : substr($raw, $end + 4),
        $end === false ? $raw : substr($raw, 0, $end),
    ];
}

// Second stage, over real HTTP: PHP's built-in server (php -S) reads no
// .htaccess, so it behaves like Apache with AllowOverride None or like
// nginx. It is part of the php-cli binary: no dependency.
//
// The PHP_SAPI condition prevents a recursion should the test.php lock
// ever disappear: the copy served over HTTP would otherwise start its own
// server.
// @phpstan-ignore identical.alwaysTrue (deliberate guard, see above)
if (PHP_SAPI === 'cli') {
    // A free port, chosen by the system.
    $reserved = stream_socket_server('tcp://127.0.0.1:0');
    $port     = 0;
    if ($reserved !== false) {
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($reserved, false), ':'), 1);
        fclose($reserved);
    }

    $pipes  = [];
    $server = proc_open(
        // PHP warnings are displayed in the response: an "Array to string
        // conversion" thus becomes visible there.
        [
            PHP_BINARY,
            '-d', 'display_errors=1',
            '-d', 'html_errors=0',
            '-d', 'error_reporting=-1',
            '-S', "127.0.0.1:$port",
            '-t', dirname(__DIR__),
        ],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );

    // The server takes a few milliseconds to start listening.
    $ready = false;
    for ($attempt = 0; $port > 0 && $attempt < 40 && !$ready; $attempt++) {
        usleep(50000);
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($probe !== false) {
            fclose($probe);
            $ready = true;
        }
    }
    check('test server started', $ready, true);

    if ($ready) {
        check('HTTP lib/chessboard.php refused', http_status($port, 'GET', '/lib/chessboard.php'), 403);
        check('HTTP lib/output-html.php refused', http_status($port, 'GET', '/lib/output-html.php'), 403);
        check('HTTP lib/output-pdf.php refused', http_status($port, 'GET', '/lib/output-pdf.php'), 403);
        check('HTTP lib/test.php refused', http_status($port, 'GET', '/lib/test.php'), 403);

        // The two entry points, for their part, must still be served.
        check('HTTP index.php served', http_status($port, 'GET', '/index.php'), 200);
        check(
            'HTTP generate.php served (html)',
            http_status($port, 'POST', '/generate.php', 'fen=8%2F8%2F8%2F4k3%2F8%2F8%2F8%2F4K3&format=html'),
            200
        );
        // Parameters received as arrays: "fen[]=x" turns $_POST['fen']
        // into an array, and a (string) cast would make it the string
        // "Array" along with a warning. Processing must go on without the
        // slightest message, with the expected status code.
        $emptyBoard = '8%2F8%2F8%2F8%2F8%2F8%2F8%2F8';
        $arrays = [
            'fen as an array, generate.php'    => ['POST', '/generate.php', "fen%5B%5D=$emptyBoard&format=html", 400],
            'notes as an array, generate.php'  => [
                'POST', '/generate.php', "fen=$emptyBoard&notes%5B%5D=x&format=html", 200,
            ],
            'turn as an array, generate.php'   => [
                'POST', '/generate.php', "fen=$emptyBoard&turn%5B%5D=b&format=html", 200,
            ],
            'format as an array, generate.php' => ['POST', '/generate.php', "fen=$emptyBoard&format%5B%5D=html", 400],
            'fen as an array, index.php'       => ['GET', '/index.php?fen%5B%5D=x', '', 200],
            'notes as an array, index.php'     => ['GET', '/index.php?notes%5B%5D=x', '', 200],
            'turn as an array, index.php'      => ['GET', '/index.php?turn%5B%5D=b', '', 200],
        ];
        foreach ($arrays as $name => [$method, $path, $body, $expectedCode]) {
            [$code, $page] = http_response($port, $method, $path, $body);
            check(
                "$name: status $expectedCode, no warning",
                [$code, str_contains($page, 'Array to string conversion')],
                [$expectedCode, false]
            );
        }

        // Content security policy (CSP). It is set by the web server (see
        // README) and forbids any inline script: the input page must
        // therefore contain none, neither a <script> block without src nor
        // an event handler attribute (onclick...). The values passed from
        // PHP to JavaScript travel in data-* attributes of the form.
        [$code, $page] = http_response($port, 'GET', '/index.php?fen=8%2F8%2F8%2F4k3%2F8%2F8%2F8%2F4K3');
        preg_match_all('#<script\b([^>]*)>#i', $page, $tags);
        $inline = array_values(array_filter($tags[1], static fn (string $a): bool => !str_contains($a, 'src=')));
        check('CSP: no inline script in index.php', [$code, $inline], [200, []]);
        check('CSP: no inline handler (on...=)', preg_match('#<[^>]+\son[a-z]+\s*=#i', $page), 0);
        check(
            'CSP: initial position in a data-* attribute',
            str_contains($page, 'data-initial-fen="8/8/8/4k3/8/8/8/4K3"'),
            true
        );
        check(
            'CSP: annotation limit in a data-* attribute',
            str_contains($page, 'data-notes-max="' . CHB_NOTES_MAX . '"'),
            true
        );

        // The generated HTML page contains no script, and will never need
        // one: its policy can therefore forbid them all, including in a
        // piece SVG that might have been altered.
        $start = 'rnbqkbnr%2Fpppppppp%2F8%2F8%2F8%2F8%2FPPPPPPPP%2FRNBQKBNR';
        [$code, $page] = http_response($port, 'POST', '/generate.php', "fen=$start&format=html");
        check('CSP: no script in the generated page', [$code, preg_match('#<script\b#i', $page)], [200, 0]);

        // The annotation is not read from the URL: it would end up in the
        // server logs, the browser history and those of any proxy. The FEN
        // and the side to move are enough to reopen a position.
        $kingsUrl = '8%2F8%2F8%2F4k3%2F8%2F8%2F8%2F4K3';
        [$code, $page] = http_response($port, 'GET', "/index.php?fen=$kingsUrl&turn=b&notes=UrlNotesMarker");
        check('URL: notes ignored by index.php', [$code, str_contains($page, 'UrlNotesMarker')], [200, false]);
        check('URL: fen still read by index.php', str_contains($page, 'data-initial-fen="8/8/8/4k3/8/8/8/4K3"'), true);

        // Side to move read from a full FEN in the URL, unless turn says
        // otherwise; a FEN rejected as a whole gives no side to move.
        $fenBlack = "$kingsUrl+b+-+-+0+1";
        [, $page] = http_response($port, 'GET', "/index.php?fen=$fenBlack");
        check('URL: side to move read from the FEN', str_contains($page, 'value="b" checked'), true);
        [, $page] = http_response($port, 'GET', "/index.php?fen=$fenBlack&turn=w");
        check('URL: explicit turn wins', str_contains($page, 'value="w" checked'), true);
        [, $page] = http_response($port, 'GET', '/index.php?fen=9%2F8%2F8%2F8%2F8%2F8%2F8%2F8+b');
        check(
            'URL: invalid FEN, no side to move taken from it',
            [str_contains($page, 'value="w" checked'), str_contains($page, 'was ignored')],
            [true, true]
        );

        // Same rule on the generator: the form always sends turn, the FEN
        // only serves when it is missing.
        [, $page] = http_response($port, 'POST', '/generate.php', "fen=$fenBlack&format=html");
        check('POST: side to move read from the FEN', str_contains($page, 'Black to move'), true);
        [, $page] = http_response($port, 'POST', '/generate.php', "fen=$fenBlack&turn=w&format=html");
        check('POST: explicit turn wins', str_contains($page, 'White to move'), true);

        // Any method other than POST: 405, with the Allow header.
        [$code, , $headers] = http_response($port, 'GET', '/generate.php');
        check(
            'GET generate.php: 405 and Allow: POST',
            [$code, preg_match('#^Allow: POST\r?$#mi', $headers)],
            [405, 1]
        );

        // The address ends with the name of the file (see app.js): only
        // diagram.pdf and diagram.html may follow generate.php.
        [$code, $page] = http_response($port, 'POST', '/generate.php/diagram.html', "fen=$fenBlack&format=html");
        check('generate.php/diagram.html served', [$code, str_contains($page, 'Black to move')], [200, true]);

        // The orientation follows the input board, and Black to move; the
        // first file label tells which side is at the bottom.
        $firstFile = static function (string $page): string {
            return preg_match('#<td class="file-label">([a-h])</td>#', $page, $m) === 1 ? $m[1] : '?';
        };
        $orientations = [];
        $queries = ['orientation=black&turn=w', 'turn=b', 'orientation=white&turn=w', 'orientation=BLACK&turn=w'];
        foreach ($queries as $query) {
            [, $page] = http_response($port, 'POST', '/generate.php/diagram.html', "fen=$kingsUrl&$query&format=html");
            $orientations[] = $firstFile($page);
        }
        check('HTTP orientation: flipped, Black to move, White, hostile value', $orientations, ['h', 'h', 'a', 'a']);
        check(
            'anything else after generate.php: 404',
            [
                http_status($port, 'POST', '/generate.php/other.txt', "fen=$fenBlack&format=html"),
                http_status($port, 'POST', '/generate.php/diagram.pdf/x', "fen=$fenBlack&format=html"),
            ],
            [404, 404]
        );

        // Size guard on the annotation: explicit refusal with a 400,
        // rather than an annotation that would vanish without explanation.
        $base = 'fen=8%2F8%2F8%2F8%2F8%2F8%2F8%2F8&format=html&notes=';
        check(
            'HTTP annotation of 776 bytes accepted',
            http_status($port, 'POST', '/generate.php', $base . str_repeat('a', 776)),
            200
        );
        check(
            'HTTP annotation of 777 bytes refused',
            http_status($port, 'POST', '/generate.php', $base . str_repeat('a', 777)),
            400
        );

        if ($tcpdfUsable) {
            check(
                'HTTP generate.php served (pdf)',
                http_status($port, 'POST', '/generate.php', 'fen=8%2F8%2F8%2F4k3%2F8%2F8%2F8%2F4K3&format=pdf'),
                200
            );
            check(
                'HTTP generate.php/diagram.pdf served (pdf)',
                http_status($port, 'POST', '/generate.php/diagram.pdf', "fen=$kingsUrl&format=pdf"),
                200
            );
        }
    }

    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
}

// =======================================================================
section('Web server configuration');
// =======================================================================

// Everything above runs on PHP's built-in server, which applies no server
// configuration. Given the URL of the input page on the real server, as in
//     php lib/test.php http://localhost/staunton/
// this section checks what docs/hardening.md asks of that configuration.
// A path that does not exist tells the server's own refusal from the PHP
// lock, which only protects the files that do exist.
$serverUrl = $argv[1] ?? '';
if ($serverUrl === '') {
    printf("  (skipped) to check the web server too: php lib/test.php http://localhost/staunton/\n");
} elseif (preg_match('#\Ahttps?://[^/\s?\#]+(?:/\S*)?\z#', $serverUrl) !== 1) {
    check('URL of the page, http://host/path/', $serverUrl, 'http://...');
} else {
    $baseUrl = rtrim($serverUrl, '/') . '/';
    $probes  = [
        'lib/ refused by the server itself' => ['lib/staunton-probe', [403]],
        'lib/test.php refused'              => ['lib/test.php', [403]],
        'no directory listing'              => ['assets/pieces/', [403]],
        '.git refused, or absent'           => ['.git/config', [403, 404]],
        'vendor/ refused, or absent'        => ['vendor/staunton-probe', [403, 404]],
        'fetch-assets.sh refused, or absent' => ['fetch-assets.sh', [403, 404]],
        'nothing else after generate.php'    => ['generate.php/staunton-probe', [404]],
    ];
    foreach ($probes as $label => [$path, $allowed]) {
        [$code] = url_response($baseUrl . $path);
        check("server: $label", $code, in_array($code, $allowed, true) ? $code : $allowed[0]);
    }

    [$code, , $headers] = url_response($baseUrl . 'index.php');
    check('server: input page served', $code, 200);
    foreach (['X-Content-Type-Options: nosniff', 'X-Frame-Options: DENY', 'Referrer-Policy: no-referrer'] as $header) {
        check("server header: $header", preg_match('#^' . preg_quote($header, '#') . '\r?$#mi', $headers), 1);
    }
    check(
        'server: content security policy of the input page',
        preg_match("#^Content-Security-Policy: default-src 'none';.*style-src-attr 'unsafe-inline'#mi", $headers),
        1
    );

    // Server identity: no version in the Server header, none on the error
    // pages either (ServerTokens Prod and ServerSignature Off under Apache,
    // server_tokens off under nginx).
    check('server: Server header without version', preg_match('#^Server:[^\r\n]*\d#mi', $headers), 0);
    [, $errorPage] = url_response($baseUrl . 'lib/staunton-probe');
    check('server: error pages without version', preg_match('#(?:Apache|nginx)/\d#i', $errorPage), 0);

    // The generated page, at both of its addresses: the one the form uses
    // (the name of the file at the end, see app.js) and the bare one.
    foreach (['generate.php/diagram.html', 'generate.php'] as $generator) {
        [$code, , $headers] = url_response(
            $baseUrl . $generator,
            'POST',
            'fen=8%2F8%2F8%2F8%2F8%2F8%2F8%2F8&format=html'
        );
        $generatedCsp = "#^Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'#mi";
        check(
            "server: content security policy of the generated page ($generator)",
            [$code, preg_match($generatedCsp, $headers)],
            [200, 1]
        );
    }
}

// =======================================================================
[$total, $failures] = tally();
printf("\n%d checks, %d failure(s).\n", $total, $failures);

exit($failures === 0 ? 0 : 1);
