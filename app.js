/*
 * app.js - pilotage du plateau de saisie.
 *
 * Quatre responsabilites :
 *
 *  - monter le plateau et sa palette, fournis nativement par chessboard.js ;
 *  - supprimer une piece par simple clic ;
 *  - poser les grandes coordonnees a l'exterieur du plateau ;
 *  - charger une position collee au format FEN ou une partie PGN, et
 *    naviguer dans cette derniere.
 */

/* global $, Chessboard, ECH_FEN_INITIAL, ECH_NOTES_MAX */

(function () {
    'use strict';

    var board = null;

    var COLONNES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];

    // Partie PGN en cours de consultation : les FEN complets de chaque
    // demi-coup, position de depart comprise, et les coups joues. Vide
    // tant qu'aucun PGN n'a ete charge.
    var partie = { fens: [], coups: [], indice: 0 };

    // -------------------------------------------------------------------
    // Plateau
    // -------------------------------------------------------------------

    /**
     * Supprime une piece relachee sur sa propre case.
     *
     * chessboard.js n'expose aucun evenement de clic, mais mousedownSquare
     * demarre le glissement sans seuil de deplacement. Un clic sec produit
     * donc un depot ou la case de depart et la case d'arrivee sont
     * identiques. Il suffit alors de renvoyer la chaine 'trash', que la
     * bibliotheque interprete comme un ordre de suppression.
     *
     * Le test sur 'spare' protege les pieces de la palette.
     *
     * @param {string} source Case de depart, ou 'spare'.
     * @param {string} cible  Case d'arrivee, ou 'offboard'.
     * @returns {string|undefined} 'trash' pour supprimer, rien sinon.
     */
    function surDepot(source, cible) {
        if (source !== 'spare' && source === cible) {
            return 'trash';
        }

        return undefined;
    }

    /**
     * Repercute la position courante dans le champ cache du formulaire.
     *
     * @param {object} ancienne Position avant modification, inutilisee.
     * @param {object} nouvelle Position apres modification.
     */
    function surChangement(ancienne, nouvelle) {
        $('#fen').val(Chessboard.objToFen(nouvelle));
    }

    // -------------------------------------------------------------------
    // Coordonnees exterieures
    // -------------------------------------------------------------------

    /**
     * Pose les grandes coordonnees le long du plateau.
     *
     * Les petites coordonnees que dessine chessboard.js sont dans les
     * cases. Celles-ci sont a l'exterieur, comme sur le diagramme imprime.
     *
     * Le calage est mesure, pas devine : chessboard.js insere la palette
     * du haut avant le plateau, et le plateau porte lui-meme une bordure
     * de 2 pixels en box-sizing content-box. Deviner la position revenait
     * a se tromper de quelques pixels des que la taille de case changeait.
     * On lit donc la geometrie reelle de l'element du plateau.
     */
    function placerReperes() {
        var plateau = document.querySelector('#plateau [class^="board-"]');
        if (!plateau) {
            return;
        }

        var rangs    = document.getElementById('rangs');
        var colonnes = document.getElementById('colonnes');

        // getBoundingClientRect plutot que offsetTop : la taille d'une
        // case n'est presque jamais un nombre entier de pixels, et les
        // proprietes offset* arrondissent, ce qui decalait les reperes
        // d'un pixel. On calcule donc la position relative au cadre a
        // partir des rectangles exacts.
        var cadre = document.querySelector('.plateau-cadre');
        var rc = cadre.getBoundingClientRect();
        var rp = plateau.getBoundingClientRect();

        var haut    = rp.top - rc.top;
        var gauche  = rp.left - rc.left;
        var hauteur = rp.height;
        var largeur = rp.width;

        rangs.style.top    = haut + 'px';
        rangs.style.height = hauteur + 'px';

        colonnes.style.top   = (haut + hauteur) + 'px';
        colonnes.style.left  = gauche + 'px';
        colonnes.style.width = largeur + 'px';

        var renverse = (board.orientation() === 'black');

        var lignes = [];
        for (var i = 0; i < 8; i++) {
            lignes.push(renverse ? (i + 1) : (8 - i));
        }

        var lettres = renverse ? COLONNES.slice().reverse() : COLONNES;

        rangs.innerHTML    = lignes.map(enCase).join('');
        colonnes.innerHTML = lettres.map(enCase).join('');
    }

    /**
     * @param {string|number} texte Libelle d'une coordonnee.
     * @returns {string} La cellule correspondante.
     */
    function enCase(texte) {
        return '<span>' + texte + '</span>';
    }

    // -------------------------------------------------------------------
    // Chargement d'un FEN colle
    // -------------------------------------------------------------------

    /**
     * Affiche un message sous le champ de collage.
     *
     * @param {string} texte  Message, vide pour effacer.
     * @param {string} classe 'succes' ou 'erreur'.
     */
    function messageFen(texte, classe) {
        $('#fen-message').text(texte).attr('class', 'message ' + (classe || ''));
    }

    /**
     * Charge la position collee dans le champ.
     *
     * Chessboard.fenToObj() coupe tout ce qui suit la premiere espace, un
     * FEN complet copie depuis Lichess passe donc tel quel. On en profite
     * pour lire le deuxieme champ et caler le trait tout seul.
     *
     * @returns {boolean} true si la position a ete chargee.
     */
    function chargerFen() {
        var saisie = $.trim($('#fen-colle').val());

        if (saisie === '') {
            messageFen('Collez d\'abord un FEN dans le champ.', 'erreur');
            return false;
        }

        var position = Chessboard.fenToObj(saisie);

        if (position === false) {
            // Le plateau n'est pas touche : votre travail en cours est
            // preserve, vous pouvez corriger la chaine et recommencer.
            messageFen('FEN invalide, le plateau n\'a pas été modifié.', 'erreur');
            return false;
        }

        board.position(position, false);

        // Deuxieme champ d'un FEN complet : le trait.
        var champs = saisie.split(/\s+/);
        var traitLu = '';

        if (champs.length > 1 && (champs[1] === 'w' || champs[1] === 'b')) {
            $('input[name="trait"][value="' + champs[1] + '"]').prop('checked', true);
            traitLu = champs[1] === 'w' ? ', trait aux Blancs' : ', trait aux Noirs';
        }

        var pieces = Object.keys(position).length;
        messageFen('Position chargée : ' + pieces + ' pièce' + (pieces > 1 ? 's' : '') + traitLu + '.', 'succes');
        return true;
    }

    // -------------------------------------------------------------------
    // Chargement d'une partie PGN collee
    // -------------------------------------------------------------------

    /**
     * Aiguille la saisie vers le chargement FEN ou PGN.
     *
     * Un FEN ne contient jamais de crochet ni de point, alors qu'un PGN a
     * toujours l'un ou l'autre : un en-tete [Event "..."] ou un numero de
     * coup "1.". Le test est donc sans ambiguite, et un FEN errone reste
     * signale comme tel au lieu de passer pour un PGN invalide.
     */
    function charger() {
        var saisie = $.trim($('#fen-colle').val());

        var reussi;

        if (/[[.]/.test(saisie)) {
            reussi = chargerPgn(saisie);
        } else {
            masquerNavigation();
            reussi = chargerFen();
        }

        // Une fois la position chargee, la zone de collage revient a son
        // etat initial : vide, placeholder visible, hauteur d'origine si
        // vous l'aviez agrandie. En cas d'erreur, en revanche, le texte
        // est conserve pour que vous puissiez le corriger. Le message de
        // confirmation et la navigation PGN, eux, restent affiches.
        if (reussi) {
            $('#fen-colle').val('').css('height', '');
        }
    }

    /**
     * Isole la premiere partie d'un fichier qui en contient plusieurs.
     *
     * chess.js refuse une deuxieme partie. On coupe donc au premier
     * en-tete qui suit le texte des coups. Le motif exige la syntaxe d'un
     * en-tete, [Nom "valeur"], en debut de ligne : les annotations de
     * Lichess du type { [%clk 0:03:00] } ne s'y confondent pas.
     *
     * @param {string} texte PGN complet.
     * @returns {{pgn: string, coupe: boolean}} La premiere partie, et
     *          l'indication qu'il en restait d'autres.
     */
    function premierePartie(texte) {
        var entetes = /^(?:\s*\[[^\]]*\])*/.exec(texte)[0];
        var reste = texte.slice(entetes.length);
        var coupure = reste.search(/\n\s*\[[A-Za-z0-9_]+\s+"/);

        if (coupure === -1) {
            return { pgn: texte, coupe: false };
        }

        return { pgn: entetes + reste.slice(0, coupure), coupe: true };
    }

    /**
     * Charge une partie PGN et affiche sa position finale.
     *
     * La legalite des coups est verifiee par chess.js. Un en-tete [FEN]
     * est pris en compte, ce qui couvre les parties et etudes qui ne
     * partent pas de la position initiale. Comme pour le FEN, un PGN
     * refuse laisse le plateau intact.
     *
     * @param {string} saisie Texte colle, deja debarrasse de ses blancs
     *                        de bordure.
     * @returns {boolean} true si la partie a ete chargee.
     */
    function chargerPgn(saisie) {
        if (typeof window.Chess === 'undefined') {
            messageFen('chess.js ne s\'est pas chargé, le PGN est inutilisable. Vérifiez le répertoire assets/.', 'erreur');
            return false;
        }

        var extrait = premierePartie(saisie);
        var jeu = new window.Chess();

        try {
            jeu.loadPgn(extrait.pgn);
        } catch (e) {
            // Le message de chess.js est en anglais mais precis, par
            // exemple "Invalid move in PGN: Ke3". On le transmet tel quel.
            messageFen('PGN invalide, le plateau n\'a pas été modifié (' + e.message + ').', 'erreur');
            return false;
        }

        var coups = jeu.history({ verbose: true });

        // Chaque coup porte les FEN d'avant et d'apres. La position de
        // depart est celle d'avant le premier coup, ou la position chargee
        // elle-meme si le PGN ne contient aucun coup.
        partie.coups = coups;
        partie.fens = [coups.length ? coups[0].before : jeu.fen()];
        coups.forEach(function (coup) {
            partie.fens.push(coup.after);
        });

        allerA(coups.length);
        $('#pgn-nav').prop('hidden', false);

        var entetes = jeu.getHeaders();
        var joueurs = '';

        if (entetes.White && entetes.White !== '?' && entetes.Black && entetes.Black !== '?') {
            joueurs = ' ' + entetes.White + ' - ' + entetes.Black + ',';
        }

        var n = coups.length;
        messageFen('Partie chargée :' + joueurs + ' ' + n + ' demi-coup' + (n > 1 ? 's' : '') +
                   ', position finale affichée' +
                   (extrait.coupe ? ' (seule la première partie a été lue)' : '') + '.', 'succes');
        return true;
    }

    /**
     * Place le plateau sur un demi-coup de la partie chargee.
     *
     * Le trait est lu dans le deuxieme champ du FEN correspondant, il suit
     * donc la navigation.
     *
     * @param {number} indice 0 pour la position de depart, n pour la
     *                        position apres le n-ieme demi-coup.
     */
    function allerA(indice) {
        var total = partie.fens.length - 1;

        indice = Math.max(0, Math.min(total, indice));
        partie.indice = indice;

        var fen = partie.fens[indice];
        board.position(fen.split(' ')[0], false);

        var trait = fen.split(' ')[1];
        $('input[name="trait"][value="' + trait + '"]').prop('checked', true);

        // Libelle du coup qui mene a la position affichee, en notation
        // usuelle : "12. Cf3" pour les Blancs, "12... Cf6" pour les Noirs.
        // Le numero vient du sixieme champ du FEN d'avant le coup.
        var libelle = 'Départ';

        if (indice > 0) {
            var coup = partie.coups[indice - 1];
            var numero = coup.before.split(' ')[5];
            libelle = numero + (coup.color === 'w' ? '. ' : '... ') + coup.san;
        }

        $('#pgn-coup').text(libelle + '  (' + indice + '/' + total + ')');

        $('#pgn-nav [data-pas="debut"], #pgn-nav [data-pas="precedent"]').prop('disabled', indice === 0);
        $('#pgn-nav [data-pas="suivant"], #pgn-nav [data-pas="fin"]').prop('disabled', indice === total);
    }

    /**
     * Masque la navigation et oublie la partie chargee.
     *
     * Appelee des qu'une autre source remplace la position : un FEN, le
     * plateau vide ou la position initiale.
     */
    function masquerNavigation() {
        partie = { fens: [], coups: [], indice: 0 };
        $('#pgn-nav').prop('hidden', true);
    }

    // -------------------------------------------------------------------
    // Compteur de caracteres
    // -------------------------------------------------------------------

    /**
     * Met a jour le compteur de l'annotation.
     *
     * Le navigateur compte en unites UTF-16 et le serveur en points de
     * code. Pour du texte latin c'est identique ; seuls des emoji
     * feraient diverger les deux, sans consequence puisque le navigateur
     * en laisserait passer moins que le serveur n'en autorise.
     */
    function majCompteur() {
        var n = $('#notes').val().length;
        $('#compteur').text(n);
        $('.compteur').toggleClass('compteur-plein', n >= ECH_NOTES_MAX);
    }

    // -------------------------------------------------------------------
    // Demarrage
    // -------------------------------------------------------------------

    $(function () {
        if (typeof Chessboard === 'undefined') {
            $('#plateau').text('chessboard.js ne s\'est pas chargé. Vérifiez le répertoire assets/.');
            return;
        }

        var config = {
            // Sans position explicite, chessboard.js demarre sur un
            // plateau vide, ce qui est le point de depart voulu.
            draggable: true,
            sparePieces: true,      // force draggable a true de toute facon
            dropOffBoard: 'trash',  // glisser hors du plateau supprime
            pieceTheme: 'assets/pieces/{piece}.svg',
            onDrop: surDepot,
            onChange: surChangement
        };

        if (ECH_FEN_INITIAL) {
            config.position = ECH_FEN_INITIAL;
        }

        board = Chessboard('plateau', config);

        // onChange ne se declenche que sur une modification. Sans cet
        // appel, le champ cache resterait vide tant que vous n'auriez pas
        // touche au plateau, et generer un gabarit vierge, qui est
        // pourtant l'usage d'origine, renverrait une erreur 400.
        $('#fen').val(board.fen());

        placerReperes();

        $(window).on('resize', function () {
            board.resize();
            placerReperes();
        });

        $('#vider').on('click', function () {
            // false coupe l'animation : sur un plateau charge elle est
            // longue et n'apporte rien.
            board.clear(false);
            masquerNavigation();
            messageFen('', '');
        });

        $('#retourner').on('click', function () {
            board.flip();
            placerReperes();
        });

        $('#initiale').on('click', function () {
            board.start(false);
            $('input[name="trait"][value="w"]').prop('checked', true);
            masquerNavigation();
            messageFen('', '');
        });

        $('#charger').on('click', charger);

        $('#pgn-nav').on('click', 'button', function () {
            var cibles = {
                debut: 0,
                precedent: partie.indice - 1,
                suivant: partie.indice + 1,
                fin: partie.fens.length - 1
            };

            allerA(cibles[$(this).data('pas')]);
        });

        // La touche Entree dans la zone de collage vaut clic sur Charger,
        // sans soumettre le formulaire. Maj+Entree insere un saut de ligne,
        // pour qui voudrait retoucher un PGN a la main ; un collage, lui,
        // ne declenche aucun evenement clavier et garde ses sauts de ligne.
        $('#fen-colle').on('keydown', function (e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                charger();
            }
        });

        $('#notes').on('input', majCompteur);
        majCompteur();
    });
}());
