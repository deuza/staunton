<?php
declare(strict_types=1);

/**
 * index.php - saisie de la position.
 *
 * La page accepte trois parametres facultatifs en GET :
 *
 *   fen   position de depart, placement seul ou FEN complet
 *         (le PGN, trop long pour une URL, ne se charge que par collage)
 *   trait w ou b
 *   notes annotation libre
 *
 * Ils ne sont plus affiches sous forme de permalien, mais ils restent pris
 * en charge : c'est ce qui donne son sens au FEN imprime en bas de chaque
 * diagramme. Vous pouvez revenir a une position depuis une feuille papier
 * en recopiant la chaine dans l'URL.
 *
 * Tout est valide ici, cote serveur, avant d'etre reinjecte dans la page.
 */

require_once __DIR__ . '/lib/echiquier.php';

$fenBrut   = trim((string) ($_GET['fen'] ?? ''));
$placement = ech_valider_placement($fenBrut);

// On distingue "aucun FEN fourni" de "FEN fourni mais invalide". Dans le
// second cas on le dit, plutot que d'afficher un plateau vide sans
// explication et de vous laisser chercher.
$fenIgnore = ($fenBrut !== '' && $placement === null);

if ($placement === null) {
    $placement = '';
}

$trait = (($_GET['trait'] ?? 'w') === 'b') ? 'b' : 'w';
$notes = ech_nettoyer_notes((string) ($_GET['notes'] ?? ''));

/** Raccourci d'echappement pour le contexte HTML. */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Échiquier de travail &mdash; saisie de position</title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="stylesheet" href="assets/chessboard-1.0.0.min.css">
<link rel="stylesheet" href="style.css">
</head>
<body>

<header class="entete">
    <h1>Échiquier de travail</h1>
    <p>Composez une position, exportez-la en PDF ou en page HTML.</p>
</header>

<?php if ($fenIgnore): ?>
<div class="alerte alerte-avertissement">
    Le FEN passé dans l'URL a été ignoré : il n'est pas valide.
    Le plateau démarre vide.
</div>
<?php endif; ?>

<div class="conteneur">

    <!-- Colonne de gauche : le plateau et ses commandes -->
    <div class="carte carte-plateau">

        <div class="plateau-cadre">
            <div class="reperes reperes-rangs" id="rangs"></div>
            <div id="plateau"></div>
            <div class="reperes reperes-colonnes" id="colonnes"></div>
        </div>

        <div class="barre">
            <button type="button" id="vider" class="bouton bouton-danger">Vider le plateau</button>
            <button type="button" id="retourner" class="bouton bouton-neutre">Retourner</button>
            <button type="button" id="initiale" class="bouton bouton-neutre">Position initiale</button>
        </div>

    </div>

    <!--
        Colonne de droite : le formulaire.

        Il s'ouvre dans un nouvel onglet, la page de saisie reste donc
        intacte. Vous pouvez enchaîner une sortie PDF puis une sortie HTML
        sans avoir à reconstruire la position.
    -->
    <form id="formulaire" class="carte panneau" action="generer.php" method="post" target="_blank">

        <input type="hidden" name="fen" id="fen" value="<?= h($placement) ?>">

        <section class="bloc">
            <h2>Charger une position</h2>
            <!--
                Une zone multiligne plutot qu'un champ texte : un champ
                <input type="text"> remplace les sauts de ligne par des
                espaces au collage, ce qui defigurerait un PGN.
            -->
            <div class="ligne">
                <textarea id="fen-colle" rows="3" autocomplete="off" spellcheck="false"
                          placeholder="Collez une position (FEN) ou une partie en cours (PGN)"></textarea>
                <button type="button" id="charger" class="bouton bouton-info">Charger</button>
            </div>
            <p class="message" id="fen-message" role="status"></p>

            <!-- Navigation dans la partie, visible seulement apres un PGN. -->
            <div class="navigation" id="pgn-nav" hidden>
                <button type="button" class="bouton bouton-neutre" data-pas="debut" title="Position de départ">&laquo;</button>
                <button type="button" class="bouton bouton-neutre" data-pas="precedent" title="Coup précédent">&lsaquo;</button>
                <button type="button" class="bouton bouton-neutre" data-pas="suivant" title="Coup suivant">&rsaquo;</button>
                <button type="button" class="bouton bouton-neutre" data-pas="fin" title="Position finale">&raquo;</button>
                <span class="coup" id="pgn-coup"></span>
            </div>
        </section>

        <section class="bloc">
            <h2>Annotation</h2>
            <textarea name="notes" id="notes" rows="3"
                      maxlength="<?= ECH_NOTES_MAX ?>"
                      placeholder="Position / notes"><?= h($notes) ?></textarea>
            <p class="compteur"><span id="compteur">0</span> / <?= ECH_NOTES_MAX ?></p>
        </section>

        <section class="bloc">
            <h2>Trait aux</h2>
            <div class="choix">
                <label><input type="radio" name="trait" value="w"<?= $trait === 'w' ? ' checked' : '' ?>> Blancs</label>
                <label><input type="radio" name="trait" value="b"<?= $trait === 'b' ? ' checked' : '' ?>> Noirs</label>
            </div>
        </section>

        <section class="bloc">
            <h2>Sortie</h2>
            <div class="choix">
                <label><input type="radio" name="format" value="pdf" checked> PDF</label>
                <label><input type="radio" name="format" value="html"> HTML</label>
            </div>
        </section>

        <button type="submit" class="bouton bouton-succes bouton-large">Générer le diagramme</button>

    </form>


    <!--
        Mode d'emploi du plateau, dans sa propre carte a droite du
        formulaire. Sur un ecran large il s'aligne sur le haut des autres
        cartes ; sur un ecran etroit il passe au-dessus, sur toute la
        largeur (voir order dans style.css).
    -->
    <aside class="carte carte-aide">
        <h2>Mode d'emploi</h2>
        <p>
            Glissez une pièce depuis la palette vers une case.
            Pour retirer une pièce, cliquez dessus sans la déplacer,
            ou faites-la glisser hors du plateau.
        </p>
    </aside>

</div>

<script src="assets/jquery-3.7.1.min.js"></script>
<script src="assets/chessboard-1.0.0.min.js"></script>
<script type="module">
// chess.js n'est distribue qu'en module ES ou en CommonJS. On l'expose donc
// en variable globale pour app.js, qui n'en a besoin qu'au chargement d'un
// PGN, donc bien apres l'execution de ce module.
import { Chess } from './assets/chess-1.4.0.js';
window.Chess = Chess;
</script>
<script>
// Position initiale transmise par l'URL, déjà validée côté serveur.
var ECH_FEN_INITIAL = <?= json_encode($placement, JSON_THROW_ON_ERROR) ?>;
var ECH_NOTES_MAX   = <?= ECH_NOTES_MAX ?>;
</script>
<script src="app.js"></script>

</body>
</html>
