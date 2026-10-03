#!/bin/sh
#
# fetch-assets.sh - brings the front-end dependencies home.
#
# Run it once, from the root of the project. Once it has run, the page
# works without Internet access: this is what makes it possible to serve
# the generator on an isolated local network.
#
# This script is written in strict POSIX sh, so it runs under bash as well
# as under dash.
#
# From the root of the project:
#     sh fetch-assets.sh
#

set -eu

JQUERY_VERSION='3.7.1'
CHESSBOARD_VERSION='1.0.0'

# chess.js is only used to read pasted PGN: it replays the moves and
# checks their legality. It is only distributed as an ES module, hence the
# loading through chess-module.js. If you change this version, carry it
# over to the import in chess-module.js.
CHESSJS_VERSION='1.4.0'

# The pieces come from the lichess repository: it serves the Cburnett set
# already named the chessboard.js way, and without a rate limiter (the
# Wikimedia Commons copies answer 429 after a few requests in a row).
#
# The commit is pinned: otherwise your pieces would change under your feet
# the next time lichess pushes to its main branch.
LILA_COMMIT='c89ee1af25b988a7380cce8fb0d7fef6e689c0b5'
LILA_BASE="https://raw.githubusercontent.com/lichess-org/lila/${LILA_COMMIT}/public/piece/cburnett"

TARGET='assets'
PIECES="${TARGET}/pieces"

# ---------------------------------------------------------------------
# Tooling check
# ---------------------------------------------------------------------

if ! command -v curl >/dev/null 2>&1; then
    echo "curl cannot be found. Install it: apt install curl" >&2
    exit 1
fi

mkdir -p "${PIECES}"

# ---------------------------------------------------------------------
# Single download
# ---------------------------------------------------------------------
#
# $1 source URL, $2 destination file.
#
fetch() {
    url="$1"
    destination="$2"

    if [ -s "${destination}" ]; then
        printf '  = %s (already present)\n' "${destination}"
        return 0
    fi

    # --fail turns a 404 response into a non-zero exit code, without which
    # you would end up with an error page saved under the name of the
    # expected file.
    #
    # --retry 3 with --retry-delay 2 covers a host that answers 429 or 503
    # to a burst of requests. And --user-agent avoids being taken for an
    # anonymous robot by the services that filter curl's default agent.
    if ! curl -sSL --fail --max-time 60 \
              --retry 3 --retry-delay 2 --retry-connrefused \
              --user-agent 'staunton/1.0 (local asset fetch)' \
              -o "${destination}" "${url}"; then
        printf '  ! failed: %s\n' "${url}" >&2
        rm -f "${destination}"
        return 1
    fi

    printf '  + %s (%s bytes)\n' "${destination}" "$(wc -c < "${destination}" | tr -d ' ')"
}

# ---------------------------------------------------------------------
# jQuery and chessboard.js
# ---------------------------------------------------------------------

echo 'Libraries:'

fetch \
    "https://code.jquery.com/jquery-${JQUERY_VERSION}.min.js" \
    "${TARGET}/jquery-${JQUERY_VERSION}.min.js"

fetch \
    "https://unpkg.com/@chrisoakman/chessboardjs@${CHESSBOARD_VERSION}/dist/chessboard-${CHESSBOARD_VERSION}.min.js" \
    "${TARGET}/chessboard-${CHESSBOARD_VERSION}.min.js"

fetch \
    "https://unpkg.com/@chrisoakman/chessboardjs@${CHESSBOARD_VERSION}/dist/chessboard-${CHESSBOARD_VERSION}.min.css" \
    "${TARGET}/chessboard-${CHESSBOARD_VERSION}.min.css"

fetch \
    "https://unpkg.com/chess.js@${CHESSJS_VERSION}/dist/esm/chess.js" \
    "${TARGET}/chess-${CHESSJS_VERSION}.js"

# ---------------------------------------------------------------------
# The twelve pieces
# ---------------------------------------------------------------------
#
# Cburnett set, the one chessboard.js distributes as 80 pixel PNGs. The
# original SVGs are used here: same drawing, but sharp in print, and the
# same file feeds both the browser and TCPDF.
#
# The lichess repository already uses the chessboard.js naming, so there
# is no name mapping to maintain.
#

echo 'Pieces:'

for code in wK wQ wR wB wN wP bK bQ bR bB bN bP; do
    fetch "${LILA_BASE}/${code}.svg" "${PIECES}/${code}.svg"
done

# ---------------------------------------------------------------------
# Final check
# ---------------------------------------------------------------------

missing=0

for file in \
    "${TARGET}/jquery-${JQUERY_VERSION}.min.js" \
    "${TARGET}/chessboard-${CHESSBOARD_VERSION}.min.js" \
    "${TARGET}/chessboard-${CHESSBOARD_VERSION}.min.css" \
    "${TARGET}/chess-${CHESSJS_VERSION}.js"
do
    [ -s "${file}" ] || { printf 'missing: %s\n' "${file}" >&2; missing=$((missing + 1)); }
done

for code in wK wQ wR wB wN wP bK bQ bR bB bN bP; do
    [ -s "${PIECES}/${code}.svg" ] || { printf 'missing: %s\n' "${PIECES}/${code}.svg" >&2; missing=$((missing + 1)); }
done

if [ "${missing}" -ne 0 ]; then
    printf '\n%d missing file(s).\n' "${missing}" >&2
    exit 1
fi

# ---------------------------------------------------------------------
# Integrity check
# ---------------------------------------------------------------------
#
# The piece SVGs are inlined as is into the generated HTML page: an
# altered file (compromised host, mishandling) would become code executed
# there. The SHA-256 checksums of the sixteen files are therefore pinned
# below, in this script itself, and every file is checked, whether it has
# just been downloaded or was already present. Kept here rather than in
# assets/, they are still available when assets/ is fetched from scratch.
#
# They were checked against the upstream sources: npm for the libraries,
# the lichess repository at the pinned commit for the pieces.
#
# Three tools are tried, in this order: sha256sum (coreutils, on every
# Debian), shasum (Perl, on macOS and most BSDs), then openssl, present
# almost everywhere.
#
# If you change a version or the commit above, check the new files by
# other means, then replace the lines of pinned_checksums() with the
# output of:
#     cd assets && sha256sum *.js *.css pieces/*.svg
#
pinned_checksums() {
    cat <<'EOF'
76c7c34f0e2e9ab076521a5d6fe786a9cce537bb1b6f29d32a9c9970b5b232d2  chess-1.4.0.js
68d033595ff24f38a50534b0da8fa14a76b8c0f3b3e6b7d2636bfa26c47f6675  chessboard-1.0.0.min.js
fc9a93dd241f6b045cbff0481cf4e1901becd0e12fb45166a8f17f95823f0b1a  jquery-3.7.1.min.js
1c1748d2ed9803ed44311a4b04ada62a2ae3508ea91b6b4b29872e5c4dd77dde  chessboard-1.0.0.min.css
b4b502654a68cae278d53cc0e2ace07723e8cff6b3dfa0ac65556fca7f8abb4f  pieces/bB.svg
b83f0a15002eefcd30137d17042894f2ed821f6a85bd6eb41b5c85ce733359ed  pieces/bK.svg
29fb49686cdc093f7e52920d68ba50ad17864469e683b6b6b4a2cf2d351d0d3d  pieces/bN.svg
9fac3f9bb943a037942edba604a07a7bacbb35edd70385ad4d500b94aff17fd2  pieces/bP.svg
ced642b82c627ce91b489e9fab0d7a5c907b35f46bb0718be398224fa13f225e  pieces/bQ.svg
0102dc83d8925bd045fcf8647c9367bbdec7d0c03e92bedb1bb49ddc26e3bb85  pieces/bR.svg
85c2d04155e1a196e06b2402f1b3000a9cb155f1cf5bfe77fe31ea4af03089ba  pieces/wB.svg
6a015951fb9ec986212164038e03008ae36d3438c2d4550d3ec1da76b172fa04  pieces/wK.svg
d788c0b7efdda72fcd480b8c9c32d225c2a12223b0b08c775a86e96c2eac36d7  pieces/wN.svg
7c81fe77ac7c5f6f397ee7f1b7801b3186cc63d42627dd4af1404ab49f3565b2  pieces/wP.svg
9f15cb1bc933b8b81717cf28dbb4e0297d52fba7e5f3c2665d897b2528647302  pieces/wQ.svg
602e42b31c44bee22b0849bf3a9c7ba0b1745c465805c036de1eca65e84a6a9b  pieces/wR.svg
EOF
}

#
# Returns 0 if everything matches, 1 on an unexpected checksum, 2 if no
# tool is available.
#
verify_checksums() {
    if command -v sha256sum >/dev/null 2>&1; then
        pinned_checksums | (cd "${TARGET}" && sha256sum -c -) || return 1
        return 0
    fi

    if command -v shasum >/dev/null 2>&1; then
        pinned_checksums | (cd "${TARGET}" && shasum -a 256 -c -) || return 1
        return 0
    fi

    if command -v openssl >/dev/null 2>&1; then
        # The loop runs in a subshell, at the end of the pipe: its exit
        # status carries the result.
        pinned_checksums | {
            errors=0
            # "|| [ -n ... ]" also reads a last line without a final line
            # feed, which read reports as the end of the input.
            while read -r checksum path || [ -n "${checksum}" ]; do
                [ -n "${checksum}" ] || continue
                computed=$(openssl dgst -sha256 -r "${TARGET}/${path}" 2>/dev/null | cut -d ' ' -f 1)
                if [ "${computed}" = "${checksum}" ]; then
                    printf '%s: OK\n' "${path}"
                else
                    printf '%s: FAILED\n' "${path}"
                    errors=$((errors + 1))
                fi
            done
            [ "${errors}" -eq 0 ]
        } || return 1
        return 0
    fi

    return 2
}

# Returns 0 if $1, a path relative to assets/, has a pinned checksum.
listed() {
    pinned_checksums | awk -v p="$1" '$2 == p { found = 1 } END { exit !found }' || {
        printf '  not listed: %s\n' "$1" >&2
        return 1
    }
}

echo
echo 'Checksums:'

if [ "${STAUNTON_SKIP_CHECKSUMS:-0}" = '1' ]; then
    # Explicit bypass, for a system without any of the three tools or a
    # deliberate change to the assets. Whoever sets it knows what they are
    # doing; they are reminded all the same.
    echo '  WARNING: STAUNTON_SKIP_CHECKSUMS=1, no verification.' >&2
    echo '  The files in assets/ are served as they are, unchecked.' >&2
else
    # Every expected file must be listed: the three tools only check the
    # lines present, and an incomplete list would let an unlisted file
    # through unchecked.
    uncovered=0
    for path in \
        "jquery-${JQUERY_VERSION}.min.js" \
        "chessboard-${CHESSBOARD_VERSION}.min.js" \
        "chessboard-${CHESSBOARD_VERSION}.min.css" \
        "chess-${CHESSJS_VERSION}.js"
    do
        listed "${path}" || uncovered=$((uncovered + 1))
    done
    for code in wK wQ wR wB wN wP bK bQ bR bB bN bP; do
        listed "pieces/${code}.svg" || uncovered=$((uncovered + 1))
    done
    if [ "${uncovered}" -ne 0 ]; then
        echo "  ${uncovered} file(s) without a pinned checksum." >&2
        echo '  Update the list (see the comment above pinned_checksums).' >&2
        exit 1
    fi

    code=0
    verify_checksums || code=$?

    if [ "${code}" -eq 2 ]; then
        echo '  No verification tool (sha256sum, shasum, openssl).' >&2
        echo '  Install one, or run again with STAUNTON_SKIP_CHECKSUMS=1' >&2
        echo '  if you accept serving these files unchecked.' >&2
        exit 1
    fi

    if [ "${code}" -ne 0 ]; then
        echo >&2
        echo '  Unexpected checksum: a file does not match its source.' >&2
        echo '  Do not serve it. Delete it and run this script again.' >&2
        exit 1
    fi
fi

echo
echo 'All set. The page now works offline.'
