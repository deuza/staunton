/*
 * chess-module.js - exposes chess.js to app.js.
 *
 * chess.js is only distributed as an ES module or as CommonJS. It is
 * therefore exposed as a global variable for app.js, which only needs it
 * when a PGN is loaded, so well after this module has run.
 *
 * It is a file of its own because the content security policy forbids
 * inline scripts. If you change the chess.js version in fetch-assets.sh,
 * carry it over to the import below.
 */

import { Chess } from './assets/chess-1.4.0.js';

window.Chess = Chess;
