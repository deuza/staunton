<?php
declare(strict_types=1);

/**
 * echiquier.php - fonctions communes au formulaire et au generateur.
 *
 * Vous trouverez ici toute la geometrie du gabarit PDF, ainsi que les
 * fonctions de validation et de conversion du champ de placement FEN.
 * Aucune de ces fonctions n'ecrit sur le disque : tout est produit a la
 * volee, conformement au cahier des charges.
 */

// -----------------------------------------------------------------------
// Geometrie du gabarit PDF, exprimee en points PostScript (1 pt = 1/72").
// Ces valeurs reproduisent a l'identique le gabarit ReportLab d'origine.
// -----------------------------------------------------------------------

/** Un centimetre, en points. */
const ECH_CM = 72.0 / 2.54;

/** Largeur et hauteur d'une page A4 portrait. */
const ECH_PAGE_W = 21.0 * ECH_CM;
const ECH_PAGE_H = 29.7 * ECH_CM;

/** Cote d'une case, puis cote du plateau complet. */
const ECH_CASE  = 2.4 * ECH_CM;
const ECH_BOARD = 8.0 * ECH_CASE;

/**
 * Coin superieur gauche du plateau.
 *
 * Attention : ReportLab place son origine en bas a gauche, TCPDF en haut
 * a gauche. La valeur ci-dessous est donc la conversion de l'origine
 * ReportLab d'origine, a savoir (PAGE_H - BOARD) / 2 + 1,5 cm.
 */
const ECH_BOARD_LEFT = (ECH_PAGE_W - ECH_BOARD) / 2.0;
const ECH_BOARD_TOP  = (ECH_PAGE_H - ECH_BOARD) / 2.0 - 1.5 * ECH_CM;

/** Niveaux de gris du plateau, identiques au gabarit d'origine. */
const ECH_GRIS_FONCE = 204;   // 0.80 en notation ReportLab
const ECH_GRIS_CLAIR = 255;

/**
 * Longueur maximale acceptee pour l'annotation libre.
 *
 * Le chiffre est mesure, pas estime. Entre le haut du bloc de notes et la
 * ligne du FEN il reste 102,6 pt, soit sept lignes a 13,2 pt d'interligne.
 * Le pire caractere qui puisse reellement atteindre le PDF est l'arobase,
 * mesuree a 11,16 pt en corps 11 par GetStringWidth(), les caracteres plus
 * larges etant tous des caracteres de controle que ech_nettoyer_notes()
 * supprime. En tenant compte du prefixe "Position / notes : " qui entame
 * la premiere ligne, 288 arobases tiennent en sept lignes, 289 non.
 *
 * A ce plafond, aucune saisie imaginable ne peut recouvrir le FEN ni
 * engendrer une seconde page, et rien n'est jamais tronque : le champ
 * refuse simplement la frappe suivante.
 *
 * En francais courant, 4,96 pt par caractere, ces 288 caracteres tiennent
 * sur trois lignes.
 */
const ECH_NOTES_MAX = 288;

// -----------------------------------------------------------------------
// Validation et conversion du FEN
// -----------------------------------------------------------------------

/**
 * Valide le champ de placement d'un FEN.
 *
 * La fonction accepte aussi bien un FEN complet a six champs, tel qu'on le
 * copie depuis Lichess ou un moteur, que le placement seul que produit
 * board.fen(). Dans le premier cas elle coupe a la premiere espace.
 *
 * Elle ne nettoie rien : elle valide contre une liste blanche. La nuance
 * compte. Nettoyer une entree, c'est du filtrage par liste noire, et une
 * liste noire se contourne, par double encodage, octet nul, ou wrapper de
 * flux. Une liste blanche ne peut pas echouer ainsi : tout ce qui n'est
 * pas explicitement autorise n'existe pas.
 *
 * @param  string      $fen Chaine brute, par exemple "8/8/8/8/8/8/8/8".
 * @return string|null Le placement valide, ou null s'il ne l'est pas.
 */
function ech_valider_placement(string $fen): ?string
{
    // Garde-fou de volume, avant tout traitement : on ne va pas appliquer
    // des expressions regulieres a une chaine de plusieurs megaoctets.
    if (strlen($fen) > 200) {
        return null;
    }

    $fen = trim($fen);

    // Un FEN complet compte six champs separes par des espaces. Seul le
    // premier decrit la position, les cinq autres portent le trait, les
    // roques, la prise en passant et les compteurs de coups. On accepte
    // donc aussi bien une chaine collee depuis Lichess que le placement
    // seul que produit board.fen().
    $espace = strpos($fen, ' ');
    if ($espace !== false) {
        $fen = substr($fen, 0, $espace);
    }

    // Un placement raisonnable ne depasse jamais 71 caracteres
    // (8 rangees de 8 caracteres + 7 separateurs).
    if ($fen === '' || strlen($fen) > 71) {
        return null;
    }

    // Liste blanche stricte, et non filtrage : pieces, chiffres de
    // compression, separateur. Rien d'autre n'existe.
    //
    // Les ancres sont \A et \z, jamais ^ et $ : ce dernier tolererait un
    // saut de ligne final, qui est la porte d'entree classique de
    // l'injection multiligne.
    if (preg_match('#\A[pnbrqkPNBRQK1-8/]+\z#', $fen) !== 1) {
        return null;
    }

    $rangees = explode('/', $fen);
    if (count($rangees) !== 8) {
        return null;
    }

    foreach ($rangees as $rangee) {
        $colonnes = 0;

        foreach (str_split($rangee) as $c) {
            if (ctype_digit($c)) {
                // Un "0" n'a aucun sens, et "44" non plus : on refuse
                // deux chiffres consecutifs plus loin via le total.
                $colonnes += (int) $c;
            } else {
                $colonnes++;
            }
        }

        // Chaque rangee doit decrire exactement huit cases.
        if ($colonnes !== 8) {
            return null;
        }
    }

    return $fen;
}

/**
 * Convertit un champ de placement en grille indexee.
 *
 * @param  string $fen Placement deja valide par ech_valider_placement().
 * @return array<int, array<int, string|null>> grille[rangee][colonne], ou
 *         rangee 0 correspond au rang 8 (haut du plateau) et colonne 0 a
 *         la colonne "a". Chaque case vaut null ou un code type "wK"/"bP".
 */
function ech_placement_vers_grille(string $fen): array
{
    $grille = [];

    foreach (explode('/', $fen) as $i => $rangee) {
        $ligne = [];

        foreach (str_split($rangee) as $c) {
            if (ctype_digit($c)) {
                // Une suite de cases vides.
                for ($n = (int) $c; $n > 0; $n--) {
                    $ligne[] = null;
                }
            } else {
                // Majuscule = blanc, minuscule = noir, comme le veut le FEN.
                $couleur = ($c === strtoupper($c)) ? 'w' : 'b';
                $ligne[] = $couleur . strtoupper($c);
            }
        }

        $grille[$i] = $ligne;
    }

    return $grille;
}

/**
 * Assemble un FEN complet a partir du seul champ de placement.
 *
 * board.fen() ne renvoie que le placement. Pour que la chaine imprimee en
 * bas de page soit collable telle quelle dans Arena, Lichess ou un moteur,
 * il faut lui ajouter les cinq sections manquantes.
 *
 * Les droits de roque sont volontairement mis a "-" : ce generateur pose
 * des diagrammes libres, rien ne garantit que les tours et le roi soient
 * sur leur case initiale.
 *
 * @param  string $placement Placement valide.
 * @param  string $trait     "w" ou "b".
 * @return string FEN complet a six champs.
 */
function ech_fen_complet(string $placement, string $trait): string
{
    $trait = ($trait === 'b') ? 'b' : 'w';

    return $placement . ' ' . $trait . ' - - 0 1';
}

/**
 * Nettoie l'annotation libre saisie par l'utilisateur.
 *
 * On retire les caracteres de controle, qui n'ont rien a faire ni dans un
 * PDF ni dans une page HTML, on normalise les espaces, puis on tronque.
 *
 * Volontairement ecrite sans mbstring : PCRE compte deja en points de code
 * en mode /u, et cela epargne une dependance de plus a installer.
 *
 * @param  string $notes Texte brut issu du formulaire.
 * @return string Texte assaini, eventuellement vide.
 */
function ech_nettoyer_notes(string $notes): string
{
    // Garde-fou indispensable : face a une chaine qui n'est pas de l'UTF-8
    // valide, toute expression reguliere en mode /u echoue en silence et
    // renvoie null. Un motif vide en mode /u ne reussit que sur une chaine
    // valide, ce qui en fait un test d'encodage a peu de frais.
    if (preg_match('##u', $notes) !== 1) {
        return '';
    }

    // Suppression des caracteres de controle. Pas de mode /u ici : ces
    // octets ne peuvent pas apparaitre au milieu d'une sequence UTF-8
    // multi-octets, dont les octets de continuation valent 0x80 a 0xBF.
    $notes = (string) preg_replace('#[\x00-\x1F\x7F]+#', ' ', $notes);

    // Normalisation des espaces, espace insecable compris.
    $notes = (string) preg_replace('#[\s\x{00A0}]+#u', ' ', $notes);
    $notes = trim($notes);

    // Troncature sur les caracteres, et non sur les octets : couper au
    // milieu d'un caractere accentue produirait de l'UTF-8 invalide.
    if (preg_match('#\A.{0,' . ECH_NOTES_MAX . '}#u', $notes, $correspondance) === 1) {
        $notes = $correspondance[0];
    }

    return $notes;
}

/**
 * Renvoie le chemin absolu du SVG d'une piece.
 *
 * @param  string      $code Code chessboard.js, par exemple "wK".
 * @return string|null Chemin du fichier, ou null s'il est absent.
 */
function ech_chemin_piece(string $code): ?string
{
    // Garde-fou : on n'accepte que les douze codes attendus, jamais une
    // chaine venue du reseau, faute de quoi on ouvrirait une traversee
    // de repertoire.
    if (preg_match('#\A[wb][KQRBNP]\z#', $code) !== 1) {
        return null;
    }

    $chemin = __DIR__ . '/../assets/pieces/' . $code . '.svg';

    return is_readable($chemin) ? $chemin : null;
}
