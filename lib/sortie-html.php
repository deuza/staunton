<?php
declare(strict_types=1);

/**
 * sortie-html.php - rendu du diagramme en page HTML statique.
 *
 * La page produite est autonome : les SVG des pieces sont inseres en clair
 * dans le document, il n'y a ni feuille de style ni image externe. Vous
 * pouvez donc l'enregistrer telle quelle, l'envoyer par courriel ou
 * l'imprimer sans rien emporter d'autre.
 *
 * Elle ne contient pas non plus le moindre lien vers la machine qui l'a
 * produite : pas de favicon, pas de href, pas de src. Une fois enregistree
 * ou transmise, un lien relatif ne resoudrait plus rien, et un lien absolu
 * trahirait l'adresse du serveur sans rien apporter au diagramme.
 *
 * Deux partis pris de mise en page, tous deux dictes par l'impression :
 *
 *  - le plateau est un tableau et non une grille CSS, parce que c'est le
 *    seul modele que tous les moteurs de rendu traitent a l'identique,
 *    y compris les plus anciens ;
 *  - toutes les cotes sont ecrites en clair, sans calc(). Les moteurs
 *    d'impression ne resolvent pas tous calc() combine a var(), et une
 *    cote non resolue fait exploser la taille des cases.
 *
 * L'unique source de verite reste donc les constantes ci-dessous.
 */

require_once __DIR__ . '/echiquier.php';

/** Cote d'une case, en centimetres. Identique au gabarit PDF. */
const ECH_CASE_CM = 2.4;

/** Largeur de la gouttiere qui porte la notation, en centimetres. */
const ECH_REPERE_CM = 0.6;

/** Part de la case occupee par le dessin d'une piece. */
const ECH_PIECE_RATIO = 0.86;

/**
 * Met en forme une longueur en centimetres pour le CSS.
 *
 * @param  float $cm Longueur.
 * @return string Par exemple "2.4cm" ou "2.064cm".
 */
function ech_cm(float $cm): string
{
    return rtrim(rtrim(number_format($cm, 3, '.', ''), '0'), '.') . 'cm';
}

/**
 * Charge le SVG d'une piece et le prepare pour l'insertion en ligne.
 *
 * On retire la declaration XML et l'eventuelle DTD, qui n'ont rien a faire
 * au milieu d'un document HTML, puis on remplace les dimensions fixes du
 * fichier d'origine par une viewBox, pour que la piece suive la taille que
 * lui impose la feuille de style.
 *
 * @param  string $code Code chessboard.js, par exemple "wK".
 * @return string Balisage SVG pret a inserer, ou "" si la piece manque.
 */
function ech_svg_en_ligne(string $code): string
{
    $chemin = ech_chemin_piece($code);
    if ($chemin === null) {
        return '';
    }

    $svg = (string) file_get_contents($chemin);

    // Suppression de la declaration XML et de la DTD.
    $svg = (string) preg_replace('#<\?xml.*?\?>\s*#s', '', $svg);
    $svg = (string) preg_replace('#<!DOCTYPE.*?>\s*#s', '', $svg);

    // Les SVG Cburnett sont dessines dans un carre de 45 unites.
    $svg = (string) preg_replace(
        '#<svg\b[^>]*>#',
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 45 45" class="piece">',
        $svg,
        1
    );

    return $svg;
}

/**
 * Produit la page HTML et l'envoie au navigateur.
 *
 * @param  string $placement Champ de placement FEN, deja valide.
 * @param  string $trait     "w" ou "b".
 * @param  string $notes     Annotation libre, deja assainie.
 * @param  bool   $retourner true pour recuperer le code au lieu de l'emettre.
 * @return string Le code HTML si $retourner vaut true, sinon "".
 */
function ech_sortie_html(string $placement, string $trait, string $notes, bool $retourner = false): string
{
    $grille = ech_placement_vers_grille($placement);
    $fen    = ech_fen_complet($placement, $trait);

    $colonnes = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];

    // Cotes calculees une fois, puis injectees telles quelles dans le CSS.
    $cCase    = ech_cm(ECH_CASE_CM);
    $cRepere  = ech_cm(ECH_REPERE_CM);
    $cPiece   = ech_cm(ECH_CASE_CM * ECH_PIECE_RATIO);
    $cFeuille = ech_cm(8 * ECH_CASE_CM + 2 * ECH_REPERE_CM);

    // -------------------------------------------------------------------
    // Construction du tableau 10x10 : le plateau, borde de sa notation.
    // -------------------------------------------------------------------
    $entete = '<tr><td class="coin"></td>';
    foreach ($colonnes as $lettre) {
        $entete .= '<td class="reperecol">' . $lettre . '</td>';
    }
    $entete .= '<td class="coin"></td></tr>';

    $lignes   = [];
    $lignes[] = $entete;

    // Les huit rangees, du rang 8 au rang 1.
    foreach ($grille as $ligne => $cases) {
        // $rang vaut 7 pour le rang 8, 0 pour le rang 1.
        $rang = 7 - $ligne;
        $num  = 8 - $ligne;

        $tr = '<tr><td class="reperelig">' . $num . '</td>';

        foreach ($cases as $colonne => $code) {
            // Meme regle de parite que dans le PDF : a1 foncee, h1 claire.
            $classes = ['case', (($colonne + $rang) % 2 === 0) ? 'foncee' : 'claire'];

            // Le cadre exterieur est porte par les cases du pourtour :
            // border-collapse fusionne les segments en un trait continu.
            if ($ligne === 0) {
                $classes[] = 'bhaut';
            }
            if ($ligne === 7) {
                $classes[] = 'bbas';
            }
            if ($colonne === 0) {
                $classes[] = 'bgauche';
            }
            if ($colonne === 7) {
                $classes[] = 'bdroite';
            }

            $piece = ($code === null) ? '' : ech_svg_en_ligne($code);

            $tr .= '<td class="' . implode(' ', $classes) . '">' . $piece . '</td>';
        }

        $tr .= '<td class="reperelig">' . $num . '</td></tr>';

        $lignes[] = $tr;
    }

    // Derniere ligne, en miroir de la premiere.
    $lignes[] = $entete;

    $plateau = implode("\n", $lignes);

    // -------------------------------------------------------------------
    // Ligne d'annotation, identique a celle du PDF.
    // -------------------------------------------------------------------
    $ligneNotes = ($notes === '')
        ? 'Position / notes : ______________________________________'
        : 'Position / notes : ' . $notes;

    $ligneNotes = htmlspecialchars($ligneNotes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // A qui de jouer. L'information est dans le FEN, mais personne ne lit
    // un FEN d'un coup d'oeil, et un diagramme sans trait est ambigu.
    $ligneTrait  = ($trait === 'b') ? 'Trait aux Noirs' : 'Trait aux Blancs';
    $classeTrait = ($trait === 'b') ? 'pastille-noirs' : 'pastille-blancs';
    $fenEchappe = htmlspecialchars('FEN : ' . $fen, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // -------------------------------------------------------------------
    // Assemblage du document
    // -------------------------------------------------------------------
    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagramme</title>
<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 1.2cm;
    background: #fff;
    color: #000;
    font-family: Helvetica, Arial, sans-serif;
}

/* Bloc unique, centre, qui sert de reference a tout le reste. */
.feuille { width: $cFeuille; margin: 0 auto; }

.plateau { border-collapse: collapse; }
.plateau td { padding: 0; margin: 0; }

.case {
    width:  $cCase;
    height: $cCase;
    text-align: center;
    vertical-align: middle;
}

.claire { background: #fff; }
.foncee { background: #ccc; }

/* Cadre exterieur du plateau. */
.bhaut   { border-top:    1.2pt solid #000; }
.bbas    { border-bottom: 1.2pt solid #000; }
.bgauche { border-left:   1.2pt solid #000; }
.bdroite { border-right:  1.2pt solid #000; }

.coin { width: $cRepere; height: $cRepere; }

.reperecol {
    width:  $cCase;
    height: $cRepere;
    text-align: center;
    vertical-align: middle;
    font-size: 11pt;
}

.reperelig {
    width:  $cRepere;
    height: $cCase;
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

/* Le retrait aligne le texte sur le bord gauche du plateau, et non sur
   la gouttiere de notation, exactement comme dans le PDF. */
.notes {
    padding-left: $cRepere;
    padding-right: $cRepere;
    margin: 1.1cm 0 0;
    font-size: 11pt;
    line-height: 1.35;
    color: #666;

    /* MultiCell coupe de force un mot trop long dans le PDF. Sans cette
       regle le HTML ne le ferait pas, et une annotation depourvue
       d'espaces s'echapperait de la page sur une seule ligne. */
    overflow-wrap: break-word;
    overflow-wrap: anywhere;
}

/* Trait, aligne sur le bord droit du plateau. La pastille est un rond,
   selon la convention des recueils de problemes : plein pour les Noirs,
   vide et cercle pour les Blancs. */
.trait {
    padding-right: $cRepere;
    margin: 0.55cm 0 0;
    font-size: 10pt;
    text-align: right;
}

.pastille {
    display: inline-block;
    width: 0.30cm;
    height: 0.30cm;
    margin-right: 0.18cm;
    border: 0.8pt solid #000;
    border-radius: 50%;
    vertical-align: baseline;
}

.pastille-noirs  { background: #000; }
.pastille-blancs { background: #fff; }

.fen {
    padding-left: $cRepere;
    margin: 0.6cm 0 0;
    font-family: "DejaVu Sans Mono", Courier, monospace;
    font-size: 10pt;
    color: #000;
    word-break: break-all;
}

/*
 * Marges laterales reduites : la feuille fait deja $cFeuille de large, il
 * ne reste que quelques millimetres de chaque cote sur une A4. Pensez a
 * regler la boite de dialogue d'impression sur des marges par defaut ou
 * nulles, faute de quoi le navigateur reduira l'echelle et la case ne
 * fera plus $cCase.
 */
@page { size: A4 portrait; margin: 0.8cm 0.3cm; }

@media print {
    body { padding: 0; }
    /* Sans cela, plusieurs navigateurs suppriment les aplats gris. */
    .foncee,
    .pastille-noirs {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
</head>
<body>

<div class="feuille">

<table class="plateau">
$plateau
</table>

<p class="trait"><span class="pastille $classeTrait"></span>$ligneTrait</p>

<p class="notes">$ligneNotes</p>
<p class="fen">$fenEchappe</p>

</div>

</body>
</html>
HTML;

    if ($retourner) {
        return $html;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;

    return '';
}
