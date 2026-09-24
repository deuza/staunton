<?php
declare(strict_types=1);

/**
 * generer.php - produit le diagramme a la volee.
 *
 * Aucune ecriture sur le disque, aucune session, aucune base : la position
 * arrive en POST, le document repart dans le flux de sortie.
 *
 * Champs attendus :
 *   fen    champ de placement FEN produit par board.fen()
 *   trait  w ou b
 *   notes  annotation libre
 *   format pdf ou html
 */

require_once __DIR__ . '/lib/echiquier.php';

/**
 * Interrompt le traitement sur une erreur de saisie.
 *
 * @param  string $message Explication destinee a l'utilisateur.
 * @return never
 */
function ech_refuser(string $message)
{
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message, "\n";
    exit;
}

// La position doit venir d'un POST : une URL de generation n'a pas de sens
// ici, le permalien de la page de saisie remplit deja ce role.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ech_refuser('Methode non autorisee. Passez par le formulaire de saisie.');
}

$placement = ech_valider_placement((string) ($_POST['fen'] ?? ''));

if ($placement === null) {
    ech_refuser('Position invalide ou absente.');
}

// Un plateau entierement vide est parfaitement legitime : c'est le gabarit
// vierge d'origine, celui que vous remplissez au stylo.

$trait  = (($_POST['trait'] ?? 'w') === 'b') ? 'b' : 'w';
$notes  = ech_nettoyer_notes((string) ($_POST['notes'] ?? ''));
$format = (string) ($_POST['format'] ?? 'pdf');

switch ($format) {
    case 'html':
        require_once __DIR__ . '/lib/sortie-html.php';
        ech_sortie_html($placement, $trait, $notes);
        break;

    case 'pdf':
        require_once __DIR__ . '/lib/sortie-pdf.php';
        ech_sortie_pdf($placement, $trait, $notes);
        break;

    default:
        ech_refuser('Format de sortie inconnu.');
}
