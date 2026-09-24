<?php
declare(strict_types=1);

/**
 * sortie-pdf.php - rendu du diagramme en PDF A4 via TCPDF.
 *
 * Le gabarit reproduit trait pour trait le PDF ReportLab d'origine :
 * case de 2,4 cm, gris 0,80 pour les cases foncees, a1 foncee, notation
 * sur les quatre cotes, ligne de notes sous le plateau.
 *
 * Rien n'est ecrit sur le disque : le PDF part directement dans le flux
 * de sortie HTTP.
 */

require_once __DIR__ . '/echiquier.php';

// Chemin du paquet Debian php-tcpdf. Si vous installez TCPDF par Composer,
// remplacez cette ligne par require_once 'vendor/autoload.php'.
require_once '/usr/share/php/tcpdf/tcpdf.php';

/** Hauteur de cellule utilisee pour poser les libelles de notation. */
const ECH_PDF_LIBELLE_H = 13.0;

/** Corps de la ligne d'annotation, en points. */
const ECH_PDF_NOTES_PT = 11.0;

/** Corps du FEN imprime en pied de page, en points. */
const ECH_PDF_FEN_PT = 10.0;

/** Interligne du bloc d'annotation, en points. */
const ECH_PDF_NOTES_H = 13.2;

/** Corps de la ligne indiquant le trait, en points. */
const ECH_PDF_TRAIT_PT = 10.0;

/**
 * Distance entre le bas de la page et le haut de la ligne du FEN.
 *
 * 40 pt, soit 1,41 cm, laisse la ligne hors de la zone non imprimable des
 * imprimantes domestiques, tout en degageant une septieme ligne pour
 * l'annotation. C'est cette valeur qui fixe ECH_NOTES_MAX.
 */
const ECH_PDF_FEN_MARGE = 40.0;

/**
 * TCPDF depouille de son lien promotionnel.
 *
 * TCPDF glisse en bas de la derniere page, dans Close(), un "Powered by
 * TCPDF" de 1 point en mode de rendu invisible, assorti d'une annotation
 * de lien vers tcpdf.org. Rien ne se voit a l'ecran ni a l'impression,
 * mais le texte ressort a l'extraction et le lien reste cliquable dans
 * tout document que vous diffusez.
 *
 * La propriete qui commande ce comportement est protegee et n'a pas
 * d'accesseur public : la seule facon propre de l'eteindre est d'heriter.
 *
 * TCPDF inscrit en outre son adresse dans le champ Producer, a deux
 * endroits : le dictionnaire d'information et le paquet XMP. La chaine
 * vient d'une methode statique que l'on ne peut pas surcharger ; elle est
 * donc retiree au passage, dans _out(), la ou transitent tous les objets
 * du document. Le document produit ne contient ainsi aucun lien, ni vers
 * tcpdf.org ni vers la machine qui l'a genere.
 */
final class EchiquierPdf extends TCPDF
{
    /**
     * Adresse que TCPDF accole a son nom dans le champ Producer.
     */
    private const LIEN_PRODUCTEUR = ' (http://www.tcpdf.org)';
    /**
     * @param string $orientation P ou L.
     * @param string $unite       Unite de mesure, ici des points.
     * @param string $format      Format de page.
     */
    public function __construct(string $orientation, string $unite, string $format)
    {
        parent::__construct($orientation, $unite, $format, true, 'UTF-8', false);

        $this->tcpdflink = false;
    }

    /**
     * Ecrit un fragment du document, debarrasse de l'adresse de TCPDF.
     *
     * Seuls les objets de structure sont concernes (etat autre que 2) :
     * le contenu des pages, donc l'annotation saisie, n'est jamais touche.
     *
     * @param string $s Fragment a ecrire.
     */
    protected function _out($s)
    {
        if ($this->state !== 2 && is_string($s) && str_contains($s, 'Producer')) {
            $producteur = TCPDF_STATIC::getTCPDFProducer();
            $sobre      = str_replace(self::LIEN_PRODUCTEUR, '', $producteur);

            // Dictionnaire d'information : la chaine y est encodee par
            // _textstring(), en UTF-16BE avec parentheses echappees. On
            // l'encode donc de la meme facon pour la retrouver. Le document
            // n'est jamais chiffre, le numero d'objet est sans effet.
            //
            // Paquet XMP : la chaine y figure en XML echappe.
            $s = str_replace(
                [$this->_textstring($producteur), TCPDF_STATIC::_escapeXML($producteur)],
                [$this->_textstring($sobre), TCPDF_STATIC::_escapeXML($sobre)],
                $s
            );

            // Le flux XMP a raccourci : sa longueur declaree doit suivre,
            // sans quoi le lecteur signalerait un fichier abime.
            $s = (string) preg_replace_callback(
                '#/Length \d+ >> stream\n(.*)\nendstream#s',
                static fn (array $m): string =>
                    '/Length ' . strlen($m[1]) . " >> stream\n" . $m[1] . "\nendstream",
                $s
            );
        }

        parent::_out($s);
    }
}

/**
 * Produit le PDF et l'envoie au navigateur.
 *
 * @param string $placement  Champ de placement FEN, deja valide.
 * @param string $trait      "w" ou "b".
 * @param string $notes      Annotation libre, deja assainie.
 * @param bool   $retourner  true pour recuperer les octets au lieu de les
 *                           emettre (utile pour les tests hors serveur web).
 * @return string Les octets du PDF si $retourner vaut true, sinon "".
 */
function ech_sortie_pdf(string $placement, string $trait, string $notes, bool $retourner = false): string
{
    $grille = ech_placement_vers_grille($placement);

    // Document en points, pour coller exactement a la geometrie ReportLab.
    $pdf = new EchiquierPdf('P', 'pt', 'A4');

    $pdf->SetCreator('echiquier');
    $pdf->SetTitle('Diagramme');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetCellPadding(0);
    $pdf->AddPage();

    $gauche = ECH_BOARD_LEFT;
    $haut   = ECH_BOARD_TOP;
    $droite = $gauche + ECH_BOARD;
    $bas    = $haut + ECH_BOARD;

    // -------------------------------------------------------------------
    // Les 64 cases
    // -------------------------------------------------------------------
    // La grille est indexee du haut vers le bas : grille[0] est le rang 8.
    // La parite est calculee sur (colonne + rang reel) pour garantir que
    // a1 reste foncee et h1 claire, comme sur un vrai echiquier.
    for ($ligne = 0; $ligne < 8; $ligne++) {
        // $rang vaut 7 pour le rang 8, 0 pour le rang 1.
        $rang = 7 - $ligne;

        for ($colonne = 0; $colonne < 8; $colonne++) {
            $teinte = (($colonne + $rang) % 2 === 0) ? ECH_GRIS_FONCE : ECH_GRIS_CLAIR;

            $pdf->SetFillColor($teinte, $teinte, $teinte);
            $pdf->Rect(
                $gauche + $colonne * ECH_CASE,
                $haut + $ligne * ECH_CASE,
                ECH_CASE,
                ECH_CASE,
                'F'
            );
        }
    }

    // -------------------------------------------------------------------
    // Cadre exterieur
    // -------------------------------------------------------------------
    $pdf->SetLineStyle(['width' => 1.2, 'color' => [0, 0, 0]]);
    $pdf->Rect($gauche, $haut, ECH_BOARD, ECH_BOARD, 'D');

    // -------------------------------------------------------------------
    // Les pieces
    // -------------------------------------------------------------------
    // Les SVG Cburnett sont dessines dans un carre de 45 unites, avec le
    // motif legerement plus petit que le cadre. On les pose sur 86 % de la
    // case, centres, pour retrouver la respiration visuelle de l'ecran.
    $taille = ECH_CASE * 0.86;
    $marge  = (ECH_CASE - $taille) / 2.0;

    foreach ($grille as $ligne => $cases) {
        foreach ($cases as $colonne => $code) {
            if ($code === null) {
                continue;
            }

            $svg = ech_chemin_piece($code);
            if ($svg === null) {
                // Piece absente du jeu d'assets : on saute plutot que de
                // faire echouer toute la page.
                continue;
            }

            $pdf->ImageSVG(
                $svg,
                $gauche + $colonne * ECH_CASE + $marge,
                $haut + $ligne * ECH_CASE + $marge,
                $taille,
                $taille,
                '',
                '',
                '',
                0,
                false
            );
        }
    }

    // -------------------------------------------------------------------
    // Notation sur les quatre cotes
    // -------------------------------------------------------------------
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(0, 0, 0);

    $colonnes = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];

    // ReportLab positionne son texte par la ligne de base, TCPDF par le
    // coin haut gauche d'une cellule. Les decalages ci-dessous replacent
    // les lignes de base exactement la ou les avait mises le gabarit :
    // 0,6 cm sous le plateau, 0,35 cm au-dessus, 0,35 cm sur les cotes.
    $baseVersHaut = ECH_PDF_LIBELLE_H / 2.0 + 11.0 * 0.35;

    foreach ($colonnes as $i => $lettre) {
        $x = $gauche + $i * ECH_CASE;

        // Sous le plateau, ligne de base a 0,6 cm.
        $pdf->SetXY($x, $bas + 0.6 * ECH_CM - $baseVersHaut);
        $pdf->Cell(ECH_CASE, ECH_PDF_LIBELLE_H, $lettre, 0, 0, 'C');

        // Au-dessus, en miroir, ligne de base a 0,35 cm.
        $pdf->SetXY($x, $haut - 0.35 * ECH_CM - $baseVersHaut);
        $pdf->Cell(ECH_CASE, ECH_PDF_LIBELLE_H, $lettre, 0, 0, 'C');
    }

    // Largeur de la gouttiere reservee aux chiffres, de part et d'autre.
    $gouttiere = 14.0;

    for ($i = 0; $i < 8; $i++) {
        // $i = 0 correspond a la ligne du haut, donc au rang 8.
        $y      = $haut + $i * ECH_CASE + (ECH_CASE - ECH_PDF_LIBELLE_H) / 2.0;
        $numero = (string) (8 - $i);

        // A gauche, bord droit du chiffre a 0,35 cm du plateau.
        $pdf->SetXY($gauche - 0.35 * ECH_CM - $gouttiere, $y);
        $pdf->Cell($gouttiere, ECH_PDF_LIBELLE_H, $numero, 0, 0, 'R');

        // A droite, bord gauche du chiffre a 0,35 cm du plateau.
        $pdf->SetXY($droite + 0.35 * ECH_CM, $y);
        $pdf->Cell($gouttiere, ECH_PDF_LIBELLE_H, $numero, 0, 0, 'L');
    }

    // -------------------------------------------------------------------
    // Trait
    // -------------------------------------------------------------------
    // L'information figure dans le FEN, mais personne ne lit un FEN d'un
    // coup d'oeil, et un diagramme sans trait est ambigu. La ligne se
    // pose entre la notation du bas et le bloc d'annotation, alignee sur
    // le bord droit du plateau.
    //
    // La pastille est un rond, selon la convention des recueils de
    // problemes : plein pour les Noirs, vide et cercle pour les Blancs.
    $ligneTrait = ($trait === 'b') ? 'Trait aux Noirs' : 'Trait aux Blancs';

    $pdf->SetFont('helvetica', '', ECH_PDF_TRAIT_PT);
    $pdf->SetTextColor(0, 0, 0);

    $largeurTrait = $pdf->GetStringWidth($ligneTrait);
    $baseTrait    = $bas + 1.45 * ECH_CM;   // ligne de base du texte

    // Pastille, calee sur la hauteur des capitales.
    $cote = 7.5;
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineStyle(['width' => 0.6, 'color' => [0, 0, 0]]);

    if ($trait === 'b') {
        $pdf->SetFillColor(0, 0, 0);
        $style = 'DF';
    } else {
        $pdf->SetFillColor(255, 255, 255);
        $style = 'DF';
    }

    // Le rond occupe exactement la place de l'ancien carre : meme diametre,
    // meme retrait par rapport au texte, pose sur la ligne de base.
    $rayon = $cote / 2.0;
    $pdf->Circle(
        $droite - $largeurTrait - 5.0 - $rayon,
        $baseTrait - $rayon,
        $rayon,
        0,
        360,
        $style
    );

    $pdf->SetXY(
        $droite - $largeurTrait,
        $baseTrait - (ECH_PDF_LIBELLE_H / 2.0 + ECH_PDF_TRAIT_PT * 0.35)
    );
    $pdf->Cell($largeurTrait, ECH_PDF_LIBELLE_H, $ligneTrait, 0, 0, 'R');

    // -------------------------------------------------------------------
    // Ligne d'annotation
    // -------------------------------------------------------------------
    // Sans annotation on retombe sur la ligne pointillee du gabarit
    // d'origine, prete a etre remplie au stylo.
    $pdf->SetFont('helvetica', '', ECH_PDF_NOTES_PT);
    $pdf->SetTextColor(102, 102, 102);   // 0,4 en notation ReportLab

    $ligneNotes = ($notes === '')
        ? 'Position / notes : ______________________________________'
        : 'Position / notes : ' . $notes;

    // MultiCell et non Cell : une annotation longue revient a la ligne au
    // lieu de deborder de la page. La premiere ligne de base reste posee
    // la ou le gabarit ReportLab la placait, a 2,3 cm sous le plateau, et
    // le bloc grandit vers le bas, en direction de la ligne du FEN.
    //
    // Le saut de page automatique est desactive depuis la creation du
    // document : meme une saisie aberrante ne pourrait pas engendrer une
    // seconde page. Le plafond de ECH_NOTES_MAX caracteres garantit de
    // toute facon que le bloc ne touche jamais le FEN.
    $pdf->SetXY(
        $gauche,
        $bas + 2.3 * ECH_CM - (ECH_PDF_NOTES_H / 2.0 + ECH_PDF_NOTES_PT * 0.35)
    );
    $pdf->MultiCell(ECH_BOARD, ECH_PDF_NOTES_H, $ligneNotes, 0, 'L', false, 1);

    // -------------------------------------------------------------------
    // FEN en pied de page
    // -------------------------------------------------------------------
    // En noir et en chasse fixe : cette chaine se recopie a la main ou se
    // relit sur une photocopie, elle doit rester nette. La chasse fixe
    // evite surtout de confondre le 1, le l minuscule et le I majuscule.
    $pdf->SetFont('courier', '', ECH_PDF_FEN_PT);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetXY($gauche, ECH_PAGE_H - ECH_PDF_FEN_MARGE);
    // Le prefixe coute six caracteres. Meme sur le pire FEN possible,
    // 81 caracteres, la ligne fait 522 pt sur les 544 pt du plateau.
    $pdf->Cell(ECH_BOARD, 14.0, 'FEN : ' . ech_fen_complet($placement, $trait), 0, 0, 'L');

    // -------------------------------------------------------------------
    // Emission
    // -------------------------------------------------------------------
    if ($retourner) {
        return $pdf->Output('diagramme.pdf', 'S');
    }

    // "I" affiche le PDF dans le navigateur, avec un nom de fichier propose
    // si l'utilisateur choisit de l'enregistrer.
    $pdf->Output('diagramme.pdf', 'I');

    return '';
}
