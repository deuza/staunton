<?php

/**
 * index.php - position input.
 *
 * The page accepts two optional GET parameters:
 *
 *   fen   starting position, bare placement or full FEN
 *         (PGN, too long for a URL, can only be loaded by pasting)
 *   turn  w or b; when it is missing or invalid, the side to move is
 *         read from the second field of a full FEN
 *
 * The annotation is not read from the URL: it would end up in the server
 * logs, the browser history and those of any proxy. The FEN and the side
 * to move are enough to reopen a position, and the annotation is already
 * on the printed sheet.
 *
 * These parameters give its meaning to the FEN printed at the bottom of
 * every diagram: you can get back to a position from a paper sheet by
 * copying the string into the URL, side to move included.
 *
 * Everything is validated here, server side, before being written back
 * into the page.
 */

declare(strict_types=1);

// Only index.php and generate.php are entry points: the files in lib/
// require this constant and refuse to run without it.
define('CHB_ENTRY', true);

require_once __DIR__ . '/lib/chessboard.php';

$rawFen    = trim(chb_param($_GET, 'fen'));
$placement = chb_validate_placement($rawFen);

// "No FEN given" is told apart from "FEN given but invalid". In the second
// case we say so, rather than show an empty board without explanation and
// leave you wondering.
$fenIgnored = ($rawFen !== '' && $placement === null);

if ($placement === null) {
    $placement = '';
}

// Side to move: the turn parameter if it is valid, otherwise the second
// field of a full FEN, otherwise White (see chb_resolve_turn()).
$turn = chb_resolve_turn(chb_param($_GET, 'turn'), $rawFen);

/** Escaping shortcut for the HTML context. */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Board editor</title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="stylesheet" href="assets/chessboard-1.0.0.min.css">
<link rel="stylesheet" href="style.css">
</head>
<body>

<header class="page-header">
    <h1>Board editor</h1>
    <p>Set up a position, export it as a PDF or an HTML page.</p>
</header>

<?php if ($fenIgnored): ?>
<div class="alert alert-warning">
    The FEN given in the URL was ignored: it is not valid.
    The board starts empty.
</div>
<?php endif; ?>

<div class="container">

    <!-- Left column: the board and its controls -->
    <div class="card card-board">

        <div class="board-frame">
            <div class="coords coords-ranks" id="ranks"></div>
            <div id="board"></div>
            <div class="coords coords-files" id="files"></div>
        </div>

        <div class="toolbar">
            <button type="button" id="clear" class="btn btn-danger">Clear board</button>
            <button type="button" id="flip" class="btn btn-neutral">Flip</button>
            <button type="button" id="start" class="btn btn-neutral">Starting position</button>
        </div>

    </div>

    <!--
        Right column: the form.

        It opens in a new tab, so the input page stays intact. You can
        produce a PDF and then an HTML page without having to set the
        position up again.
    -->
    <!--
        data-initial-fen: position passed in the URL, already validated
        server side; data-notes-max: annotation limit, for the counter.
    -->
    <form id="form" class="card panel" action="generate.php" method="post" target="_blank"
          data-initial-fen="<?= h($placement) ?>"
          data-notes-max="<?= CHB_NOTES_MAX ?>">

        <input type="hidden" name="fen" id="fen" value="<?= h($placement) ?>">
        <input type="hidden" name="orientation" id="orientation" value="white">

        <section class="block">
            <h2>Load a position</h2>
            <!--
                A multiline area rather than a text field: an
                <input type="text"> field replaces line breaks with spaces
                on paste, which would mangle a PGN.
            -->
            <div class="row">
                <textarea id="fen-paste" rows="3" autocomplete="off" spellcheck="false"
                          placeholder="Paste a position (FEN) or a game (PGN)"></textarea>
                <button type="button" id="load" class="btn btn-info">Load</button>
            </div>
            <p class="message" id="fen-message" role="status"></p>

            <!-- Navigation through the game, only visible after a PGN. -->
            <div class="nav" id="pgn-nav" hidden>
                <button type="button" class="btn btn-neutral" data-step="first"
                        title="Starting position">&laquo;</button>
                <button type="button" class="btn btn-neutral" data-step="prev"
                        title="Previous move">&lsaquo;</button>
                <button type="button" class="btn btn-neutral" data-step="next"
                        title="Next move">&rsaquo;</button>
                <button type="button" class="btn btn-neutral" data-step="last"
                        title="Final position">&raquo;</button>
                <span class="move" id="pgn-move"></span>
            </div>
        </section>

        <section class="block">
            <h2>Annotation</h2>
            <textarea name="notes" id="notes" rows="3"
                      maxlength="<?= CHB_NOTES_MAX ?>"
                      placeholder="Position / notes"></textarea>
            <p class="counter"><span id="counter">0</span> / <?= CHB_NOTES_MAX ?></p>
        </section>

        <section class="block">
            <h2>To move</h2>
            <div class="choice">
                <label><input type="radio" name="turn" value="w"<?= $turn === 'w' ? ' checked' : '' ?>> White</label>
                <label><input type="radio" name="turn" value="b"<?= $turn === 'b' ? ' checked' : '' ?>> Black</label>
            </div>
        </section>

        <section class="block">
            <h2>Output</h2>
            <div class="choice">
                <label><input type="radio" name="format" value="pdf" checked> PDF</label>
                <label><input type="radio" name="format" value="html"> HTML</label>
            </div>
        </section>

        <button type="submit" class="btn btn-success btn-wide">Generate the diagram</button>

    </form>


    <!--
        How to use the board, in its own card to the right of the form. On
        a wide screen it lines up with the top of the other cards; on a
        narrow screen it moves above them, full width (see order in
        style.css).

        The card is collapsible (details element, no script needed). It
        starts closed, so that on a narrow screen it only takes one line
        above the board; app.js opens it at start-up when the window is
        wide enough for the card to sit to the right.
    -->
    <aside class="card card-help">
        <details id="help">
            <summary><h2>How to use</h2></summary>
            <p>
                Drag a piece from either palette onto a square.
                To remove a piece, click it without moving it,
                or drag it off the board.
            </p>
            <p>
                You can also paste a position (FEN) or a whole game
                (PGN) under Load a position; for a game, the arrows
                then step through the moves.
            </p>
        </details>
    </aside>

</div>

<script src="assets/jquery-3.7.1.min.js"></script>
<script src="assets/chessboard-1.0.0.min.js"></script>
<!--
    No inline script: the content security policy set by the server (see
    README) forbids them. chess-module.js exposes chess.js to app.js, and
    the values coming from PHP travel in the data-* attributes of the form.
-->
<script type="module" src="chess-module.js"></script>
<script src="app.js"></script>

</body>
</html>
