<?php
declare(strict_types=1);

/**
 * test.php - controles de non-regression, en php-cli pur.
 *
 * Aucune dependance : ni Composer, ni Node, ni jsdom. Il se lance depuis
 * la racine du projet :
 *
 *     php lib/test.php
 *
 * Il renvoie 0 si tout passe, 1 sinon, ce qui le rend utilisable tel quel
 * dans un crochet git ou une tache planifiee.
 *
 * Il se trouve dans lib/ parce que le .htaccess y refuse tout acces en
 * HTTP. Ce placement ne gene pas l'execution en ligne de commande.
 */

require_once __DIR__ . '/echiquier.php';

$total  = 0;
$echecs = 0;

/**
 * Compare une valeur obtenue a la valeur attendue.
 *
 * @param string $intitule Ce qui est verifie.
 * @param mixed  $obtenu   Valeur produite par le code.
 * @param mixed  $attendu  Valeur de reference.
 */
function verifier(string $intitule, $obtenu, $attendu): void
{
    global $total, $echecs;

    $total++;

    $o = var_export($obtenu, true);
    $a = var_export($attendu, true);

    if ($o === $a) {
        printf("  ok     %s\n", $intitule);
        return;
    }

    $echecs++;
    printf("  ECHEC  %s\n         obtenu  %s\n         attendu %s\n", $intitule, $o, $a);
}

/** Affiche un titre de section. */
function section(string $titre): void
{
    printf("\n%s\n", $titre);
}

// =======================================================================
section('Validation du placement : cas legitimes');
// =======================================================================

verifier(
    'plateau vide',
    ech_valider_placement('8/8/8/8/8/8/8/8'),
    '8/8/8/8/8/8/8/8'
);

verifier(
    'position initiale',
    ech_valider_placement('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR'),
    'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR'
);

// Un FEN complet copie depuis Lichess doit passer : c'est le format que
// l'on colle le plus souvent, et celui qui est imprime en pied de page.
verifier(
    'FEN complet, six champs',
    ech_valider_placement('8/8/8/4k3/8/8/8/4K3 w - - 0 1'),
    '8/8/8/4k3/8/8/8/4K3'
);

verifier(
    'espaces en tete et en queue',
    ech_valider_placement('   8/8/8/8/8/8/8/8 b - - 0 1   '),
    '8/8/8/8/8/8/8/8'
);

// =======================================================================
section('Validation du placement : structures invalides');
// =======================================================================

verifier('rangee de neuf cases', ech_valider_placement('9/8/8/8/8/8/8/8'), null);
verifier('sept rangees',          ech_valider_placement('8/8/8/8/8/8/8'), null);
verifier('neuf rangees',          ech_valider_placement('8/8/8/8/8/8/8/8/8'), null);
verifier('rangee incomplete',     ech_valider_placement('7/8/8/8/8/8/8/8'), null);
verifier('chaine vide',           ech_valider_placement(''), null);
verifier('piece inconnue',        ech_valider_placement('xnbqkbnr/8/8/8/8/8/8/8'), null);
verifier('chaine de 5 ko',        ech_valider_placement(str_repeat('8/', 3000)), null);

// =======================================================================
section('Validation du placement : charges hostiles');
// =======================================================================

// La fonction ne nettoie pas, elle valide contre une liste blanche. Ces
// charges ne sont donc pas filtrees, elles n'existent simplement pas dans
// l'alphabet autorise. Le jour ou quelqu'un elargirait la classe de
// caracteres, ces lignes le lui rappelleraient.
$hostiles = [
    'traversee simple'      => '../../etc/passwd',
    'traversee profonde'    => '../../../home/user/.bash_profile',
    'traversee doublee'     => '....//....//etc/passwd',
    'traversee encodee'     => '..%2f..%2fetc%2fpasswd',
    'encodage double'       => '%252e%252e%252fetc%252fpasswd',
    'chemin absolu'         => '/etc/passwd',
    'antislash Windows'     => '..\\..\\windows\\system32',
    'wrapper php'           => 'php://filter/convert.base64-encode/resource=index.php',
    'wrapper data'          => 'data://text/plain;base64,PD9waHA=',
    'wrapper file'          => 'file:///etc/passwd',
    'tilde'                 => '~/.ssh/id_rsa',
    'substitution shell'    => '$(cat /etc/passwd)',
    'accent grave'          => '`id`',
    'octet nul'             => "8/8/8/8/8/8/8/8\x00../../etc/passwd",
    'saut de ligne final'   => "8/8/8/8/8/8/8/8\n../../etc/passwd",
];

foreach ($hostiles as $nom => $charge) {
    verifier($nom, ech_valider_placement($charge), null);
}

// Cas particulier : tout ce qui suit la premiere espace est jete, donc la
// charge disparait et le placement valide subsiste. C'est le comportement
// voulu, pas un contournement.
verifier(
    'injection apres une espace : la charge est jetee',
    ech_valider_placement('8/8/8/8/8/8/8/8 ; cat /etc/passwd'),
    '8/8/8/8/8/8/8/8'
);

// =======================================================================
section('Resolution du chemin des pieces');
// =======================================================================

// Seul point du code qui touche le disque. Deuxieme liste blanche.
foreach (['wK', 'wQ', 'wR', 'wB', 'wN', 'wP', 'bK', 'bQ', 'bR', 'bB', 'bN', 'bP'] as $code) {
    verifier('piece ' . $code . ' presente', ech_chemin_piece($code) !== null, true);
}

$codesHostiles = [
    '../../../etc/passwd',
    'wK/../../../etc/passwd',
    "wK\x00../../etc/passwd",
    '/etc/passwd',
    'php://filter/resource=index.php',
    'wKK', 'xK', 'wk', 'wX', '', '..', 'w/K', '%2e%2e%2fwK',
];

foreach ($codesHostiles as $i => $code) {
    verifier('code de piece hostile ' . ($i + 1), ech_chemin_piece($code), null);
}

// =======================================================================
section('Conversion en grille');
// =======================================================================

$grille = ech_placement_vers_grille('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR');

verifier('a8 : tour noire',       $grille[0][0], 'bR');
verifier('h8 : tour noire',       $grille[0][7], 'bR');
verifier('e8 : roi noir',         $grille[0][4], 'bK');
verifier('d8 : dame noire',       $grille[0][3], 'bQ');
verifier('a1 : tour blanche',     $grille[7][0], 'wR');
verifier('e1 : roi blanc',        $grille[7][4], 'wK');
verifier('d4 : case vide',        $grille[4][3], null);
verifier('huit rangees',          count($grille), 8);
verifier('huit colonnes',         count($grille[0]), 8);

// =======================================================================
section('Parite des cases');
// =======================================================================

// Le bug historique : a1 avait ete dessinee claire. La regle, identique
// dans la sortie PDF et dans la sortie HTML, est que la case est foncee
// quand (colonne + rang) est pair, ou le rang vaut 0 pour la rangee 1.
$foncee = static fn (int $colonne, int $rang): bool => (($colonne + $rang) % 2 === 0);

verifier('a1 est foncee', $foncee(0, 0), true);
verifier('h1 est claire', $foncee(7, 0), false);
verifier('a8 est claire', $foncee(0, 7), false);
verifier('h8 est foncee', $foncee(7, 7), true);
verifier('e4 est claire',  $foncee(4, 3), false);
verifier('d4 est foncee',  $foncee(3, 3), true);

// =======================================================================
section('Geometrie du gabarit');
// =======================================================================

// Valeurs relevees sur le PDF ReportLab d'origine, a un centieme de point.
verifier('largeur de page',    round(ECH_PAGE_W, 2), 595.28);
verifier('hauteur de page',    round(ECH_PAGE_H, 2), 841.89);
verifier('cote de case',       round(ECH_CASE, 2), 68.03);
verifier('cote du plateau',    round(ECH_BOARD, 2), 544.25);
verifier('bord gauche',        round(ECH_BOARD_LEFT, 2), 25.51);
verifier('bord superieur',     round(ECH_BOARD_TOP, 2), 106.3);
verifier('gris des cases',     ECH_GRIS_FONCE, 204);

// =======================================================================
section('Assemblage du FEN complet');
// =======================================================================

verifier(
    'trait aux Blancs',
    ech_fen_complet('8/8/8/8/8/8/8/8', 'w'),
    '8/8/8/8/8/8/8/8 w - - 0 1'
);

verifier(
    'trait aux Noirs',
    ech_fen_complet('8/8/8/8/8/8/8/8', 'b'),
    '8/8/8/8/8/8/8/8 b - - 0 1'
);

verifier(
    'trait aberrant ramene aux Blancs',
    ech_fen_complet('8/8/8/8/8/8/8/8', 'z'),
    '8/8/8/8/8/8/8/8 w - - 0 1'
);

// =======================================================================
section('Nettoyage de l annotation');
// =======================================================================

verifier(
    'texte ordinaire intact',
    ech_nettoyer_notes('Defense sicilienne, Najdorf'),
    'Defense sicilienne, Najdorf'
);

verifier(
    'espaces multiples normalises',
    ech_nettoyer_notes('  trop   d   espaces  '),
    'trop d espaces'
);

verifier(
    'caracteres de controle remplaces',
    ech_nettoyer_notes("avant\x01\x02apres"),
    'avant apres'
);

verifier(
    'espace insecable normalise',
    ech_nettoyer_notes("avant\u{00A0}\u{00A0}apres"),
    'avant apres'
);

verifier(
    'accents preserves',
    ech_nettoyer_notes('échec et mat, la dame prend en français'),
    'échec et mat, la dame prend en français'
);

// La fonction est volontairement ecrite sans mbstring, mais elle doit
// compter en points de code et non en octets.
$longAccentue = str_repeat('é', ECH_NOTES_MAX + 50);
$tronque      = ech_nettoyer_notes($longAccentue);

verifier(
    'troncature au plafond, en caracteres',
    (int) preg_match_all('/./u', $tronque),
    ECH_NOTES_MAX
);

verifier(
    'la troncature ne casse pas l UTF-8',
    preg_match('##u', $tronque),
    1
);

verifier(
    'UTF-8 invalide rejete en bloc',
    ech_nettoyer_notes("\xC3\x28 casse"),
    ''
);

verifier(
    'balises laissees telles quelles, l echappement est a l affichage',
    ech_nettoyer_notes('<script>alert(1)</script>'),
    '<script>alert(1)</script>'
);

verifier('mbstring n est pas requis', extension_loaded('mbstring'), extension_loaded('mbstring'));

// =======================================================================
section('Sortie HTML');
// =======================================================================

require_once __DIR__ . '/sortie-html.php';

$sortie = ech_sortie_html('8/8/8/4k3/8/8/8/4K3', 'b', 'Finale de rois', true);

verifier('document complet',        str_contains($sortie, '<!DOCTYPE html>'), true);
verifier('soixante-quatre cases',   substr_count($sortie, '<td class="case'), 64);
verifier('deux rois places',        substr_count($sortie, 'class="piece"'), 2);
verifier('annotation presente',     str_contains($sortie, 'Finale de rois'), true);
verifier('FEN complet en pied',     str_contains($sortie, '8/8/8/4k3/8/8/8/4K3 b - - 0 1'), true);
verifier('FEN precede de son libelle', str_contains($sortie, 'FEN : 8/8/8/4k3/8/8/8/4K3'), true);

// Le trait avait ete oublie dans les deux sorties. Ces trois lignes
// auraient attrape l'omission.
verifier('trait annonce',           str_contains($sortie, 'Trait aux Noirs'), true);
verifier('pastille pleine',         str_contains($sortie, 'pastille-noirs'), true);

$sortieBlancs = ech_sortie_html('8/8/8/8/8/8/8/8', 'w', '', true);
verifier('trait aux Blancs',        str_contains($sortieBlancs, 'Trait aux Blancs'), true);
verifier('pastille vide',           str_contains($sortieBlancs, 'pastille-blancs'), true);
verifier('pastille ronde',          str_contains($sortie, 'border-radius: 50%'), true);

// La page est destinee a etre enregistree ou transmise : aucun lien ne
// doit renvoyer vers la machine qui l'a produite.
verifier('aucun href',              str_contains($sortie, 'href='), false);
verifier('aucun src',               str_contains($sortie, 'src='), false);
verifier('aucun favicon',           str_contains($sortie, 'favicon'), false);

// MultiCell coupe un mot trop long dans le PDF. Le HTML doit en faire
// autant, sans quoi une annotation sans espaces sort de la page.
verifier('coupure des mots longs',  str_contains($sortie, 'overflow-wrap: anywhere'), true);

// L'echappement se fait a l'affichage, pas au nettoyage.
$sortieXss = ech_sortie_html('8/8/8/8/8/8/8/8', 'w', '<script>alert(1)</script>', true);
verifier('balise echappee',         str_contains($sortieXss, '&lt;script&gt;'), true);
verifier('balise non executable',   str_contains($sortieXss, '<script>alert(1)</script>'), false);

// =======================================================================
section('Sortie PDF');
// =======================================================================

// TCPDF est une dependance du projet, mais le test reste utilisable sans
// lui : on signale l'absence plutot que d'echouer.
if (is_readable('/usr/share/php/tcpdf/tcpdf.php')) {
    require_once __DIR__ . '/sortie-pdf.php';

    $pdfNoirs  = ech_sortie_pdf('8/8/8/4k3/8/8/8/4K3', 'b', 'Finale de rois', true);
    $pdfBlancs = ech_sortie_pdf('8/8/8/8/8/8/8/8', 'w', '', true);

    verifier('en-tete PDF',        substr($pdfNoirs, 0, 5), '%PDF-');
    // Le noeud /Pages porte le decompte. Compter "/Type /Page" serait
    // faux : la chaine apparait aussi dans "/Type /Pages".
    preg_match('#/Count\s+(\d+)#', $pdfNoirs, $pages);
    verifier('une seule page',     $pages[1] ?? '?', '1');

    // TCPDF glisse un lien promotionnel vers son site dans Close(). La
    // sous-classe EchiquierPdf l'eteint ; verifions qu'elle le fait
    // encore.
    verifier('aucune annotation de lien', substr_count($pdfNoirs, '/URI'), 0);

    // TCPDF inscrit son adresse dans le Producer, info et XMP.
    // Le dictionnaire d'information est en UTF-16BE : on cherche donc la
    // chaine sous ses deux formes.
    $utf16 = implode('', array_map(static fn (string $c): string => "\0" . $c, str_split('tcpdf.org')));
    verifier('aucune adresse tcpdf.org',  str_contains($pdfNoirs, 'tcpdf.org'), false);
    verifier('aucune adresse en UTF-16',  str_contains($pdfNoirs, $utf16), false);
    verifier('aucune adresse, vierge',    str_contains($pdfBlancs, $utf16), false);

    // Le paquet XMP a raccourci : sa longueur declaree doit suivre.
    $xmpOk = preg_match('#/Subtype /XML /Length (\d+) >> stream\n(.*?)\nendstream#s', $pdfNoirs, $xmp) === 1
        && (int) $xmp[1] === strlen($xmp[2]);
    verifier('longueur XMP coherente',    $xmpOk, true);

    verifier('taille plausible, avec pieces', strlen($pdfNoirs) > 5000, true);
    verifier('taille plausible, vierge',      strlen($pdfBlancs) > 3000, true);
} else {
    printf("  (ignore) TCPDF absent, controles PDF non executes\n");
}

// =======================================================================
printf("\n%d controles, %d echec(s).\n", $total, $echecs);

exit($echecs === 0 ? 0 : 1);
