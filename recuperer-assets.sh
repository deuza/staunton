#!/bin/sh
#
# recuperer-assets.sh - rapatrie en local les dependances du front.
#
# A lancer une seule fois, depuis la racine du projet. Une fois execute,
# la page fonctionne sans acces a Internet : c'est ce qui permet de servir
# le generateur sur un reseau local isole.
#
# Ce script est ecrit en sh POSIX strict, il tourne donc aussi bien sous
# bash que sous dash.
#
# Depuis la racine du projet :
#     sh recuperer-assets.sh
#

set -eu

JQUERY_VERSION='3.7.1'
CHESSBOARD_VERSION='1.0.0'

# chess.js sert uniquement a lire les PGN colles : il rejoue les coups et
# en verifie la legalite. Il n'est distribue qu'en module ES, d'ou le
# chargement par <script type="module"> dans index.php. Si vous changez
# cette version, reportez-la dans l'import de index.php.
CHESSJS_VERSION='1.4.0'

# Les pieces viennent du depot lichess plutot que de Wikimedia Commons.
#
# Commons applique un limiteur de debit qui se declenche au bout de
# quelques requetes consecutives depuis la meme adresse, et renvoie alors
# un 429 en plein milieu du telechargement. Le depot lichess sert
# exactement le meme jeu Cburnett, deja nomme au format chessboard.js,
# sans limitation de ce genre.
#
# Le commit est fige : sans cela, vos pieces changeraient sous vos pieds
# au prochain envoi de lichess sur sa branche principale.
LILA_COMMIT='c89ee1af25b988a7380cce8fb0d7fef6e689c0b5'
LILA_BASE="https://raw.githubusercontent.com/lichess-org/lila/${LILA_COMMIT}/public/piece/cburnett"

CIBLE='assets'
PIECES="${CIBLE}/pieces"

# ---------------------------------------------------------------------
# Verification de l'outillage
# ---------------------------------------------------------------------

if ! command -v curl >/dev/null 2>&1; then
    echo "curl est introuvable. Installez-le : apt install curl" >&2
    exit 1
fi

mkdir -p "${PIECES}"

# ---------------------------------------------------------------------
# Telechargement unitaire
# ---------------------------------------------------------------------
#
# $1 URL source, $2 fichier de destination.
#
recuperer() {
    url="$1"
    destination="$2"

    if [ -s "${destination}" ]; then
        printf '  = %s (deja present)\n' "${destination}"
        return 0
    fi

    # --fail transforme une reponse 404 en code de retour non nul, sans
    # quoi vous vous retrouveriez avec une page d'erreur enregistree sous
    # le nom du fichier attendu.
    #
    # --retry 3 avec --retry-delay 2 couvre le cas d'un hebergeur qui
    # repond 429 ou 503 sur une rafale de requetes. Et --user-agent evite
    # d'etre pris pour un robot anonyme par les services qui filtrent
    # l'agent par defaut de curl.
    if ! curl -sSL --fail --max-time 60 \
              --retry 3 --retry-delay 2 --retry-connrefused \
              --user-agent 'echiquier/1.0 (recuperation locale des assets)' \
              -o "${destination}" "${url}"; then
        printf '  ! echec : %s\n' "${url}" >&2
        rm -f "${destination}"
        return 1
    fi

    printf '  + %s (%s octets)\n' "${destination}" "$(wc -c < "${destination}" | tr -d ' ')"
}

# ---------------------------------------------------------------------
# jQuery et chessboard.js
# ---------------------------------------------------------------------

echo 'Bibliotheques :'

recuperer \
    "https://code.jquery.com/jquery-${JQUERY_VERSION}.min.js" \
    "${CIBLE}/jquery-${JQUERY_VERSION}.min.js"

recuperer \
    "https://unpkg.com/@chrisoakman/chessboardjs@${CHESSBOARD_VERSION}/dist/chessboard-${CHESSBOARD_VERSION}.min.js" \
    "${CIBLE}/chessboard-${CHESSBOARD_VERSION}.min.js"

recuperer \
    "https://unpkg.com/@chrisoakman/chessboardjs@${CHESSBOARD_VERSION}/dist/chessboard-${CHESSBOARD_VERSION}.min.css" \
    "${CIBLE}/chessboard-${CHESSBOARD_VERSION}.min.css"

recuperer \
    "https://unpkg.com/chess.js@${CHESSJS_VERSION}/dist/esm/chess.js" \
    "${CIBLE}/chess-${CHESSJS_VERSION}.js"

# ---------------------------------------------------------------------
# Les douze pieces
# ---------------------------------------------------------------------
#
# Jeu Cburnett, celui que chessboard.js distribue en PNG de 80 pixels.
# On prend ici les SVG d'origine : meme dessin, mais net a l'impression,
# et le meme fichier sert au navigateur et a TCPDF.
#
# Le depot lichess utilise deja la nomenclature de chessboard.js, il n'y
# a donc aucune correspondance de noms a maintenir.
#

echo 'Pieces :'

for code in wK wQ wR wB wN wP bK bQ bR bB bN bP; do
    recuperer "${LILA_BASE}/${code}.svg" "${PIECES}/${code}.svg"
done

# ---------------------------------------------------------------------
# Controle final
# ---------------------------------------------------------------------

manquants=0

for fichier in \
    "${CIBLE}/jquery-${JQUERY_VERSION}.min.js" \
    "${CIBLE}/chessboard-${CHESSBOARD_VERSION}.min.js" \
    "${CIBLE}/chessboard-${CHESSBOARD_VERSION}.min.css" \
    "${CIBLE}/chess-${CHESSJS_VERSION}.js"
do
    [ -s "${fichier}" ] || { printf 'manquant : %s\n' "${fichier}" >&2; manquants=$((manquants + 1)); }
done

for code in wK wQ wR wB wN wP bK bQ bR bB bN bP; do
    [ -s "${PIECES}/${code}.svg" ] || { printf 'manquant : %s\n' "${PIECES}/${code}.svg" >&2; manquants=$((manquants + 1)); }
done

if [ "${manquants}" -ne 0 ]; then
    printf '\n%d fichier(s) manquant(s).\n' "${manquants}" >&2
    exit 1
fi

echo
echo 'Tout est en place. La page fonctionne desormais hors ligne.'
