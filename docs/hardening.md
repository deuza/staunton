# Staunton: hardening notes

This document details the security measures built into Staunton, and the web server configuration that completes them. The [README](../README.md) covers installation and everyday use.

1. [Principles](#1-principles)
2. [Entry points](#2-entry-points)
3. [Request parameters](#3-request-parameters)
4. [Position (FEN)](#4-position-fen)
5. [Side to move](#5-side-to-move)
6. [Annotation](#6-annotation)
7. [PDF output](#7-pdf-output)
8. [HTML output](#8-html-output)
9. [Browser dependencies](#9-browser-dependencies)
10. [Web server configuration](#10-web-server-configuration)
11. [Test suite](#11-test-suite)
12. [Supported versions](#12-supported-versions)

---

## 1. Principles

- **Nothing is stored.** No file is written, there is no session and no database: each request produces its document straight into the output stream.
- **Allow lists, not filters.** Every input is compared with what is allowed; anything else is refused or ignored. A filter can be bypassed (double encoding, null byte, stream wrapper); an allow list cannot, since what it does not list does not exist.
- **Escaping on output.** Inputs are validated and cleaned, never escaped in advance; escaping happens where the value is written, for the context it is written to.
- **Defence in depth.** Each protection that depends on the web server is backed by one in the code, and the other way round.

## 2. Entry points

Only `index.php` and `generate.php` are entry points. They define the `CHB_ENTRY` constant before loading `lib/`, and every file in `lib/` stops with a 403 when loaded without it. This lock is enforced by PHP itself, whatever the web server and its configuration.

- `lib/test.php` only runs from the command line: served over HTTP, it would generate PDFs at every request.
- `lib/.htaccess` (`Require all denied`) is an extra safety net. Apache only reads it if `AllowOverride` allows at least `AuthConfig`; with `AllowOverride None`, the Debian default, it is ignored without a word, hence the PHP lock.
- `generate.php` only accepts POST. Any other method gets a 405 with `Allow: POST`.
- `generate.php` also answers at `generate.php/diagram.pdf` and `generate.php/diagram.html`: `app.js` puts the name of the file at the end of the address, the one browsers propose when saving the document. Chromium's PDF viewer falls back on that last segment when the name given by `Content-Disposition` does not reach it. Anything else after `generate.php` gets a 404.

## 3. Request parameters

- Every parameter is read through `chb_param()`, which only returns strings. A request such as `fen[]=x` makes PHP build an array: it is read as an empty string, so the code never emits an "Array to string conversion" warning (with `display_errors` on, such a warning, sent before the headers, would also break the PDF).
- `generate.php` also reads `orientation`, the orientation of the input board. Only the exact value `black` counts as a flip, as an allow list; with Black to move, the diagram is drawn from Black's side anyway.
- `index.php` reads `fen` and `turn` from the URL. The annotation is never read from the URL: it would end up in the server logs, the browser history and those of any proxy.
- An input error gets a plain text 400 response that says what is wrong; nothing is dropped silently.

## 4. Position (FEN)

`chb_validate_placement()` validates the placement field:

- a size guard of 200 bytes, before any processing, then at most 71 characters for the placement (8 ranks of 8 characters and 7 separators);
- only the first field is kept, up to the first space;
- an allow list, `[pnbrqkPNBRQK1-8/]`, anchored with `\A` and `\z`, never `^` and `$`, which tolerate a trailing line feed;
- two consecutive digits are refused: `44` adds up to eight squares but is not FEN, and chess.js and Lichess reject it;
- exactly eight ranks of exactly eight squares.

The placement never reaches the disk. The only file access of the code, `chb_piece_path()`, has its own allow list: the twelve piece codes, `[wb][KQRBNP]`.

The validated placement is written back into the input page in two attributes, escaped with `htmlspecialchars()`, and passed to the script through a `data-*` attribute rather than an inline script.

## 5. Side to move

The side to move of a request is settled by `chb_resolve_turn()`, in this order:

1. the `turn` parameter, if it is exactly `w` or `b`;
2. otherwise the second field of a full FEN, if it is exactly `w` or `b` and the placement before it is valid;
3. otherwise White.

The second field goes through an allow list as well (`chb_fen_turn()`): one or more spaces after the placement, then `w` or `b`, followed by a space or by the end of the string. `W`, `white`, a fullwidth letter, a tag, a null byte or a line feed glued to the letter cannot match. An invalid field is simply ignored and the placement stays usable. The input page applies the same rule when a FEN is pasted.

## 6. Annotation

**Length.** The annotation is limited to 194 characters: the largest number that always fits in the seven lines left below the board, however the text breaks into words. The worst case is not a run of the widest character but a run of words of about half a line: each of them moves alone to the next line and leaves half of the previous one empty. An exhaustive search, checked with TCPDF's own line breaking, shows that 195 characters are enough to overflow.

**Size.** On the server side, an annotation of more than 776 bytes (194 characters of at most 4 bytes each in UTF-8) cannot come from the form: it is refused with a 400, before any regular expression runs.

**Cleaning** (`chb_clean_notes()`):

- a string that is not valid UTF-8 gives an empty annotation;
- C0 controls, DEL and C1 controls are replaced with a space;
- Unicode format characters (category Cf: right-to-left override U+202E, zero width space U+200B, byte order mark, soft hyphen, zero width joiner...) are removed: they are not displayed but act on the text;
- whitespace, no-break space included, is normalised;
- truncation is counted in code points, without mbstring, so that an accented character is never cut in half.

**Output.** The HTML page escapes the annotation with `htmlspecialchars()`. In the PDF, TCPDF writes it as text and escapes parentheses and backslashes. Before writing it, the PDF output measures it with TCPDF's line breaking and refuses the document (400) beyond seven lines, rather than overlap the FEN, should the font, the board width or a TCPDF update change the computation.

The PDF writes the annotation in Helvetica, a TCPDF core font limited to the Western character set (close to Windows-1252): other scripts come out as `?` in the PDF, but show correctly in the HTML page.

## 7. PDF output

- **Metadata are constants**, creator and title only. No user data ever goes into the information dictionary or the XMP packet: `ChessboardPdf::_out()` rewrites, with a regular expression, every object outside the page streams that contains `Producer`, and free text in those objects would make that rewriting remotely triggerable.
- **No promotional link.** At the bottom of the last page, TCPDF slips in a 1 point "Powered by TCPDF", in invisible rendering mode, with a link annotation to tcpdf.org. The `ChessboardPdf` subclass switches it off: the document contains no link at all.
- **No address in the Producer field.** TCPDF writes its address there, in the information dictionary and in the XMP packet; it is removed when writing, and the declared length of the XMP stream is recomputed. The field comes down to "TCPDF" and the version number.
- **One page, always.** Automatic page breaks are disabled from the creation of the document.
- **TCPDF 6 only.** TCPDF 7 keeps the name and the public API, but runs on another engine, without the `TCPDF_STATIC` class nor the internals that `ChessboardPdf` relies on. TCPDF 6.8 and later also need the PHP curl extension. In both cases the PDF output stops with a clear message and a 500, instead of a fatal error; the technical detail goes to the PHP error log, not to the visitor.

## 8. HTML output

- **Self-contained.** The piece SVGs are inlined, the styles too: no external resource, and no link at all (no `href`, no `src`, no favicon). Once saved or forwarded, the page gives nothing away about the machine that produced it.
- **No script.** The page contains none and needs none, so its content security policy forbids them all (see section 10), including in a piece SVG that might have been altered.

## 9. Browser dependencies

- jQuery, chessboard.js, chess.js and the twelve Cburnett pieces are served from the project itself: no third-party host is contacted when the page runs.
- `fetch-assets.sh` pins the versions of the libraries, and the lichess commit the pieces come from.
- `fetch-assets.sh` pins, inside itself, the checksums of the sixteen files, checked against their upstream sources (npm for the libraries, the lichess repository at the pinned commit for the pieces). Kept in the script rather than in `assets/`, they are still available when `assets/` has to be fetched from scratch. The piece SVGs are inlined into the generated page: an altered file would become code there.
- The script checks every file, whether it has just been downloaded or was already present, with the first tool available among `sha256sum`, `shasum` and `openssl`. It stops on the slightest difference, when a file has no pinned checksum, or when none of the three tools exists. `STAUNTON_SKIP_CHECKSUMS=1` bypasses the check, knowingly.
- After any deliberate change of version or commit, check the new files by other means, then replace the lines of `pinned_checksums()` with the output of:

  ```
  cd assets && sha256sum *.js *.css pieces/*.svg
  ```

- `app.js` calls none of the utility functions removed in jQuery 4.0.0, so the upgrade stays open.
- `fetch-assets.sh` stays next to the code, since the test suite reads its checksums; the server configuration refuses to serve it (section 10).

## 10. Web server configuration

The code protects itself, but some protections can only come from the web server: they do not exist without its configuration. That configuration is therefore not optional. It is the second pillar of the security of an installation, and it is to be applied before the page is opened to others.

| Protection                              | In the code                                  | In the server configuration                |
|-----------------------------------------|----------------------------------------------|--------------------------------------------|
| `lib/` not reachable from outside       | include lock, 403 on the existing files      | `Require all denied` on the whole directory |
| `lib/test.php` not runnable over HTTP   | command line only, 403                       | `Require all denied`                       |
| no directory listing                    | none                                         | `Options -Indexes`, `autoindex off`        |
| `.git` not readable                     | none                                         | refused on the whole server                |
| `vendor/` not served                    | none                                         | refused                                    |
| `fetch-assets.sh` not served            | none                                         | refused                                    |
| hardening headers                       | none                                         | `nosniff`, `DENY`, `no-referrer`           |
| content security policies               | no inline script, so that they can apply     | one for the input page, one for the output |
| server version hidden                   | none                                         | `ServerTokens Prod`, `server_tokens off`   |

The configuration ships with the project, ready to install: [`docs/apache2/staunton.conf`](apache2/staunton.conf) and [`docs/nginx/staunton.conf`](nginx/staunton.conf). Both are reproduced below; the test suite makes sure the copies in this document stay identical to the files.

### Apache

From the root of the project, with PHP through `libapache2-mod-php` or `php-fpm`:

```
cp docs/apache2/staunton.conf /etc/apache2/conf-available/staunton.conf
a2enmod headers
a2enconf staunton
apache2ctl configtest && systemctl reload apache2
```

- **The order matters.** `Header` is a directive of mod_headers: without the module, `configtest` stops on `Invalid command 'Header'`. As long as `configtest` fails with the configuration enabled, a reload is refused and the running server carries on, but a restart or a reboot leaves Apache down. To back out halfway: `a2disconf staunton`. A reload is enough to load mod_headers.
- **The paths.** The file expects the project in `/var/www/html/staunton`: adjust its four paths if it lives elsewhere.
- **`.git` is refused on the whole server**, for every clone below the document roots, not only for Staunton. Debian's `security.conf` offers an alternative, commented out: `RedirectMatch 404 /\.git`, which answers as if nothing were there.
- **`Options -Indexes` wins** over a more general `Options Indexes`, such as Debian's default for `/var/www/`: the most specific `<Directory>` section applies.
- **`lib/.htaccess` is redundant** with this configuration, and kept on purpose. Where `AllowOverride` lets Apache read it (Debian's default, `None`, does not), it refuses `lib/` on hosts whose server configuration cannot be edited, such as shared hosting. And it keeps `lib/` closed should PHP stop interpreting the files, after a PHP upgrade for instance: Apache would otherwise hand out their source.

```apache
<Directory /var/www/html/staunton>
    # No directory listing.
    Options -Indexes
    Require all granted

    # Hardening headers (headers module: a2enmod headers).
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "no-referrer"

    # Input page: the site's own scripts and style sheets only, no inline
    # script. style-src-attr only allows style="..." attributes, which
    # chessboard.js needs to size the board.
    Header always set Content-Security-Policy "default-src 'none'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"

    # The fetch script holds the pinned checksums the test suite reads:
    # it stays next to the code, but is never served.
    <Files "fetch-assets.sh">
        Require all denied
    </Files>

    # Generated HTML page: no script, ever, not even in an altered piece
    # SVG; inline styles only (the page is self-contained).
    # The PDF goes out without a CSP.
    <Files "generate.php">
        Header always unset Content-Security-Policy
        Header always set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'" "expr=%{CONTENT_TYPE} =~ m#^text/html#"
    </Files>
</Directory>

# PHP includes: already locked by the code, refused here as well.
<Directory /var/www/html/staunton/lib>
    Require all denied
</Directory>

# Composer dependencies (FreeBSD installation): never served over HTTP,
# TCPDF ships tooling scripts there.
<Directory /var/www/html/staunton/vendor>
    Require all denied
</Directory>

# Git repository of a working clone: history, local branches.
<DirectoryMatch "/\.git(/|$)">
    Require all denied
</DirectoryMatch>
```

#### Server identity

By default, Debian announces Apache's version and the system in every `Server` header, and signs its error pages with them. In `/etc/apache2/conf-available/security.conf`:

```apache
ServerTokens Prod
ServerSignature Off
```

Then `apache2ctl configtest && systemctl reload apache2`. The `Server` header now reads `Apache`, without version or system, and the error pages are no longer signed.

#### HTTPS

Serve the site over HTTPS : HSTS (`Strict-Transport-Security`) belongs to the virtual host, not to this file: it commits the whole site, not only these pages.
However, the script also works over HTTP.

### nginx

Configuration with PHP-FPM, equivalent to the Apache one. On Debian:

```
cp docs/nginx/staunton.conf /etc/nginx/sites-available/staunton
ln -s /etc/nginx/sites-available/staunton /etc/nginx/sites-enabled/staunton
nginx -t && nginx -s reload
```

On FreeBSD, include the file from the `http` block of `/usr/local/etc/nginx/nginx.conf`. In every case, adjust `server_name`, `root` and `fastcgi_pass` to your system. `server_tokens off`, in the file, already hides the version of nginx.

The PHP location hands what follows the name of the script (`generate.php/diagram.pdf`) over to PHP in `PATH_INFO`, and the content security policy of the generated page covers both of its addresses. Apache needs nothing of the kind: mod_php and php-fpm both accept such addresses.

```nginx
# Staunton under nginx, served in the /staunton/ subdirectory with PHP-FPM.
# To be placed in /etc/nginx/sites-available/ (Debian) or included from the
# http block of nginx.conf (FreeBSD: /usr/local/etc/nginx/nginx.conf).

# Content security policy. The generated HTML page gets its own, without
# any script; the PDF gets none (an empty value sends no header). Both
# maps are evaluated when the response is sent.
map $sent_http_content_type $staunton_csp_generated {
    ~^text/html "default-src 'none'; style-src 'unsafe-inline'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'";
    default     "";
}

map $uri $staunton_csp {
    ~^/staunton/generate\.php(/|$) $staunton_csp_generated;
    default "default-src 'none'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";
}

server {
    listen 80;
    server_name example.org;

    root /var/www/html;
    index index.php;

    # No directory listing (off by default, restated here), and no nginx
    # version in the headers and error pages.
    autoindex off;
    server_tokens off;

    # Declared at server level, and nowhere else: nginx stops inheriting
    # the add_header directives of an upper level as soon as a location
    # declares one.
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header Content-Security-Policy $staunton_csp always;

    # Git repository of a working clone: history, local branches.
    location ~ /\.git {
        deny all;
    }

    # PHP includes (already locked by the code) and Composer
    # dependencies: never served. ^~ short-circuits the PHP location.
    location ^~ /staunton/lib/ {
        deny all;
    }
    location ^~ /staunton/vendor/ {
        deny all;
    }

    # The fetch script holds the pinned checksums the test suite reads:
    # it stays next to the code, but is never served.
    location = /staunton/fetch-assets.sh {
        deny all;
    }

    location ~ \.php(/|$) {
        # Split the script from what follows it (generate.php/diagram.pdf),
        # and refuse scripts that do not exist rather than hand them over to
        # PHP-FPM. try_files resets $fastcgi_path_info: it is kept aside
        # first.
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        set $path_info $fastcgi_path_info;
        try_files $fastcgi_script_name =404;

        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $path_info;

        # Debian Trixie: php8.4-fpm. FreeBSD: 127.0.0.1:9000 by default.
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;

        # Location of TCPDF, if neither the Debian package nor Composer
        # will do (see the README).
        # fastcgi_param STAUNTON_TCPDF /path/to/tcpdf.php;
    }
}
```

### Checking the configuration

Give the test suite the address of the page:

```
php lib/test.php http://localhost/staunton/
```

Its last section then queries the real server:

- a path that does not exist under `lib/` must be refused by the server itself, since the PHP lock only protects the files that exist;
- directory listings must be off, and `.git`, `vendor/` and `fetch-assets.sh` refused, or absent;
- the input page and the generated page must carry the hardening headers and their content security policies, the generated page at both of its addresses;
- nothing else than the name of the file may follow `generate.php`;
- the `Server` header and the error pages must not give the version of the server away.

Both configurations were applied as they stand and checked this way: Apache 2.4.58 with mod_php 8.3, nginx 1.24 with PHP-FPM 8.3 (only `fastcgi_pass` adapted), then in Chromium, where the input page, the PGN loading and the generated page run without a single CSP violation. 

### About the content security policy

The input page contains no inline script and no `on...=` handler, and the test suite makes sure of it: the values coming from PHP travel in `data-*` attributes, and chess.js is loaded by `chess-module.js`.

`style-src-attr` belongs to CSP level 3: Chrome 75, Firefox 108 and Safari 15.4 at least. An older browser falls back on `style-src 'self'`, and the board is then displayed without dimensions.

Some browser extensions inject their own scripts or styles into every page: ad blockers, grammar checkers such as Antidote, password managers. The policy refuses them, and the browser console says so. That is the policy at work, not an error of the page, which needs none of them. The developer tools also try to fetch `chess.js.map`, named at the end of chess.js: that request is refused as well, and the file is not shipped anyway.

## 11. Test suite

```
php lib/test.php
```

A pure php-cli script, without any dependency. It returns 0 if every check passes and 1 otherwise, so it can run as is in a git hook or after any change. It covers:

- placement validation: legitimate cases, broken structures, hostile payloads (directory traversals, absolute paths, stream wrappers, shell substitution, null byte, line feed, multibyte look-alikes, HTML breakouts), the 256 possible bytes in a piece slot, the anchors and the size guard;
- consecutive digits;
- piece path resolution and its own hostile payloads;
- grid conversion, square parity, template geometry and full FEN assembly;
- the side to move read from a full FEN, with hostile second fields, and the order of precedence;
- the orientation of the diagram: flipped board or Black to move, coordinates and pieces turned by half a turn, hostile values;
- annotation cleaning: invalid UTF-8, C0 and C1 controls, format characters, no-break space, truncation in code points, size guard, no mbstring call;
- request parameters received as arrays, with no PHP warning;
- HTML output: squares, side to move, round dot, long words broken, tags escaped, no link at all;
- environment: PHP version, `ctype` and `xml`, TCPDF location, and a clear message for a missing, unsupported or unrunnable TCPDF;
- PDF output: header, single page, hostile annotation, no link annotation, no address in the metadata, consistent XMP length, no user data in the metadata, annotation height and the 194 character limit;
- asset checksums: the checksums pinned in `fetch-assets.sh` cover the sixteen files and each of them matches; on a throwaway copy, the script accepts intact files, stops on an altered SVG or an incomplete list of checksums, checks the file listed last in its openssl branch too, and only goes ahead regardless on explicit request;
- input page: title, help card, thresholds consistent between `app.js` and `style.css`, English plurals, measured layout constants;
- front-end compatibility with jQuery 4;
- include lock, on the command line and then over real HTTP through PHP's built-in server (which reads no `.htaccess`): `lib/` answers 403, the entry points are served, the side to move is read from the URL, a GET on `generate.php` gets a 405, `generate.php/diagram.html` is served and any other path after `generate.php` gets a 404, the content security policy finds no inline script;
- the configuration files shipped in `docs/` are identical to their copies in this document;
- given the address of the page, the configuration of the real web server, server identity included (see section 10).

The suite first makes sure `assets/` holds its sixteen files: if one is missing, it says so in one line and stops, since most of the output checks would fail for that reason alone.

The PHP code also passes PHPStan at its highest level. Under PHP_CodeSniffer with the PSR-12 standard, nine deliberate exceptions remain: six files that both declare functions and act (the include lock in `lib/`, the two entry points and the test suite with their own helpers), the `ChessboardPdf` class outside any namespace, its `_out()` method whose name TCPDF imposes, and the alternative `if (...):` syntax in the `index.php` template. `app.js` and `chess-module.js` pass ESLint with its recommended rules.

## 12. Supported versions

**PHP.** 8.0 or later, with `ctype` and `xml`. The test suite passes under PHP 8.0, 8.3, 8.4 and 8.5, on Linux.

**TCPDF 6.** The test suite passes with TCPDF 6.4.4, 6.6.2, 6.7.5, 6.9.1, 6.9.2, 6.10.1, 6.11.2 and 6.11.4. On Debian, `php-tcpdf` provides 6.6.2 in Bookworm and 6.9.1 in Trixie (which brings the PHP curl extension along).

**Composer.** Without a version constraint, `composer require tecnickcom/tcpdf` installs TCPDF 7, which is refused. Install `tecnickcom/tcpdf:^6.9.2`: the latest TCPDF 6, and at least the 6.9.1 and 6.9.2 security fixes listed in the TCPDF changelog. From 6.8 on, TCPDF needs the PHP curl extension.

**FreeBSD.** The default PHP version of the ports is 8.5, and the packages named in the README exist in the ports tree; TCPDF does not, hence Composer.

**Browsers.** The board width follows the height of the window, so that the page fits without a scrollbar; its constants were measured in Chromium and Firefox, for default font sizes of 16, 20 and 24 px, and follow the size chosen in the browser (see the comments of `style.css`). The content security policy relies on CSP level 3 (see section 10).
