/*
 * app.js - drives the input board.
 *
 * Four responsibilities:
 *
 *  - set up the board and its palette, provided natively by chessboard.js;
 *  - remove a piece with a single click;
 *  - lay the large coordinates outside the board;
 *  - load a position pasted as FEN or a PGN game, and navigate through
 *    the latter.
 */

/* global $, Chessboard */

(function () {
    'use strict';

    var board = null;

    // Values passed by index.php in the data-* attributes of the form, and
    // not in an inline <script> block, which the content security policy
    // forbids. The initial position comes from the URL and has already
    // been validated server side; an empty string means there is none.
    var form        = document.getElementById('form');
    var INITIAL_FEN = form ? (form.getAttribute('data-initial-fen') || '') : '';
    var NOTES_MAX   = form ? (parseInt(form.getAttribute('data-notes-max'), 10) || 0) : 0;

    var FILES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];

    // PGN game being browsed: the full FEN of every half-move, starting
    // position included, and the moves played. Empty as long as no PGN
    // has been loaded.
    var game = { fens: [], moves: [], index: 0 };

    // -------------------------------------------------------------------
    // Board
    // -------------------------------------------------------------------

    /**
     * Removes a piece dropped back on its own square.
     *
     * chessboard.js exposes no click event, but mousedownSquare starts the
     * drag with no movement threshold. A single click therefore produces a
     * drop where the source and target squares are the same. Returning the
     * string 'trash', which the library reads as a removal order, is then
     * enough.
     *
     * The check on 'spare' protects the palette pieces.
     *
     * @param {string} source Source square, or 'spare'.
     * @param {string} target Target square, or 'offboard'.
     * @returns {string|undefined} 'trash' to remove, nothing otherwise.
     */
    function onDrop(source, target) {
        if (source !== 'spare' && source === target) {
            return 'trash';
        }

        return undefined;
    }

    /**
     * Copies the current position into the hidden field of the form.
     *
     * @param {object} oldPos Position before the change, unused.
     * @param {object} newPos Position after the change.
     */
    function onChange(oldPos, newPos) {
        $('#fen').val(Chessboard.objToFen(newPos));
    }

    // -------------------------------------------------------------------
    // Outer coordinates
    // -------------------------------------------------------------------

    /**
     * Lays the large coordinates along the board.
     *
     * The small coordinates drawn by chessboard.js are inside the squares.
     * These ones are outside, as on the printed diagram.
     *
     * The placement is measured from the actual geometry of the board
     * element: chessboard.js inserts the top palette before the board, and
     * the board itself has a 2 pixel border in content-box sizing, both of
     * which move it by an amount that depends on the square size.
     */
    function placeCoords() {
        var inner = document.querySelector('#board [class^="board-"]');
        if (!inner) {
            return;
        }

        var ranks = document.getElementById('ranks');
        var files = document.getElementById('files');

        // getBoundingClientRect rather than offsetTop: the size of a
        // square is almost never a whole number of pixels, and the offset*
        // properties round to whole pixels. The position relative to the
        // frame is therefore computed from the exact rectangles.
        var frame = document.querySelector('.board-frame');
        var rf = frame.getBoundingClientRect();
        var rb = inner.getBoundingClientRect();

        var top    = rb.top - rf.top;
        var left   = rb.left - rf.left;
        var height = rb.height;
        var width  = rb.width;

        ranks.style.top    = top + 'px';
        ranks.style.height = height + 'px';

        files.style.top   = (top + height) + 'px';
        files.style.left  = left + 'px';
        files.style.width = width + 'px';

        var flipped = (board.orientation() === 'black');

        var numbers = [];
        for (var i = 0; i < 8; i++) {
            numbers.push(flipped ? (i + 1) : (8 - i));
        }

        var letters = flipped ? FILES.slice().reverse() : FILES;

        ranks.innerHTML = numbers.map(toCell).join('');
        files.innerHTML = letters.map(toCell).join('');
    }

    /**
     * @param {string|number} text Label of a coordinate.
     * @returns {string} The matching cell.
     */
    function toCell(text) {
        return '<span>' + text + '</span>';
    }

    // -------------------------------------------------------------------
    // Loading a pasted FEN
    // -------------------------------------------------------------------

    /**
     * Shows a message below the paste field.
     *
     * @param {string} text      Message, empty to clear.
     * @param {string} className 'success' or 'error'.
     */
    function fenMessage(text, className) {
        $('#fen-message').text(text).attr('class', 'message ' + (className || ''));
    }

    /**
     * Loads the position pasted in the field.
     *
     * Chessboard.fenToObj() drops everything after the first space, so a
     * full FEN copied from Lichess goes through as is. The second field is
     * read along the way to set the side to move automatically.
     *
     * @returns {boolean} true if the position was loaded.
     */
    function loadFen() {
        var input = String($('#fen-paste').val() || '').trim();

        if (input === '') {
            fenMessage('Paste a FEN into the field first.', 'error');
            return false;
        }

        var position = Chessboard.fenToObj(input);

        if (position === false) {
            // The board is left untouched: your work in progress is kept,
            // you can fix the string and try again.
            fenMessage('Invalid FEN, the board was not changed.', 'error');
            return false;
        }

        board.position(position, false);

        // Second field of a full FEN: the side to move.
        var fields = input.split(/\s+/);
        var turnRead = '';

        if (fields.length > 1 && (fields[1] === 'w' || fields[1] === 'b')) {
            $('input[name="turn"][value="' + fields[1] + '"]').prop('checked', true);
            turnRead = fields[1] === 'w' ? ', White to move' : ', Black to move';
        }

        var pieces = Object.keys(position).length;
        fenMessage('Position loaded: ' + pieces + ' piece' + (pieces !== 1 ? 's' : '') + turnRead + '.', 'success');
        return true;
    }

    // -------------------------------------------------------------------
    // Loading a pasted PGN game
    // -------------------------------------------------------------------

    /**
     * Routes the input to FEN or PGN loading.
     *
     * A FEN never contains a bracket or a dot, whereas a PGN always has
     * one or the other: an [Event "..."] header or a move number "1.". The
     * test is therefore unambiguous, and a wrong FEN is still reported as
     * such instead of passing for an invalid PGN.
     */
    function load() {
        var input = String($('#fen-paste').val() || '').trim();

        var loaded;

        if (/[[.]/.test(input)) {
            loaded = loadPgn(input);
        } else {
            hideNavigation();
            loaded = loadFen();
        }

        // Once the position is loaded, the paste area goes back to its
        // initial state: empty, placeholder visible, original height if
        // you had enlarged it. On error, on the other hand, the text is
        // kept so that you can fix it. The confirmation message and the
        // PGN navigation stay on screen.
        if (loaded) {
            $('#fen-paste').val('').css('height', '');
        }
    }

    /**
     * Isolates the first game of a file that holds several.
     *
     * chess.js rejects a second game. The text is therefore cut at the
     * first header that follows the movetext. The pattern requires the
     * syntax of a header, [Name "value"], at the start of a line: Lichess
     * annotations such as { [%clk 0:03:00] } are not mistaken for one.
     *
     * @param {string} text Full PGN.
     * @returns {{pgn: string, cut: boolean}} The first game, and whether
     *          there were others after it.
     */
    function firstGame(text) {
        var headers = /^(?:\s*\[[^\]]*\])*/.exec(text)[0];
        var rest = text.slice(headers.length);
        var cutAt = rest.search(/\n\s*\[[A-Za-z0-9_]+\s+"/);

        if (cutAt === -1) {
            return { pgn: text, cut: false };
        }

        return { pgn: headers + rest.slice(0, cutAt), cut: true };
    }

    /**
     * Loads a PGN game and shows its final position.
     *
     * The legality of the moves is checked by chess.js. A [FEN] header is
     * taken into account, which covers games and studies that do not
     * start from the initial position. As with FEN, a rejected PGN leaves
     * the board intact.
     *
     * @param {string} input Pasted text, already stripped of surrounding
     *                       whitespace.
     * @returns {boolean} true if the game was loaded.
     */
    function loadPgn(input) {
        if (typeof window.Chess === 'undefined') {
            fenMessage('chess.js did not load, PGN cannot be used. Check the assets/ directory.', 'error');
            return false;
        }

        var extract = firstGame(input);
        var chess = new window.Chess();

        try {
            chess.loadPgn(extract.pgn);
        } catch (e) {
            // chess.js gives a precise message, for instance
            // "Invalid move in PGN: Ke3". It is passed on as is.
            fenMessage('Invalid PGN, the board was not changed (' + e.message + ').', 'error');
            return false;
        }

        var moves = chess.history({ verbose: true });

        // Every move carries the FEN before and after it. The starting
        // position is the one before the first move, or the loaded
        // position itself if the PGN holds no move.
        game.moves = moves;
        game.fens = [moves.length ? moves[0].before : chess.fen()];
        moves.forEach(function (move) {
            game.fens.push(move.after);
        });

        goTo(moves.length);
        $('#pgn-nav').prop('hidden', false);

        var headers = chess.getHeaders();
        var players = '';

        if (headers.White && headers.White !== '?' && headers.Black && headers.Black !== '?') {
            players = ' ' + headers.White + ' - ' + headers.Black + ',';
        }

        var n = moves.length;
        fenMessage('Game loaded:' + players + ' ' + n + ' half-move' + (n !== 1 ? 's' : '') +
                   ', final position shown' +
                   (extract.cut ? ' (only the first game was read)' : '') + '.', 'success');
        return true;
    }

    /**
     * Sets the board on a half-move of the loaded game.
     *
     * The side to move is read from the second field of the matching FEN,
     * so it follows the navigation.
     *
     * @param {number} index 0 for the starting position, n for the
     *                       position after the n-th half-move.
     */
    function goTo(index) {
        var total = game.fens.length - 1;

        index = Math.max(0, Math.min(total, index));
        game.index = index;

        var fen = game.fens[index];
        board.position(fen.split(' ')[0], false);

        var turn = fen.split(' ')[1];
        $('input[name="turn"][value="' + turn + '"]').prop('checked', true);

        // Label of the move that leads to the position shown, in the usual
        // notation: "12. Nf3" for White, "12... Nf6" for Black. The number
        // comes from the sixth field of the FEN before the move.
        var label = 'Start';

        if (index > 0) {
            var move = game.moves[index - 1];
            var number = move.before.split(' ')[5];
            label = number + (move.color === 'w' ? '. ' : '... ') + move.san;
        }

        $('#pgn-move').text(label + '  (' + index + '/' + total + ')');

        $('#pgn-nav [data-step="first"], #pgn-nav [data-step="prev"]').prop('disabled', index === 0);
        $('#pgn-nav [data-step="next"], #pgn-nav [data-step="last"]').prop('disabled', index === total);
    }

    /**
     * Hides the navigation and forgets the loaded game.
     *
     * Called as soon as another source replaces the position: a FEN, the
     * empty board or the starting position.
     */
    function hideNavigation() {
        game = { fens: [], moves: [], index: 0 };
        $('#pgn-nav').prop('hidden', true);
    }

    // -------------------------------------------------------------------
    // Character counter
    // -------------------------------------------------------------------

    /**
     * Updates the annotation counter.
     *
     * The browser counts UTF-16 units and the server counts code points.
     * For Latin text they are the same; only emoji would make them differ,
     * with no consequence since the browser would let fewer through than
     * the server allows.
     */
    function updateCounter() {
        var n = $('#notes').val().length;
        $('#counter').text(n);
        $('.counter').toggleClass('counter-full', n >= NOTES_MAX);
    }

    // -------------------------------------------------------------------
    // Start-up
    // -------------------------------------------------------------------

    $(function () {
        // The help card starts closed (index.php). On a wide screen it sits
        // to the right of the form and costs no height: it is opened. On a
        // narrow screen it moves above the board and stays closed, so that
        // it only takes one line (see the board width in style.css). The
        // threshold is the one of the media query in style.css.
        var help = document.getElementById('help');
        if (help && window.matchMedia && !window.matchMedia('(max-width: 1200px)').matches) {
            help.open = true;
        }

        if (typeof Chessboard === 'undefined') {
            $('#board').text('chessboard.js did not load. Check the assets/ directory.');
            return;
        }

        var config = {
            // Without an explicit position, chessboard.js starts on an
            // empty board, which is the intended starting point.
            draggable: true,
            sparePieces: true,      // forces draggable to true anyway
            dropOffBoard: 'trash',  // dragging off the board removes
            pieceTheme: 'assets/pieces/{piece}.svg',
            onDrop: onDrop,
            onChange: onChange
        };

        if (INITIAL_FEN) {
            config.position = INITIAL_FEN;
        }

        board = Chessboard('board', config);

        // onChange only fires on a change. Without this call, the hidden
        // field would stay empty as long as you had not touched the board,
        // and generating a blank template, the original use case, would
        // return a 400 error.
        $('#fen').val(board.fen());

        placeCoords();

        $(window).on('resize', function () {
            board.resize();
            placeCoords();
        });

        $('#clear').on('click', function () {
            // false switches the animation off: on a loaded board it is
            // long and brings nothing.
            board.clear(false);
            hideNavigation();
            fenMessage('', '');
        });

        $('#flip').on('click', function () {
            board.flip();
            placeCoords();
        });

        $('#start').on('click', function () {
            board.start(false);
            $('input[name="turn"][value="w"]').prop('checked', true);
            hideNavigation();
            fenMessage('', '');
        });

        $('#load').on('click', load);

        $('#pgn-nav').on('click', 'button', function () {
            var targets = {
                first: 0,
                prev: game.index - 1,
                next: game.index + 1,
                last: game.fens.length - 1
            };

            goTo(targets[$(this).data('step')]);
        });

        // The Enter key in the paste area acts as a click on Load, without
        // submitting the form. Shift+Enter inserts a line break, for anyone
        // who wants to touch up a PGN by hand; a paste triggers no keyboard
        // event and keeps its line breaks.
        $('#fen-paste').on('keydown', function (e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                load();
            }
        });

        $('#notes').on('input', updateCounter);
        updateCounter();

        // The address of the generator ends with the name of the file,
        // diagram.pdf or diagram.html: it is the name browsers propose when
        // the document is saved. Chromium's PDF viewer falls back on the last
        // segment of the address when the name of the Content-Disposition
        // header does not reach it, and would otherwise propose generate.php.
        // generate.php only accepts these two names.
        //
        // The orientation of the board goes along: the diagram follows a
        // flip, and also turns to Black's side when Black is to move (see
        // chb_black_at_bottom()).
        $('#form').on('submit', function () {
            var format = $('input[name="format"]:checked').val() === 'html' ? 'html' : 'pdf';
            this.setAttribute('action', 'generate.php/diagram.' + format);
            $('#orientation').val(board.orientation() === 'black' ? 'black' : 'white');
        });
    });
}());
