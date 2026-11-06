<?php
/**
 * Photo Gallery / Fotogalerie – Single-File PHP + Vue 3 + Chart.js
 *
 * Voraussetzungen: PHP >= 7.4, GD (JPEG/PNG/GIF/WebP), exif-Extension (Metadaten & Drehung)
 *
 * Alle Bild- und Thumbnail-URLs entstehen ausschließlich in image_url(), thumb_url(), download_url() und icon_url().
 * Standardmäßig liefert dieses Skript die Dateien selbst aus. IMAGE_DIR und THUMB_DIR dürfen daher
 * beliebig liegen, auch außerhalb des Webroots.
 *
 * Ablauf:
 *  1. ?api=data       – schneller Ordner-Scan (ohne EXIF). Neue/geänderte Bilder werden als "pending" markiert.
 *  2. ?api=process    – der Browser lässt "pending"-Bilder paketweise verarbeiten:
 *                       EXIF lesen, Thumbnail erzeugen, Farbe/Schwarzweiß analysieren, Cache speichern.
 *  3. ?thumb= / ?img= – Auslieferung von Thumbnails und Originalen.
 *  4. ?icon= / ?manifest – Icons (Favicon, Startbildschirm) und Web-App-Manifest.
 *
 * Sprache der Oberfläche: EN (Standard) / DE – Umschalter in der Kopfleiste, ?lang=de|en, Cookie "g_lang".
 */
declare(strict_types=1);

/* ================================================================ Konfiguration */

// Sprachen der Oberfläche (die erste ist der Standard)
const LANGS          = ['en', 'de'];
const GALLERY_TITLE  = ['en' => 'Photo Gallery', 'de' => 'Fotogalerie'];
const BOOT_TEXT      = [
    'en' => ['loading' => 'Loading gallery …',      'cdn' => 'Error: Vue.js could not be loaded (CDN unreachable).'],
    'de' => ['loading' => 'Galerie wird geladen …', 'cdn' => 'Fehler: Vue.js konnte nicht geladen werden (CDN nicht erreichbar).'],
];

// Dateisystem-Pfade
const IMAGE_DIR      = __DIR__ . '/images';
const THUMB_DIR      = __DIR__ . '/thumbs';

// Optionale öffentliche URLs zur Direktauslieferung durch den Webserver (schneller).
// null = Auslieferung über dieses Skript (funktioniert immer).
// Nur setzen, wenn die URL wirklich auf IMAGE_DIR bzw. THUMB_DIR zeigt, z. B. '/galerie/images'.
const IMAGE_URL      = null;
const THUMB_URL      = null;

// Icon für Browser-Tab, Lesezeichen und Startbildschirm (Smartphone).
// Quadratisches PNG/JPG/WebP ab 512 × 512 px. Fehlt die Datei, wird ein eingebautes Icon erzeugt.
const ICON_FILE      = __DIR__ . '/icon.png';
const ICON_SIZES     = [32, 180, 192, 512];
const THEME_COLOR    = '#0f1115';

const THUMB_MAX      = 400;    // max. Kantenlänge des Thumbnails in Pixel
const THUMB_QUALITY  = 82;     // JPEG-Qualität
const MIN_IMAGE_SIZE = 0;      // Bilder mit kleinerer längster Seite ausblenden (0 = aus)
const DEBUG          = false;  // true = Diagnose unter ?debug=1 + Fehlerdetails in der API (nur zur Fehlersuche!)

// Farbanalyse (Farbe vs. Schwarzweiß)
const BW_CHROMA        = 24;   // Buntheit (max−min RGB, 0–255), ab der ein Pixel als "farbig" gilt
const BW_MAX_SHARE     = 0.03; // höchstens dieser Anteil farbiger Pixel → Schwarzweiß
const TONED_MAX_CHROMA = 48;   // getönte SW-Bilder (Sepia …): mittlere Buntheit höchstens …
const TONED_MIN_HUE    = 0.92; // … und Farbtöne mindestens so stark gebündelt (0 = beliebig, 1 = ein Farbton)

const PROCESS_MAX    = 20;     // max. Bilder pro Verarbeitungsanfrage

const IMAGE_EXT      = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const MIME_TYPES     = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
const META_CACHE     = THUMB_DIR . '/.meta-cache.json';
const META_LOCK      = THUMB_DIR . '/.meta-cache.lock';
const META_VERSION   = 3;
const JSON_FLAGS     = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

// Eingebautes Icon als SVG (Fallback ohne GD)
const DEFAULT_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4f9cf9"/><stop offset="1" stop-color="#1e40af"/></linearGradient></defs><rect width="100" height="100" fill="url(#g)"/><circle cx="64" cy="36" r="8.5" fill="#f5a524"/><path d="M20 72 42 42 55 58 64 48 80 72Z" fill="#fff"/></svg>';

ini_set('display_errors', '0');

/* ================================================================ Allgemeine Helfer */

/** Query-Parameter nur als String (Arrays wie ?dir[]=x werden ignoriert) */
function qstr(string $key, string $default = ''): string {
    $v = $_GET[$key] ?? null;
    return is_string($v) ? $v : $default;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** JSON ausgeben – nie eine leere Antwort */
function json_out(array $data): void {
    $s = json_encode($data, JSON_FLAGS);
    echo $s !== false ? $s : '{"error":"server","detail":null}';
}

/* ================================================================ Fehler & Sprache */

/** Fehler mit sprachneutralem Code (Übersetzung erfolgt im Frontend) */
final class GalleryError extends RuntimeException {
    public string $key;
    public function __construct(string $key, string $detail = '', int $http = 400) {
        parent::__construct($detail !== '' ? $detail : $key, $http);
        $this->key = $key;
    }
}

/** JSON-Fehlerantwort; technische Details nur bei DEBUG (keine Serverpfade nach außen) */
function api_fail(Throwable $e): void {
    $isG  = $e instanceof GalleryError;
    $code = $isG ? (int)$e->getCode() : 500;
    http_response_code($code >= 400 && $code < 600 ? $code : 400);
    json_out([
        'error'  => $isG ? $e->key : 'server',
        'detail' => DEBUG ? $e->getMessage() : null,
    ]);
}

/** Sprache der Oberfläche: ?lang= → Cookie → Standard (erste in LANGS) */
function ui_lang(): string {
    foreach ([$_GET['lang'] ?? null, $_COOKIE['g_lang'] ?? null] as $l) {
        if (is_string($l) && in_array(strtolower($l), LANGS, true)) return strtolower($l);
    }
    return LANGS[0];
}

/* ================================================================ Pfade & URLs */

/** Absoluter, kanonischer Pfad des Bildordners */
function base_dir(): string {
    static $base = null;
    if ($base === null) {
        $b = realpath(IMAGE_DIR);
        if ($b === false || !is_dir($b)) throw new GalleryError('dir_missing', 'Image folder not found: ' . IMAGE_DIR, 500);
        $base = $b;
    }
    return $base;
}

/** Relativen Pfad aus Benutzereingabe bereinigen (keine "..", keine versteckten Segmente) */
function clean_rel(?string $p): string {
    $out = [];
    foreach (explode('/', str_replace('\\', '/', (string)$p)) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..' || $seg[0] === '.') throw new GalleryError('invalid_path', 'Invalid path segment', 400);
        $out[] = $seg;
    }
    return implode('/', $out);
}

/** Relativen Pfad in absoluten Pfad innerhalb von IMAGE_DIR auflösen */
function resolve(string $rel): string {
    $base = base_dir();
    $full = realpath($base . ($rel === '' ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel)));
    if ($full === false || ($full !== $base && strpos($full, $base . DIRECTORY_SEPARATOR) !== 0)) {
        throw new GalleryError('not_found', 'Path not found: ' . $rel, 404);
    }
    return $full;
}

/** Absoluten Pfad (innerhalb IMAGE_DIR) in kanonischen relativen Pfad umwandeln – EINZIGE Quelle für $rel */
function rel_of(string $abs): string {
    return ltrim(str_replace('\\', '/', (string)substr($abs, strlen(base_dir()))), '/');
}

/** Locale-unabhängiges basename() (UTF-8-sicher) */
function base_name(string $path): string {
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $p = strrpos($path, '/');
    return $p === false ? $path : (string)substr($path, $p + 1);
}

/** Dateiendung in Kleinbuchstaben (Locale-unabhängig) */
function file_ext(string $path): string {
    $name = base_name($path);
    $d = strrpos($name, '.');
    return $d === false ? '' : strtolower((string)substr($name, $d + 1));
}

function is_image_file(string $name): bool {
    return in_array(file_ext($name), IMAGE_EXT, true);
}

function url_path(string $rel): string {
    return implode('/', array_map('rawurlencode', explode('/', $rel)));
}

/** Absolute URL dieses Skripts (unabhängig davon, wie die Seite aufgerufen wurde) */
function self_url(): string {
    static $u = null;
    if ($u === null) {
        $s = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $u = $s !== '' ? url_path($s) : rawurlencode(base_name(__FILE__));
    }
    return $u;
}

function join_url(string $base, string $rel): string {
    return rtrim($base, '/') . '/' . url_path($rel);
}

/** Hash über Pfad, Dateigröße, Änderungszeit und Thumbnail-Einstellungen */
function thumb_hash(string $rel, int $size, int $mtime): string {
    return sha1($rel . '|' . $size . '|' . $mtime . '|' . THUMB_MAX . '|' . THUMB_QUALITY);
}

function thumb_file(string $hash): string {
    return THUMB_DIR . '/' . $hash . '.jpg';
}

function php_image_url(string $rel): string { return self_url() . '?img=' . rawurlencode($rel); }
function php_thumb_url(string $rel, string $hash): string { return self_url() . '?thumb=' . rawurlencode($rel) . '&v=' . $hash; }

function image_url(string $rel): string {
    return IMAGE_URL !== null ? join_url((string)IMAGE_URL, $rel) : php_image_url($rel);
}

function thumb_url(string $rel, string $hash): string {
    return (THUMB_URL !== null && is_file(thumb_file($hash)))
        ? join_url((string)THUMB_URL, $hash . '.jpg')
        : php_thumb_url($rel, $hash);
}

function download_url(string $rel): string {
    return php_image_url($rel) . '&dl=1';
}

/* ================================================================ Dateien schreiben & senden */

function ensure_dir(string $dir): bool {
    return is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir);
}

/** Darf in THUMB_DIR geschrieben werden (Thumbnails, Icons, Metadaten-Cache)? */
function cache_writable(): bool {
    static $w = null;
    if ($w === null) $w = ensure_dir(THUMB_DIR) && is_writable(THUMB_DIR);
    return $w;
}

function write_atomic(string $file, string $data): bool {
    if (!ensure_dir(dirname($file))) return false;
    $tmp = $file . '.' . getmypid() . '.' . mt_rand() . '.tmp';
    if (@file_put_contents($tmp, $data) === false) return false;
    if (@rename($tmp, $file)) return true;
    @unlink($tmp);
    return is_file($file);
}

function send_file(string $file, string $mime, string $etag, int $mtime, int $maxAge, bool $immutable = false, ?string $downloadName = null): void {
    while (ob_get_level() > 0) ob_end_clean();
    $etag = '"' . $etag . '"';
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Cache-Control: public, max-age=' . $maxAge . ($immutable ? ', immutable' : ''));
    if ($downloadName !== null) {
        $ascii = (string)preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $downloadName);
        header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($downloadName));
    }
    $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    $ims = (string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
    $imsTime = $ims !== '' ? strtotime($ims) : false;
    if (($inm !== '' && strpos($inm, $etag) !== false) || ($inm === '' && $imsTime !== false && $imsTime >= $mtime)) {
        http_response_code(304);
        return;
    }
    header('Content-Length: ' . (string)filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
}

function send_bytes(string $data, string $mime, string $cache): void {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: ' . $cache);
    header('Content-Length: ' . strlen($data));
    echo $data;
}

function send_placeholder(): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(404);
    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-store');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 3"><rect width="4" height="3" fill="#2a2f3a"/></svg>';
}

/* ================================================================ EXIF / Metadaten */

function exif_num($v): ?float {
    if (is_array($v)) $v = reset($v);
    if ($v === null || $v === '' || !is_scalar($v)) return null;
    $v = (string)$v;
    if (strpos($v, '/') !== false) {
        [$a, $b] = explode('/', $v, 2);
        if (!is_numeric($a) || !is_numeric($b) || (float)$b == 0.0) return null;
        return (float)$a / (float)$b;
    }
    return is_numeric($v) ? (float)$v : null;
}

function exif_str($v): ?string {
    if (is_array($v)) $v = reset($v);
    if (!is_scalar($v)) return null;
    $v = trim(str_replace("\0", '', (string)$v));
    return $v === '' ? null : $v;
}

/** "NIKON CORPORATION" → "Nikon", "OLYMPUS IMAGING CORP." → "Olympus" */
function norm_make(?string $s): ?string {
    if ($s === null) return null;
    do {
        $prev = $s;
        $s = (string)preg_replace('/[\s,]+(corporation|corp|co|ltd|inc|company|imaging|optical|electric)\.?$/i', '', $s);
    } while ($s !== $prev && $s !== '');
    if ($s === '') return null;
    if (strlen($s) > 3 && strtoupper($s) === $s) $s = ucwords(strtolower($s));
    return $s;
}

function read_exif(string $abs): ?array {
    if (!function_exists('exif_read_data')) return null;
    $e = @exif_read_data($abs);
    return is_array($e) ? $e : null;
}

/** Leerer Metadatensatz (Struktur des Caches) */
function blank_meta(): array {
    return [
        'v' => META_VERSION, 'w' => 0, 'h' => 0,
        'make' => null, 'model' => null, 'lens' => null, 'fnumber' => null,
        'focal' => null, 'focal35' => null, 'exposure' => null, 'iso' => null, 'date' => null,
        'color' => null,   // 'color' | 'bw' | null (nicht analysiert)
        'tok'   => false,  // Thumbnail konnte erzeugt werden
    ];
}

function read_meta(string $abs): array {
    $m = blank_meta();
    $info = @getimagesize($abs);
    $m['w'] = (int)($info[0] ?? 0);
    $m['h'] = (int)($info[1] ?? 0);
    if (!$info || $info[2] !== IMAGETYPE_JPEG) return $m;
    $e = read_exif($abs);
    if ($e === null) return $m;

    $m['make']  = norm_make(exif_str($e['Make'] ?? null));
    $m['model'] = exif_str($e['Model'] ?? null);
    $m['lens']  = exif_str($e['UndefinedTag:0xA434'] ?? $e['LensModel'] ?? null);

    $f = exif_num($e['FNumber'] ?? null);
    if ($f === null && isset($e['COMPUTED']['ApertureFNumber'])) {
        $f = exif_num(str_replace('f/', '', (string)$e['COMPUTED']['ApertureFNumber']));
    }
    $m['fnumber']  = $f !== null ? round($f, 1) : null;
    $fl            = exif_num($e['FocalLength'] ?? null);
    $m['focal']    = $fl !== null ? round($fl, 1) : null;
    $f35           = exif_num($e['FocalLengthIn35mmFilm'] ?? null);
    $m['focal35']  = $f35 ? (int)$f35 : null;
    $m['exposure'] = exif_num($e['ExposureTime'] ?? null);
    $iso           = exif_num($e['ISOSpeedRatings'] ?? $e['PhotographicSensitivity'] ?? null);
    $m['iso']      = $iso ? (int)$iso : null;

    $d = exif_str($e['DateTimeOriginal'] ?? $e['DateTimeDigitized'] ?? $e['DateTime'] ?? null);
    if ($d && preg_match('/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', $d, $x) && $x[1] !== '0000') {
        $m['date'] = "$x[1]-$x[2]-$x[3] $x[4]:$x[5]:$x[6]";
    }
    $o = (int)($e['Orientation'] ?? 1);
    if ($o >= 5 && $o <= 8) { [$m['w'], $m['h']] = [$m['h'], $m['w']]; }
    return $m;
}

function load_cache(): array {
    if (!is_file(META_CACHE)) return [];
    $d = json_decode((string)@file_get_contents(META_CACHE), true);
    return is_array($d) ? $d : [];
}

/** Cache unter exklusiver Sperre lesen, ändern, schreiben ($fn gibt true zurück, wenn gespeichert werden soll) */
function update_cache(callable $fn): void {
    if (!cache_writable()) return;
    $fh = @fopen(META_LOCK, 'c');
    if ($fh) @flock($fh, LOCK_EX);
    try {
        $cache = load_cache();
        if ($fn($cache)) write_atomic(META_CACHE, (string)json_encode($cache, JSON_FLAGS));
    } finally {
        if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }
    }
}

/** Datensatz für das Frontend (feste Feldliste, keine internen Cache-Felder) */
function image_record(string $rel, array $c, bool $pending): array {
    $size  = (int)($c['size'] ?? 0);
    $mtime = (int)($c['mtime'] ?? 0);
    $hash  = thumb_hash($rel, $size, $mtime);
    $p     = strrpos($rel, '/');
    return [
        'path'     => $rel,
        'name'     => base_name($rel),
        'folder'   => $p === false ? '' : (string)substr($rel, 0, $p),
        'ext'      => file_ext($rel),
        'size'     => $size,
        'mtime'    => $mtime,
        'w'        => (int)($c['w'] ?? 0),
        'h'        => (int)($c['h'] ?? 0),
        'make'     => $c['make'] ?? null,
        'model'    => $c['model'] ?? null,
        'lens'     => $c['lens'] ?? null,
        'fnumber'  => $c['fnumber'] ?? null,
        'focal'    => $c['focal'] ?? null,
        'focal35'  => $c['focal35'] ?? null,
        'exposure' => $c['exposure'] ?? null,
        'iso'      => $c['iso'] ?? null,
        'date'     => $c['date'] ?? null,
        'color'    => $c['color'] ?? null,
        'pending'  => $pending,
        'url'      => image_url($rel),
        'urlAlt'   => IMAGE_URL !== null ? php_image_url($rel) : null,
        'thumb'    => thumb_url($rel, $hash),
        'thumbAlt' => THUMB_URL !== null ? php_thumb_url($rel, $hash) : null,
        'download' => download_url($rel),
    ];
}

/** Verwaiste Thumbnails, veraltete Icons und liegen gebliebene Temp-Dateien löschen */
function cleanup_thumbs(array $validHashes): void {
    foreach (glob(THUMB_DIR . '/*.jpg') ?: [] as $t) {
        $h = substr(base_name($t), 0, -4);
        if (preg_match('/^[0-9a-f]{40}$/', $h) && !isset($validHashes[$h])) @unlink($t);
    }
    $ver = icon_version();
    foreach (glob(THUMB_DIR . '/icon-*.png') ?: [] as $t) {
        if (preg_match('/^icon-\d+-([0-9a-f]{12})\.png$/', base_name($t), $m) && $m[1] !== $ver) @unlink($t);
    }
    $old = time() - 3600;
    foreach (array_merge(glob(THUMB_DIR . '/*.tmp') ?: [], glob(THUMB_DIR . '/.*.tmp') ?: []) as $t) {
        if ((int)@filemtime($t) < $old) @unlink($t);
    }
}

/**
 * Schneller Scan aller Bilder (rekursiv) unterhalb eines Ordners.
 * Neue/geänderte Bilder werden NICHT hier analysiert, sondern als "pending" markiert.
 */
function collect_images(string $relDir, string $absDir): array {
    $canStore = cache_writable();
    $cache    = load_cache();
    $seen     = [];
    $hashes   = [];
    $out      = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f): bool { return $f->getFilename()[0] !== '.'; }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD   // nicht lesbare Ordner überspringen statt abbrechen
    );

    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || !is_image_file($f->getFilename())) continue;
        $abs   = $f->getPathname();
        $rel   = rel_of($abs);
        $size  = (int)$f->getSize();
        $mtime = (int)$f->getMTime();
        $hash  = thumb_hash($rel, $size, $mtime);
        $seen[$rel]    = true;
        $hashes[$hash] = true;

        $c        = $cache[$rel] ?? null;
        $sameFile = is_array($c) && ($c['size'] ?? -1) === $size && ($c['mtime'] ?? -1) === $mtime;
        $pending  = false;

        if (!$sameFile || ($c['v'] ?? 0) !== META_VERSION) {
            if ($canStore) {
                // Vorläufige Daten (ggf. aus älterer Cache-Version), Verarbeitung folgt über ?api=process
                $c = $sameFile ? array_merge(blank_meta(), array_intersect_key($c, blank_meta())) : blank_meta();
                $pending = true;
            } else {
                // Kein Schreibrecht → Metadaten direkt lesen (ohne Farbanalyse), keine Hintergrundverarbeitung
                $c = read_meta($abs);
            }
            $c['size']  = $size;
            $c['mtime'] = $mtime;
        } elseif ($canStore && !empty($c['tok']) && !is_file(thumb_file($hash))) {
            $pending = true; // Thumbnail fehlt (gelöscht oder Thumbnail-Einstellungen geändert)
        }

        if (!$pending && MIN_IMAGE_SIZE > 0 && max((int)$c['w'], (int)$c['h']) < MIN_IMAGE_SIZE) continue;
        $out[] = image_record($rel, $c, $pending);
    }

    // Beim Scan des Wurzelordners: Cache bereinigen + verwaiste Dateien löschen (Basis: Datei-Scan)
    if ($relDir === '' && $canStore) {
        if (array_diff_key($cache, $seen)) {
            update_cache(static function (array &$c) use ($seen): bool {
                $before = count($c);
                $c = array_intersect_key($c, $seen);
                return count($c) !== $before;
            });
        }
        cleanup_thumbs($hashes);
    }

    usort($out, static function (array $a, array $b): int { return strnatcasecmp($a['path'], $b['path']); });
    return $out;
}

/* ================================================================ Bildverarbeitung (GD) */

function gd_available(): bool {
    return function_exists('imagecreatetruecolor') && function_exists('imagepng');
}

/** GD-Speicher freigeben (ab PHP 8 automatisch; imagedestroy ist ab 8.5 veraltet) */
function gd_free($img): void {
    if (PHP_VERSION_ID < 80000 && $img) imagedestroy($img);
}

/** Bilddatei mit GD öffnen (oder false) */
function gd_open(string $src, int $type) {
    switch ($type) {
        case IMAGETYPE_JPEG: return @imagecreatefromjpeg($src);
        case IMAGETYPE_PNG:  return @imagecreatefrompng($src);
        case IMAGETYPE_GIF:  return @imagecreatefromgif($src);
        case IMAGETYPE_WEBP: return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false;
    }
    return false;
}

/** imagefilledpolygon() – Signatur unterscheidet sich zwischen PHP 7 und PHP 8 */
function gd_polygon($img, array $points, int $color): void {
    if (PHP_VERSION_ID >= 80000) imagefilledpolygon($img, $points, $color);
    else imagefilledpolygon($img, $points, intdiv(count($points), 2), $color);
}

function ini_bytes(string $v): int {
    $v = trim($v);
    if ($v === '' || $v === '-1') return -1;
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024; // no break
        case 'm': $n *= 1024; // no break
        case 'k': $n *= 1024;
    }
    return $n;
}

/** Grobe Abschätzung, ob GD das Bild im verfügbaren Speicher dekodieren kann */
function memory_ok(int $w, int $h): bool {
    $limit = ini_bytes((string)ini_get('memory_limit'));
    if ($limit <= 0) return true;
    $need = (int)($w * $h * 5 + THUMB_MAX * THUMB_MAX * 10 + 8 * 1024 * 1024);
    return memory_get_usage() + $need < $limit;
}

function gd_rotate($img, int $angle) {
    $r = imagerotate($img, $angle, 0);
    if ($r === false) return $img;
    gd_free($img);
    return $r;
}

function apply_orientation($img, int $o) {
    switch ($o) {
        case 2: imageflip($img, IMG_FLIP_HORIZONTAL); break;
        case 3: $img = gd_rotate($img, 180); break;
        case 4: imageflip($img, IMG_FLIP_VERTICAL); break;
        case 5: imageflip($img, IMG_FLIP_HORIZONTAL); $img = gd_rotate($img, 90); break;
        case 6: $img = gd_rotate($img, -90); break;
        case 7: imageflip($img, IMG_FLIP_HORIZONTAL); $img = gd_rotate($img, -90); break;
        case 8: $img = gd_rotate($img, 90); break;
    }
    return $img;
}

/** Thumbnail als GD-Bild erzeugen (korrekt gedreht) oder null */
function build_thumb(string $src) {
    $info = @getimagesize($src);
    if (!$info) return null;
    [$w, $h, $type] = $info;
    @ini_set('memory_limit', '1024M');
    @set_time_limit(120);
    if (!memory_ok((int)$w, (int)$h)) return null;

    $img = gd_open($src, (int)$type);
    if (!$img) return null;

    $orientation = 1;
    if ($type === IMAGETYPE_JPEG) {
        $e = read_exif($src);
        $orientation = (int)($e['Orientation'] ?? 1);
    }

    $scale = min(1, THUMB_MAX / max($w, $h));
    $tw = max(1, (int)round($w * $scale));
    $th = max(1, (int)round($h * $scale));

    $thumb = imagecreatetruecolor($tw, $th);
    if (!$thumb) { gd_free($img); return null; }
    imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255)); // Transparenz → weiß
    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, (int)$w, (int)$h);
    gd_free($img);
    return apply_orientation($thumb, $orientation);
}

function encode_jpeg($img): ?string {
    ob_start();
    $ok   = imagejpeg($img, null, THUMB_QUALITY);
    $data = ob_get_clean();
    return ($ok && is_string($data) && $data !== '') ? $data : null;
}

/**
 * Farbbild oder Schwarzweiß? Analyse auf dem (Truecolor-)Thumbnail.
 *  - Buntheit je Pixel = max(R,G,B) − min(R,G,B)
 *  - kaum farbige Pixel                                 → 'bw'
 *  - farbige Pixel mit einheitlichem, schwachem Farbton → getöntes SW (Sepia …) → 'bw'
 *  - sonst                                              → 'color'
 */
function analyse_color($img): ?string {
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 1 || $h < 1) return null;
    $step = max(1, (int)floor(sqrt($w * $h / 20000)));   // ~20.000 Stichproben

    $n = 0; $colorful = 0; $strong = 0; $chromaSum = 0; $sx = 0.0; $sy = 0.0;
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            $c = (int)imagecolorat($img, $x, $y);
            $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
            $max = max($r, $g, $b);
            $ch  = $max - min($r, $g, $b);
            $n++;
            if ($ch < BW_CHROMA) continue;
            $colorful++;
            $chromaSum += $ch;
            if ($ch >= 2 * TONED_MAX_CHROMA) $strong++;
            if ($max === $r)     $hue = ($g - $b) / $ch;
            elseif ($max === $g) $hue = ($b - $r) / $ch + 2;
            else                 $hue = ($r - $g) / $ch + 4;
            $a = $hue * M_PI / 3;           // Farbton als Winkel
            $sx += cos($a);
            $sy += sin($a);
        }
    }
    if ($n === 0) return null;
    if ($colorful / $n <= BW_MAX_SHARE) return 'bw';

    $concentration = sqrt($sx * $sx + $sy * $sy) / $colorful;   // 1 = ein einziger Farbton
    if ($concentration >= TONED_MIN_HUE && $chromaSum / $colorful <= TONED_MAX_CHROMA && $strong / $n <= 0.01) {
        return 'bw';
    }
    return 'color';
}

/** Ein Bild vollständig verarbeiten: Metadaten + Thumbnail + Farbanalyse */
function process_image(string $abs, string $rel, int $size, int $mtime): array {
    $m    = read_meta($abs);
    $file = thumb_file(thumb_hash($rel, $size, $mtime));
    $img  = is_file($file) ? @imagecreatefromjpeg($file) : false;   // vorhandenes Thumbnail wiederverwenden
    $tok  = (bool)$img;
    if (!$img) {
        $img = build_thumb($abs);
        if ($img) {
            $data = encode_jpeg($img);
            $tok  = $data !== null && write_atomic($file, $data);
        }
    }
    $m['color'] = $img ? analyse_color($img) : null;
    if ($img) gd_free($img);
    $m['tok']   = $tok;
    $m['size']  = $size;
    $m['mtime'] = $mtime;
    return $m;
}

/* ================================================================ Icons (Favicon, Startbildschirm) */

/** Eigene Icon-Datei (falls vorhanden und lesbar) */
function icon_source(): ?string {
    static $s = false;
    if ($s === false) {
        $s = (ICON_FILE !== '' && is_file(ICON_FILE) && @getimagesize(ICON_FILE)) ? ICON_FILE : null;
    }
    return $s;
}

/** Version des Icons (ändert sich mit der Icon-Datei → Cache-Busting) */
function icon_version(): string {
    static $v = null;
    if ($v === null) {
        $src = icon_source();
        $id  = $src !== null ? $src . '|' . (int)@filesize($src) . '|' . (int)@filemtime($src) : 'builtin-1';
        $v   = (string)substr(sha1($id . '|' . (gd_available() ? 'png' : 'svg')), 0, 12);
    }
    return $v;
}

function icon_url(int $size): string {
    return self_url() . '?icon=' . $size . '&v=' . icon_version();
}

/** Eingebautes Icon: blauer Verlauf, Sonne, Berge (Motiv innerhalb der "maskable" Safe-Zone) */
function draw_default_icon(int $s) {
    $im  = imagecreatetruecolor($s, $s);
    $top = [0x4f, 0x9c, 0xf9];
    $bot = [0x1e, 0x40, 0xaf];
    for ($y = 0; $y < $s; $y++) {
        $t = $y / max(1, $s - 1);
        $c = imagecolorallocate($im,
            (int)round($top[0] + ($bot[0] - $top[0]) * $t),
            (int)round($top[1] + ($bot[1] - $top[1]) * $t),
            (int)round($top[2] + ($bot[2] - $top[2]) * $t));
        imageline($im, 0, $y, $s - 1, $y, $c);
    }
    $p = static function (float $v) use ($s): int { return (int)round($v * $s); };
    imagefilledellipse($im, $p(.64), $p(.36), $p(.17), $p(.17), imagecolorallocate($im, 0xf5, 0xa5, 0x24));
    gd_polygon($im, [$p(.20), $p(.72), $p(.42), $p(.42), $p(.55), $p(.58), $p(.64), $p(.48), $p(.80), $p(.72)],
        imagecolorallocate($im, 255, 255, 255));
    return $im;
}

/** Icon in gewünschter Größe als PNG-Bytes (oder null ohne GD / bei Fehler) */
function render_icon(int $size): ?string {
    if (!gd_available()) return null;
    @ini_set('memory_limit', '1024M');
    $out = imagecreatetruecolor($size, $size);
    if (!$out) return null;

    $src = icon_source();
    if ($src !== null) {
        $info = @getimagesize($src);
        if (!$info || !memory_ok((int)$info[0], (int)$info[1])) return null;
        $img = gd_open($src, (int)$info[2]);
        if (!$img) return null;
        imagealphablending($out, false);                 // Transparenz des Originals erhalten
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        $w = (int)$info[0]; $h = (int)$info[1]; $side = min($w, $h);
        imagecopyresampled($out, $img, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);
        gd_free($img);
        if ((int)$info[2] === IMAGETYPE_JPEG) {
            $e = read_exif($src);
            $out = apply_orientation($out, (int)($e['Orientation'] ?? 1));
        }
    } else {
        $big = draw_default_icon(1024);                  // groß zeichnen, verkleinern → glatte Kanten
        imagecopyresampled($out, $big, 0, 0, 0, 0, $size, $size, 1024, 1024);
        gd_free($big);
    }

    ob_start();
    $ok   = imagepng($out, null, 9);
    $data = ob_get_clean();
    gd_free($out);
    return ($ok && is_string($data) && $data !== '') ? $data : null;
}

/* ================================================================ Routing */

// --- Diagnose (nur wenn DEBUG = true) ---
if (DEBUG && isset($_GET['debug'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $cache = load_cache();
    $rows = [
        'Script file'         => __FILE__,
        'SCRIPT_NAME'         => (string)($_SERVER['SCRIPT_NAME'] ?? '-'),
        'REQUEST_URI'         => (string)($_SERVER['REQUEST_URI'] ?? '-'),
        'self_url()'          => self_url(),
        'UI language'         => ui_lang(),
        'IMAGE_DIR'           => IMAGE_DIR,
        'realpath(IMAGE_DIR)' => var_export(realpath(IMAGE_DIR), true),
        'THUMB_DIR'           => THUMB_DIR,
        'thumbs writable'     => var_export(cache_writable(), true),
        'Cache entries'       => (string)count($cache),
        'IMAGE_URL'           => var_export(IMAGE_URL, true),
        'THUMB_URL'           => var_export(THUMB_URL, true),
        'Icon'                => icon_source() ?? '(built-in)',
        'GD / WebP / EXIF'    => var_export(extension_loaded('gd'), true) . ' / '
                               . var_export(function_exists('imagecreatefromwebp'), true) . ' / '
                               . var_export(function_exists('exif_read_data'), true),
        'memory_limit'        => (string)ini_get('memory_limit'),
        'PHP'                 => PHP_VERSION,
    ];
    foreach ($rows as $k => $v) printf("%-22s: %s\n", $k, $v);

    $stats = []; $sample = null;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_dir(), FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) {
            $ext = file_ext($f->getFilename()) ?: '(none)';
            $stats[$ext] = ($stats[$ext] ?? 0) + 1;
            if ($sample === null && is_image_file($f->getFilename())) $sample = rel_of($f->getPathname());
        }
    } catch (Throwable $e) { echo "\nERROR: " . $e->getMessage() . "\n"; }
    arsort($stats);
    echo "\nFile types in IMAGE_DIR (recursive):\n";
    foreach ($stats as $ext => $n) printf("  %-8s %6d%s\n", $ext, $n, is_image_file("x.$ext") ? '' : '   <- ignored');
    if ($sample !== null) {
        echo "\nExample URLs for \"$sample\":\n";
        echo "  Original : " . image_url($sample) . "\n";
        echo "  Thumbnail: " . php_thumb_url($sample, 'HASH') . "\n";
        $c = $cache[$sample] ?? null;
        echo "  Color    : " . var_export(is_array($c) ? ($c['color'] ?? null) : 'not cached', true) . "\n";
    }
    exit;
}

// --- Web-App-Manifest (Name, Farben, Icons für den Startbildschirm) ---
if (isset($_GET['manifest'])) {
    $lang   = ui_lang();
    $title  = GALLERY_TITLE[$lang] ?? (string)reset(GALLERY_TITLE);
    $self   = self_url();
    $slash  = strrpos($self, '/');
    $scope  = $slash === false ? '/' : (string)substr($self, 0, $slash + 1);
    $png    = gd_available();
    $icons  = [];
    foreach ([192, 512] as $s) {
        $icons[] = ['src' => icon_url($s), 'sizes' => $png ? "{$s}x{$s}" : 'any', 'type' => $png ? 'image/png' : 'image/svg+xml', 'purpose' => 'any'];
    }
    if (icon_source() === null) {   // eingebautes Icon hat eine Safe-Zone → auch als "maskable" nutzbar
        $icons[] = ['src' => icon_url(512), 'sizes' => $png ? '512x512' : 'any', 'type' => $png ? 'image/png' : 'image/svg+xml', 'purpose' => 'maskable'];
    }
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    json_out([
        'id'               => $self . '?dir=',
        'name'             => $title,
        'short_name'       => $title,
        'lang'             => $lang,
        'start_url'        => $self . '?dir=',
        'scope'            => $scope,
        'display'          => 'standalone',
        'background_color' => THEME_COLOR,
        'theme_color'      => THEME_COLOR,
        'icons'            => $icons,
    ]);
    exit;
}

// --- Icon ausliefern (PNG; ohne GD als SVG) ---
if (isset($_GET['icon'])) {
    $size = (int)qstr('icon');
    if (!in_array($size, ICON_SIZES, true)) $size = 192;
    $ver       = icon_version();
    $immutable = qstr('v') === $ver;
    $maxAge    = $immutable ? 31536000 : 86400;
    $file      = THUMB_DIR . '/icon-' . $size . '-' . $ver . '.png';
    try {
        if (!is_file($file)) {
            $data = render_icon($size);
            if ($data === null) {
                send_bytes(DEFAULT_ICON_SVG, 'image/svg+xml', 'public, max-age=3600');
                exit;
            }
            if (!write_atomic($file, $data)) {   // THUMB_DIR nicht beschreibbar → direkt senden
                send_bytes($data, 'image/png', 'public, max-age=3600');
                exit;
            }
        }
        send_file($file, 'image/png', 'icon-' . $size . '-' . $ver, (int)filemtime($file), $maxAge, $immutable);
    } catch (Throwable $e) {
        send_bytes(DEFAULT_ICON_SVG, 'image/svg+xml', 'no-store');
    }
    exit;
}

// --- Original ausliefern ---
if (isset($_GET['img'])) {
    try {
        $abs = resolve(clean_rel(qstr('img')));
        if (!is_file($abs) || !is_image_file($abs)) throw new GalleryError('not_found', 'Not an image', 404);
        @set_time_limit(0);
        $size  = (int)filesize($abs);
        $mtime = (int)filemtime($abs);
        $etag  = sha1(rel_of($abs) . '|' . $size . '|' . $mtime);
        send_file($abs, MIME_TYPES[file_ext($abs)], $etag, $mtime, 86400, false, isset($_GET['dl']) ? base_name($abs) : null);
    } catch (Throwable $e) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Image not found';
    }
    exit;
}

// --- Thumbnail erzeugen / ausliefern ---
if (isset($_GET['thumb'])) {
    try {
        $abs = resolve(clean_rel(qstr('thumb')));
        if (!is_file($abs) || !is_image_file($abs)) throw new GalleryError('not_found', 'Not an image', 404);
        $rel       = rel_of($abs);
        $hash      = thumb_hash($rel, (int)filesize($abs), (int)filemtime($abs));
        $file      = thumb_file($hash);
        $immutable = qstr('v') === $hash;

        if (!is_file($file)) {
            $img = build_thumb($abs);
            if (!$img) throw new RuntimeException('Thumbnail failed');
            $data = encode_jpeg($img);
            gd_free($img);
            if ($data === null) throw new RuntimeException('Thumbnail failed');
            if (!write_atomic($file, $data)) {
                // THUMB_DIR nicht beschreibbar → Thumbnail direkt aus dem Speicher senden
                send_bytes($data, 'image/jpeg', 'no-cache');
                exit;
            }
        }
        send_file($file, 'image/jpeg', $hash, (int)filemtime($file), $immutable ? 31536000 : 3600, $immutable);
    } catch (Throwable $e) {
        send_placeholder();
    }
    exit;
}

// --- JSON-API: Ordnerinhalt ---
if (qstr('api') === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    @set_time_limit(0);
    try {
        $abs = resolve(clean_rel(qstr('dir')));
        if (!is_dir($abs)) throw new GalleryError('not_found', 'Not a folder', 404);
        $rel = rel_of($abs);

        $images  = collect_images($rel, $abs);
        $folders = [];
        foreach (scandir($abs) ?: [] as $name) {
            if ($name[0] === '.' || !is_dir($abs . DIRECTORY_SEPARATOR . $name)) continue;
            $path   = ($rel === '' ? '' : "$rel/") . $name;
            $prefix = $path . '/';
            $count  = 0; $cover = null; $coverAlt = null;
            foreach ($images as $img) {
                if (strncmp($img['path'], $prefix, strlen($prefix)) === 0) {
                    $count++;
                    if ($cover === null) { $cover = $img['thumb']; $coverAlt = $img['thumbAlt']; }
                }
            }
            $folders[] = ['name' => $name, 'path' => $path, 'count' => $count, 'cover' => $cover, 'coverAlt' => $coverAlt];
        }
        usort($folders, static function (array $a, array $b): int { return strnatcasecmp($a['name'], $b['name']); });

        json_out(['dir' => $rel, 'canStore' => cache_writable(), 'folders' => $folders, 'images' => $images]);
    } catch (Throwable $e) {
        api_fail($e);
    }
    exit;
}

// --- JSON-API: Bilder verarbeiten (Metadaten, Thumbnail, Farbanalyse) ---
if (qstr('api') === 'process') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        // Nur POST mit eigenem Header (erzwingt bei fremden Seiten einen CORS-Preflight → kein CSRF)
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_GALLERY'] ?? '') !== '1') {
            throw new GalleryError('bad_request', 'POST with X-Gallery header required', 405);
        }
        if (!cache_writable()) throw new GalleryError('not_writable', 'THUMB_DIR not writable', 500);
        @set_time_limit(300);
        ignore_user_abort(true);   // angefangenes Paket auch bei Seitenwechsel sauber abschließen

        $in    = json_decode((string)file_get_contents('php://input'), true);
        $paths = (is_array($in) && is_array($in['paths'] ?? null))
            ? array_slice(array_values(array_filter($in['paths'], 'is_string')), 0, PROCESS_MAX)
            : [];

        $updates = []; $removed = []; $failed = [];
        foreach ($paths as $p) {
            try {
                $abs = resolve(clean_rel($p));
            } catch (Throwable $e) {
                $removed[] = $p;
                continue;
            }
            if (!is_file($abs) || !is_image_file($abs)) { $removed[] = $p; continue; }
            try {
                $rel = rel_of($abs);
                $updates[$rel] = process_image($abs, $rel, (int)filesize($abs), (int)filemtime($abs));
            } catch (Throwable $e) {
                $failed[] = $p;
            }
        }

        if ($updates) {
            update_cache(static function (array &$c) use ($updates): bool {
                foreach ($updates as $k => $v) $c[$k] = $v;
                return true;
            });
        }

        $records = [];
        foreach ($updates as $rel => $c) {
            $rel = (string)$rel;
            if (MIN_IMAGE_SIZE > 0 && max((int)$c['w'], (int)$c['h']) < MIN_IMAGE_SIZE) { $removed[] = $rel; continue; }
            $records[] = image_record($rel, $c, false);
        }
        json_out(['images' => $records, 'removed' => $removed, 'failed' => $failed]);
    } catch (Throwable $e) {
        api_fail($e);
    }
    exit;
}

try { $initialDir = clean_rel(qstr('dir')); } catch (Throwable $e) { $initialDir = ''; }
$lang     = ui_lang();
$title    = GALLERY_TITLE[$lang];
$iconType = gd_available() ? 'image/png' : 'image/svg+xml';
?><!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= h(THEME_COLOR) ?>">
<meta name="application-name" content="<?= h($title) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h($title) ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="icon" type="<?= h($iconType) ?>" sizes="32x32" href="<?= h(icon_url(32)) ?>">
<link rel="icon" type="<?= h($iconType) ?>" sizes="192x192" href="<?= h(icon_url(192)) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= h(icon_url(180)) ?>">
<link rel="manifest" href="<?= h(self_url() . '?manifest=1&lang=' . $lang) ?>">
<title><?= h($title) ?></title>
<style>
:root{--bg:#0f1115;--panel:#171a21;--panel2:#1f232c;--line:#2a2f3a;--text:#e6e8ec;--muted:#9aa1ad;--accent:#4f9cf9;--accent2:#f5a524;--r:10px}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--text);font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-text-size-adjust:100%}
[v-cloak]{display:none}
a{color:inherit;text-decoration:none}
button,select,input{font:inherit;color:inherit}
/* Spinner & Ladeanimationen */
.spinner{display:inline-block;width:40px;height:40px;border:3px solid rgba(255,255,255,.12);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite;vertical-align:middle}
.spinner.md{width:26px;height:26px}
.spinner.sm{width:14px;height:14px;border-width:2px}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes shimmer{to{transform:translateX(100%)}}
.boot{position:fixed;inset:0;display:grid;place-content:center;justify-items:center;gap:14px;color:var(--muted);text-align:center;padding:20px}
.boot.err{color:#ff6b6b}
/* Kopfleiste */
.topbar{position:sticky;top:0;z-index:20;display:flex;align-items:center;gap:12px;padding:10px 16px;padding-top:max(10px,env(safe-area-inset-top));background:rgba(15,17,21,.92);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
.brand{flex:1;min-width:0;font-weight:700;font-size:1.1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tabs{display:flex;background:var(--panel2);border-radius:999px;padding:3px}
.tabs button{border:0;background:none;padding:6px 14px;border-radius:999px;cursor:pointer;color:var(--muted)}
.tabs button.active{background:var(--accent);color:#fff}
.lang{display:flex;border:1px solid var(--line);border-radius:8px;overflow:hidden;flex:none}
.lang button{border:0;background:var(--panel2);padding:6px 9px;cursor:pointer;color:var(--muted);font-size:.78rem;font-weight:700;letter-spacing:.03em}
.lang button+button{border-left:1px solid var(--line)}
.lang button.active{background:var(--accent);color:#fff}
.btn{border:1px solid var(--line);background:var(--panel2);padding:7px 12px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
.btn:hover{border-color:var(--accent)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.badge{background:var(--accent2);color:#111;border-radius:999px;font-size:.75rem;padding:0 7px;font-weight:700}
.proc-pill{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--panel2);border-radius:999px;padding:4px 10px;font-size:.8rem;color:var(--muted);cursor:pointer;white-space:nowrap;font-variant-numeric:tabular-nums}
.proc-pill:hover{border-color:var(--accent)}
/* Toolbar */
.toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px 16px;padding:12px 16px}
.crumbs{display:flex;flex-wrap:wrap;align-items:center;gap:4px}
.crumbs a{padding:2px 8px;border-radius:6px;background:var(--panel)}
.crumbs a.cur{background:var(--accent);color:#fff}
.crumbs .sep{color:var(--muted)}
.tools{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
select{background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:6px 8px}
.switch{display:inline-flex;gap:6px;align-items:center;cursor:pointer;color:var(--muted);user-select:none}
.switch input{accent-color:var(--accent);width:18px;height:18px;margin:0}
.chips{display:flex;flex-wrap:wrap;gap:6px;padding:0 16px 10px}
.chip{border:1px solid var(--accent2);color:var(--accent2);background:transparent;border-radius:999px;padding:3px 10px;font-size:.85rem;cursor:pointer}
.chip.clear{border-color:var(--line);color:var(--muted)}
main{padding:0 16px 40px}
.state{padding:60px 0;text-align:center;color:var(--muted)}
.state .spinner{display:block;margin:0 auto 14px}
.state.error{color:#ff6b6b}
/* Ordner */
.folders{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin-bottom:20px}
.folder{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);overflow:hidden;transition:transform .15s,border-color .15s}
.folder:hover{transform:translateY(-2px);border-color:var(--accent)}
.folder .cover{position:relative;overflow:hidden;aspect-ratio:16/10;background:var(--panel2);display:grid;place-items:center;font-size:2.4rem}
.folder .cover img{position:absolute;inset:0;z-index:1;width:100%;height:100%;object-fit:cover;display:block;opacity:0;transition:opacity .3s}
.folder .meta{padding:8px 10px;display:flex;flex-direction:column;min-width:0}
.folder strong{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.folder small,.count{color:var(--muted)}
.count{margin:0 0 10px;font-size:.9rem}
/* Bilderraster */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px}
.tile{position:relative;aspect-ratio:1;overflow:hidden;border-radius:8px;background:var(--panel2);display:block}
.tile img{position:relative;z-index:1;width:100%;height:100%;object-fit:cover;display:block;opacity:0;transition:opacity .3s,transform .3s}
.tile:hover img{transform:scale(1.05)}
.tile img.ok,.folder .cover img.ok{opacity:1}
.tile::before,.folder .cover.has-img::before{content:'';position:absolute;inset:0;background:linear-gradient(100deg,transparent 20%,rgba(255,255,255,.07) 50%,transparent 80%);transform:translateX(-100%);animation:shimmer 1.3s ease-in-out infinite}
.tile:has(> img.ok)::before,.folder .cover:has(> img.ok)::before{display:none}
.tile-wait{position:absolute;inset:0;z-index:1;display:grid;place-items:center}
.tile .cap{position:absolute;z-index:2;left:0;right:0;bottom:0;padding:18px 8px 6px;font-size:.78rem;background:linear-gradient(transparent,rgba(0,0,0,.75));opacity:0;transition:opacity .2s;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tile:hover .cap,.tile:focus .cap{opacity:1}
@media (hover:none){.tile .cap{display:none}}
/* Statistik */
.hint{color:var(--muted);font-size:.9rem;margin:0 0 12px}
.hint .btn{margin-left:6px;font-size:.85rem;padding:4px 10px}
.hint .live,.hint.warn{color:var(--accent2)}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:12px 14px}
.card b{display:block;font-size:1.3rem}
.card span{color:var(--muted);font-size:.85rem}
.charts{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,420px),1fr));gap:14px}
.chart-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:12px 14px;min-width:0}
.chart-card h3{margin:0 0 8px;font-size:1rem;display:flex;justify-content:space-between;gap:8px;align-items:baseline}
.chart-card h3 small{color:var(--muted);font-weight:400;font-size:.8rem}
.chart-box{position:relative;width:100%}
/* Verarbeitungs-Overlay */
.proc{position:fixed;inset:0;z-index:80;background:rgba(15,17,21,.88);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);display:grid;place-items:center;padding:20px}
.proc-box{width:min(420px,100%);background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:26px 22px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.proc-box .spinner{margin-bottom:14px}
.proc-box h2{margin:0 0 4px;font-size:1.15rem}
.proc-box p{margin:0 0 14px;color:var(--muted);font-size:.9rem}
.proc-box .proc-num{font-variant-numeric:tabular-nums;color:var(--text)}
.bar{height:8px;background:var(--panel2);border-radius:99px;overflow:hidden;margin:0 0 10px}
.bar i{display:block;height:100%;background:linear-gradient(90deg,var(--accent),#7cc0ff);border-radius:99px;transition:width .3s}
/* Filter-Drawer */
.backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:40}
.drawer{position:fixed;top:0;right:0;bottom:0;width:min(380px,100%);background:var(--panel);border-left:1px solid var(--line);z-index:41;display:flex;flex-direction:column;transform:translateX(100%);visibility:hidden;transition:transform .25s ease,visibility 0s linear .25s}
.drawer.open{transform:none;visibility:visible;transition-delay:0s}
.drawer-head{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;padding-top:max(14px,env(safe-area-inset-top));border-bottom:1px solid var(--line)}
.drawer-head h2{margin:0;font-size:1.15rem}
.drawer-body{flex:1;overflow:auto;padding:8px 16px;-webkit-overflow-scrolling:touch}
.drawer details{border-bottom:1px solid var(--line)}
.drawer summary{cursor:pointer;padding:12px 0;font-weight:600;display:flex;align-items:center;gap:8px;list-style:none}
.drawer summary::-webkit-details-marker{display:none}
.drawer summary .lbl{flex:1}
.drawer summary::after{content:'▾';color:var(--muted)}
.drawer details[open] summary::after{content:'▴'}
.opts{list-style:none;margin:0;padding:0 0 8px;max-height:260px;overflow:auto}
.opts label{display:flex;align-items:center;gap:10px;padding:7px 2px;cursor:pointer}
.opts input{accent-color:var(--accent);width:18px;height:18px;flex:none;margin:0}
.opts span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.opts em{font-style:normal;color:var(--muted);font-size:.85rem}
.opts li.zero{opacity:.45}
.drawer-foot{display:flex;gap:8px;padding:12px 16px calc(12px + env(safe-area-inset-bottom));border-top:1px solid var(--line)}
.drawer-foot .btn{flex:1;justify-content:center}
/* Lightbox */
.lightbox{position:fixed;inset:0;z-index:60;background:rgba(5,6,8,.97);display:flex;flex-direction:column}
.lb-top{display:flex;align-items:center;gap:8px;padding:8px 12px;padding-top:max(8px,env(safe-area-inset-top))}
.lb-title{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lb-count{color:var(--muted);font-size:.85rem;white-space:nowrap}
.icon-btn{width:40px;height:40px;border-radius:50%;border:0;background:rgba(255,255,255,.08);display:inline-grid;place-items:center;cursor:pointer;font-size:1.1rem;flex:none}
.icon-btn:hover,.icon-btn.on{background:rgba(255,255,255,.2)}
.lb-body{flex:1;display:flex;min-height:0}
.lb-stage{flex:1;position:relative;min-width:0;min-height:0;touch-action:pan-y pinch-zoom}
.lb-stage img{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;padding:8px}
.lb-ph{filter:blur(8px)}
.lb-full{opacity:0;transition:opacity .25s;cursor:zoom-in}
.lb-full.loaded{opacity:1}
.lb-spin{position:absolute;inset:0;z-index:1;display:grid;place-items:center;pointer-events:none}
.nav{position:absolute;top:50%;transform:translateY(-50%);width:48px;height:72px;border:0;background:rgba(0,0,0,.35);color:#fff;font-size:2rem;cursor:pointer;border-radius:8px;z-index:2}
.nav:hover{background:rgba(0,0,0,.6)}
.nav.prev{left:8px}.nav.next{right:8px}
.lb-info{width:320px;flex:none;overflow:auto;background:var(--panel);border-left:1px solid var(--line);padding:14px 16px}
.lb-info dl{display:grid;grid-template-columns:auto 1fr;gap:6px 12px;margin:0;font-size:.9rem}
.lb-info dt{color:var(--muted)}
.lb-info dd{margin:0;word-break:break-word}
/* Vollbildmodus */
.lightbox.fs{background:#000}
.lightbox:fullscreen{background:#000}
.lightbox::backdrop{background:#000}
.lightbox.fs .lb-stage img{padding:0}
.lightbox.fs .lb-full{cursor:zoom-out}
.lightbox.fs.playing .lb-full{cursor:pointer}
.lightbox.fs .nav{opacity:0;transition:opacity .2s}
.lightbox.fs .lb-stage:hover .nav,.lightbox.fs .nav:focus-visible{opacity:1}
.fs-meta{position:absolute;z-index:3;left:max(12px,env(safe-area-inset-left));bottom:max(12px,env(safe-area-inset-bottom));max-width:calc(100% - 24px);display:flex;flex-wrap:wrap;gap:2px 12px;padding:5px 10px;border-radius:6px;background:rgba(0,0,0,.5);color:rgba(255,255,255,.88);font-size:.72rem;line-height:1.5;pointer-events:none;font-variant-numeric:tabular-nums;text-shadow:0 1px 2px rgba(0,0,0,.6)}
/* Diashow-Steuerung (oben mittig) */
.fs-top{position:absolute;z-index:4;top:max(14px,env(safe-area-inset-top));left:50%;transform:translateX(-50%);display:flex;justify-content:center;max-width:calc(100% - 24px)}
.fs-btn{display:inline-flex;align-items:center;gap:8px;border:1px solid rgba(255,255,255,.25);background:rgba(0,0,0,.55);color:#fff;padding:8px 18px;border-radius:999px;cursor:pointer;font-size:.9rem;white-space:nowrap;backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
.fs-btn:hover{background:rgba(0,0,0,.78)}
.fs-durs{display:flex;gap:4px;padding:4px;border-radius:999px;background:rgba(0,0,0,.55);border:1px solid rgba(255,255,255,.2);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
.fs-durs button{border:0;background:transparent;color:rgba(255,255,255,.85);padding:6px 12px;border-radius:999px;cursor:pointer;font-size:.85rem;white-space:nowrap;font-variant-numeric:tabular-nums}
.fs-durs button:hover{background:rgba(255,255,255,.15)}
.fs-durs button.active{background:var(--accent);color:#fff}
.fade-enter-active,.fade-leave-active{transition:opacity .6s ease}
.fade-enter-from,.fade-leave-to{opacity:0}
@media (hover:none){.nav{display:none}}
@media (max-width:800px){
  .lb-body{flex-direction:column}
  .lb-info{width:auto;max-height:42vh;border-left:0;border-top:1px solid var(--line);padding-bottom:calc(14px + env(safe-area-inset-bottom))}
}
@media (max-width:600px){
  .topbar{padding-left:10px;padding-right:10px;gap:8px}
  .tabs button{padding:6px 10px}
  .lang button{padding:6px 7px}
  .btn-label,.pill-label{display:none}
  .toolbar{padding:10px 8px}
  .chips{padding:0 8px 8px}
  main{padding:0 8px 30px}
  .grid{grid-template-columns:repeat(3,1fr);gap:4px}
  .tile{border-radius:4px}
  .folders{grid-template-columns:repeat(2,1fr);gap:8px}
  .fs-durs button{padding:6px 9px}
}
@media (max-width:380px){.grid{grid-template-columns:repeat(2,1fr)}}
@media (prefers-reduced-motion:reduce){
  .spinner{animation-duration:2.4s}
  .tile::before,.folder .cover.has-img::before{animation:none}
  .tile img,.lb-full,.bar i{transition:none}
  .fade-enter-active,.fade-leave-active{transition-duration:.01s}
}
</style>
</head>
<body>
<div id="boot" class="boot" data-err="<?= h(BOOT_TEXT[$lang]['cdn']) ?>">
  <span class="spinner"></span><span><?= h(BOOT_TEXT[$lang]['loading']) ?></span>
</div>

<div id="app" v-cloak>

  <header class="topbar">
    <a class="brand" :href="dirUrl('')" @click.prevent="go('')">{{ title }}</a>
    <button class="proc-pill" v-if="proc.running && !procBlocking" @click="showProc" :title="t('proc.show')">
      <span class="spinner sm"></span><span class="pill-label">{{ t('proc.pill') }}</span> {{ procPct }} %
    </button>
    <nav class="tabs">
      <button :class="{active: view==='gallery'}" @click="setView('gallery')">{{ t('tab.gallery') }}</button>
      <button :class="{active: view==='stats'}" @click="setView('stats')">{{ t('tab.stats') }}</button>
    </nav>
    <div class="lang" role="group" :aria-label="t('lang.label')">
      <button v-for="l in langs" :key="l" :class="{active: lang === l}" :aria-pressed="lang === l"
              :title="t('lang.' + l)" @click="setLang(l)">{{ l.toUpperCase() }}</button>
    </div>
    <button class="btn" @click="drawer = true" :title="t('filter')">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M3 4h18l-7 9v6l-4 2v-8z"></path></svg>
      <span class="btn-label">{{ t('filter') }}</span>
      <span class="badge" v-if="activeCount">{{ activeCount }}</span>
    </button>
  </header>

  <div class="toolbar">
    <nav class="crumbs">
      <a :href="dirUrl('')" :class="{cur: !dir}" @click.prevent="go('')">{{ t('home') }}</a>
      <template v-for="c in crumbs" :key="c.path">
        <span class="sep">/</span>
        <a :href="dirUrl(c.path)" :class="{cur: c.path === dir}" @click.prevent="go(c.path)">{{ c.name }}</a>
      </template>
    </nav>
    <div class="tools" v-if="view==='gallery'">
      <label class="switch"><input type="checkbox" v-model="recursive"> {{ t('subfolders') }}</label>
      <select v-model="sortBy" :aria-label="t('sort.label')">
        <option value="name">{{ t('sort.name') }}</option>
        <option value="dateDesc">{{ t('sort.dateDesc') }}</option>
        <option value="dateAsc">{{ t('sort.dateAsc') }}</option>
        <option value="res">{{ t('sort.res') }}</option>
      </select>
    </div>
  </div>

  <div class="chips" v-if="activeCount">
    <template v-for="(vals, key) in filters" :key="key">
      <button class="chip" v-for="v in vals" :key="key + '|' + v" :title="t('chip.remove')" @click="toggle(key, v)">{{ facetLabel(key) }}: {{ fmtVal(key, v) }} ✕</button>
    </template>
    <button class="chip clear" @click="clearFilters">{{ t('clearAll') }}</button>
  </div>

  <main>
    <div v-if="loading" class="state"><span class="spinner"></span>{{ t('reading') }}</div>
    <div v-else-if="error" class="state error">{{ errorText }}</div>

    <template v-else>
      <!-- GALERIE -->
      <section v-if="view==='gallery'">
        <div class="folders" v-if="folders.length">
          <a v-for="f in folders" :key="f.path" class="folder" :href="dirUrl(f.path)" @click.prevent="go(f.path)">
            <div class="cover" :class="{'has-img': !!f.cover}">
              <img v-if="f.cover" :src="f.cover" loading="lazy" alt="" @load="imgDone" @error="imgErr($event, f.coverAlt)">
              <span v-else>📁</span>
            </div>
            <div class="meta"><strong>{{ f.name }}</strong><small>{{ t('nImages', {n: f.count}) }}</small></div>
          </a>
        </div>

        <p class="count" v-if="scope.length">
          {{ t('nImages', {n: filtered.length}) }}<span v-if="activeCount">{{ t('filteredFrom', {n: fmtInt(scope.length)}) }}</span>
        </p>

        <div class="grid">
          <a v-for="(img, i) in visible" :key="img.path" class="tile" :href="img.url" @click.prevent="open(i)">
            <span v-if="img.pending" class="tile-wait" :title="t('processing')"><span class="spinner md"></span></span>
            <img v-else :src="img.thumb" loading="lazy" decoding="async" :alt="img.name" @load="imgDone" @error="imgErr($event, img.thumbAlt)">
            <span class="cap">{{ img.name }}</span>
          </a>
        </div>

        <div v-if="!filtered.length && !folders.length" class="state">{{ t('noImages') }}</div>
        <div v-else-if="!filtered.length && activeCount" class="state">{{ t('noMatch') }}</div>
      </section>

      <!-- STATISTIK -->
      <section v-else>
        <p class="hint">
          {{ t('stats.scopeA') }} <b>{{ dir || t('home') }}</b> {{ t('stats.scopeB') }}
          <span class="live" v-if="proc.running"> {{ t('stats.live') }}</span>
          <button class="btn" @click="showInGallery">{{ t('stats.show') }}</button>
        </p>
        <p class="hint warn" v-if="!chartsOk">{{ t('stats.noCharts') }}</p>
        <div class="cards">
          <div class="card" v-for="c in summary" :key="c[0]"><b>{{ c[1] }}</b><span>{{ c[0] }}</span></div>
        </div>
        <div class="charts" v-if="chartsOk">
          <div class="chart-card" v-for="f in facets" :key="f.key">
            <h3>{{ facetLabel(f.key) }}<small v-if="facetData[f.key].length > maxBars">{{ t('stats.topN', {n: maxBars, m: facetData[f.key].length}) }}</small></h3>
            <div class="chart-box" :style="{height: chartHeight(f) + 'px'}">
              <canvas :ref="el => setCanvas(f.key, el)"></canvas>
            </div>
          </div>
        </div>
      </section>
    </template>
  </main>

  <!-- FILTER-DRAWER -->
  <div class="backdrop" v-if="drawer" @click="drawer = false"></div>
  <aside class="drawer" :class="{open: drawer}" :aria-label="t('filters')">
    <div class="drawer-head">
      <h2>{{ t('filters') }}</h2>
      <button class="icon-btn" @click="drawer = false" :aria-label="t('close')">✕</button>
    </div>
    <div class="drawer-body">
      <label class="switch" v-if="view==='gallery'" style="padding:10px 0"><input type="checkbox" v-model="recursive"> {{ t('subfolders') }}</label>
      <details v-for="f in facets" :key="f.key">
        <summary>
          <span class="lbl">{{ facetLabel(f.key) }}</span>
          <span class="badge" v-if="(filters[f.key] || []).length">{{ filters[f.key].length }}</span>
        </summary>
        <ul class="opts">
          <li v-for="e in facetData[f.key]" :key="e.value" :class="{zero: !e.count}">
            <label>
              <input type="checkbox" :checked="isOn(f.key, e.value)" @change="toggle(f.key, e.value)">
              <span>{{ fmtVal(f.key, e.value) }}</span><em>{{ fmtInt(e.count) }}</em>
            </label>
          </li>
        </ul>
      </details>
    </div>
    <div class="drawer-foot">
      <button class="btn" @click="clearFilters">{{ t('reset') }}</button>
      <button class="btn primary" @click="drawer = false">{{ t('nImages', {n: filtered.length}) }}</button>
    </div>
  </aside>

  <!-- LIGHTBOX / EINZELANSICHT -->
  <div class="lightbox" ref="lbEl" :class="{fs, playing}" v-if="current" @touchstart.passive="touchStart" @touchend="touchEnd">
    <div class="lb-top" v-if="!fs">
      <span class="lb-title">{{ current.name }}</span>
      <span class="lb-count">{{ fmtInt(lbIndex + 1) }} / {{ fmtInt(filtered.length) }}</span>
      <button class="icon-btn" @click="enterFs" :title="t('lb.fullscreen')" :aria-label="t('lb.fullscreen')">⛶</button>
      <button class="icon-btn" :class="{on: info}" @click="info = !info" :title="t('lb.info')" :aria-label="t('lb.info')">ⓘ</button>
      <a class="icon-btn" :href="current.download" download :title="t('lb.download')" :aria-label="t('lb.download')">⤓</a>
      <button class="icon-btn" @click="close" :title="t('lb.close')" :aria-label="t('lb.close')">✕</button>
    </div>
    <div class="lb-body">
      <div class="lb-stage">
        <img v-if="lbLoading" :src="current.thumb" class="lb-ph" alt="" @error="fallback($event, current.thumbAlt)">
        <img :key="current.path" :src="current.url" :alt="current.name" class="lb-full" :class="{loaded: !lbLoading}"
             :title="fs ? (playing ? t('lb.endShow') : t('lb.fromFs')) : t('lb.toFs')"
             @click="toggleFs" @load="lbLoading = false" @error="onFullError($event)">
        <div class="lb-spin" v-if="lbLoading"><span class="spinner"></span></div>
        <button class="nav prev" @click="prev" :aria-label="t('lb.prev')">‹</button>
        <button class="nav next" @click="next" :aria-label="t('lb.next')">›</button>

        <!-- Diashow: Start-Knopf bzw. Anzeigedauer (oben mittig, blendet aus) -->
        <transition name="fade">
          <div class="fs-top" v-if="fs && (fsHint || slideOpts)">
            <button v-if="!playing" class="fs-btn" @click="startShow">▶ {{ t('slide.start') }}</button>
            <div v-else class="fs-durs" role="group" :aria-label="t('slide.duration')">
              <button v-for="s in slideOptions" :key="s" :class="{active: slideDur === s}" :aria-pressed="slideDur === s"
                      :title="t('slide.secTitle', {n: s})" @click="setDur(s)">{{ s }} s</button>
            </div>
          </div>
        </transition>

        <div class="fs-meta" v-if="fs && fsMeta.length">
          <span v-for="(m, k) in fsMeta" :key="k">{{ m }}</span>
        </div>
      </div>
      <aside class="lb-info" v-if="info && !fs">
        <dl>
          <template v-for="r in currentInfo" :key="r[0]"><dt>{{ r[0] }}</dt><dd>{{ r[1] }}</dd></template>
        </dl>
      </aside>
    </div>
  </div>

  <!-- VERARBEITUNGS-OVERLAY (Thumbnails & Metadaten) -->
  <div class="proc" v-if="procBlocking" role="dialog" aria-live="polite">
    <div class="proc-box">
      <span class="spinner"></span>
      <h2>{{ t('proc.title') }}</h2>
      <p>{{ t('proc.desc') }}</p>
      <div class="bar"><i :style="{width: procPct + '%'}"></i></div>
      <p><span class="proc-num">{{ fmtInt(proc.done) }} / {{ fmtInt(proc.total) }}</span> · {{ procEta }}</p>
      <button class="btn" @click="hideProc">{{ t('proc.bg') }}</button>
    </div>
  </div>
</div>

<script src="https://unpkg.com/vue@3.5.13/dist/vue.global.prod.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
if (!window.Vue) {
  const b = document.getElementById('boot');
  b.className = 'boot err';
  b.textContent = b.dataset.err;
  throw new Error('Vue not available');
}

const CFG = <?= json_encode(
    ['titles' => GALLERY_TITLE, 'lang' => $lang, 'langs' => LANGS, 'dir' => $initialDir, 'self' => self_url()],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;
const { createApp, ref, shallowRef, reactive, computed, watch, nextTick, onMounted } = Vue;

/* ================================================================ Sprache / i18n */

const LOCALES = { en: 'en-US', de: 'de-DE' };
const lang = ref(CFG.langs.includes(CFG.lang) ? CFG.lang : CFG.langs[0]);   // global reaktiv → t() & Formatierung

const I18N = {
  en: {
    'lang.label': 'Language', 'lang.en': 'English', 'lang.de': 'Deutsch',
    'tab.gallery': 'Gallery', 'tab.stats': 'Statistics',
    'filter': 'Filter', 'filters': 'Filters', 'close': 'Close', 'reset': 'Reset',
    'home': 'Home',
    'subfolders': 'Include subfolders',
    'sort.label': 'Sort order', 'sort.name': 'Name', 'sort.dateDesc': 'Newest first', 'sort.dateAsc': 'Oldest first', 'sort.res': 'Resolution',
    'chip.remove': 'Remove filter', 'clearAll': 'Clear all filters',
    'reading': 'Reading folder …', 'noImages': 'No images found.', 'noMatch': 'No images match the filters.',
    'nImages': p => p.n === 1 ? '1 image' : fmtInt(p.n) + ' images',
    'filteredFrom': ' (filtered from {n})',
    'processing': 'Processing …',
    'unknown': 'Unknown',
    'stats.scopeA': 'Analysis of all images in',
    'stats.scopeB': 'including subfolders. Click a bar or segment to set or remove a filter.',
    'stats.live': 'Analysis in progress – values are updated continuously.',
    'stats.show': 'Show images →',
    'stats.topN': 'Top {n} of {m}',
    'stats.noCharts': 'Chart.js could not be loaded – charts are unavailable. Filters still work.',
    'sum.images': 'Images', 'sum.size': 'Data volume', 'sum.avgRes': 'Avg. resolution',
    'sum.models': 'Camera models', 'sum.bw': 'Black & white share', 'sum.period': 'Period',
    'facet.color': 'Color / black & white', 'facet.make': 'Camera make', 'facet.model': 'Camera model',
    'facet.lens': 'Lens', 'facet.fnumber': 'Aperture', 'facet.focal': 'Focal length',
    'facet.focal35': 'Focal length (35 mm equiv.)', 'facet.exposure': 'Shutter speed', 'facet.iso': 'ISO',
    'facet.mp': 'Resolution (megapixels)', 'facet.dims': 'Dimensions (pixels)', 'facet.ratio': 'Aspect ratio',
    'facet.orientation': 'Orientation', 'facet.year': 'Year taken', 'facet.type': 'File type',
    'color.color': 'Color', 'color.bw': 'Black & white',
    'orient.landscape': 'Landscape', 'orient.portrait': 'Portrait', 'orient.square': 'Square',
    'ratio.other': 'Other',
    'lb.fullscreen': 'Fullscreen (f)', 'lb.info': 'Information (i)', 'lb.download': 'Download original',
    'lb.close': 'Close (Esc)', 'lb.prev': 'Previous', 'lb.next': 'Next',
    'lb.toFs': 'Click: fullscreen', 'lb.fromFs': 'Click: back to single view',
    'lb.endShow': 'Click: end slideshow and fullscreen',
    'slide.start': 'Start slideshow', 'slide.duration': 'Display time per image', 'slide.secTitle': '{n} seconds per image',
    'info.file': 'File', 'info.folder': 'Folder', 'info.date': 'Taken', 'info.color': 'Color',
    'info.dims': 'Dimensions', 'info.ratio': 'Aspect ratio', 'info.res': 'Resolution', 'info.size': 'File size', 'info.make': 'Make',
    'info.camera': 'Camera', 'info.lens': 'Lens', 'info.aperture': 'Aperture', 'info.focal': 'Focal length',
    'info.kb': '35 mm equiv.: {n} mm', 'info.exposure': 'Shutter speed', 'info.iso': 'ISO',
    'info.analysing': 'analysing …',
    'proc.pill': 'Analysis', 'proc.show': 'Show progress', 'proc.title': 'Preparing gallery',
    'proc.desc': 'Creating thumbnails, reading metadata and analysing images for color / black & white.',
    'proc.eta0': 'Estimating remaining time …', 'proc.etaS': 'about {n} s left', 'proc.etaM': 'about {n} min left',
    'proc.bg': 'Continue in background',
    'error': 'Error',
    'err.dir_missing': 'Image folder not found.', 'err.not_found': 'Folder not found.',
    'err.invalid_path': 'Invalid path.', 'err.bad_request': 'Invalid request.',
    'err.not_writable': 'Thumbnail folder is not writable.', 'err.server': 'Server error.',
    'err.network': 'Network error – server unreachable.', 'err.bad_response': 'Invalid server response (HTTP {status}).',
  },
  de: {
    'lang.label': 'Sprache', 'lang.en': 'English', 'lang.de': 'Deutsch',
    'tab.gallery': 'Galerie', 'tab.stats': 'Statistik',
    'filter': 'Filter', 'filters': 'Filter', 'close': 'Schließen', 'reset': 'Zurücksetzen',
    'home': 'Start',
    'subfolders': 'Unterordner einbeziehen',
    'sort.label': 'Sortierung', 'sort.name': 'Name', 'sort.dateDesc': 'Neueste zuerst', 'sort.dateAsc': 'Älteste zuerst', 'sort.res': 'Auflösung',
    'chip.remove': 'Filter entfernen', 'clearAll': 'Alle Filter löschen',
    'reading': 'Ordner wird eingelesen …', 'noImages': 'Keine Bilder gefunden.', 'noMatch': 'Keine Bilder passen zu den Filtern.',
    'nImages': p => p.n === 1 ? '1 Bild' : fmtInt(p.n) + ' Bilder',
    'filteredFrom': ' (gefiltert aus {n})',
    'processing': 'Wird verarbeitet …',
    'unknown': 'Unbekannt',
    'stats.scopeA': 'Auswertung aller Bilder in',
    'stats.scopeB': 'inkl. Unterordnern. Klick auf einen Balken oder ein Segment setzt bzw. entfernt einen Filter.',
    'stats.live': 'Analyse läuft – die Werte werden laufend aktualisiert.',
    'stats.show': 'Bilder anzeigen →',
    'stats.topN': 'Top {n} von {m}',
    'stats.noCharts': 'Chart.js konnte nicht geladen werden – Diagramme sind nicht verfügbar. Die Filter funktionieren weiterhin.',
    'sum.images': 'Bilder', 'sum.size': 'Datenmenge', 'sum.avgRes': 'Ø Auflösung',
    'sum.models': 'Kameramodelle', 'sum.bw': 'Schwarzweiß-Anteil', 'sum.period': 'Zeitraum',
    'facet.color': 'Farbe / Schwarzweiß', 'facet.make': 'Kamera-Hersteller', 'facet.model': 'Kamera-Modell',
    'facet.lens': 'Objektiv', 'facet.fnumber': 'Blende', 'facet.focal': 'Brennweite',
    'facet.focal35': 'Brennweite (KB-äquivalent)', 'facet.exposure': 'Belichtungszeit', 'facet.iso': 'ISO',
    'facet.mp': 'Auflösung (Megapixel)', 'facet.dims': 'Bildmaße (Pixel)', 'facet.ratio': 'Seitenverhältnis',
    'facet.orientation': 'Ausrichtung', 'facet.year': 'Aufnahmejahr', 'facet.type': 'Dateityp',
    'color.color': 'Farbe', 'color.bw': 'Schwarzweiß',
    'orient.landscape': 'Querformat', 'orient.portrait': 'Hochformat', 'orient.square': 'Quadratisch',
    'ratio.other': 'Andere',
    'lb.fullscreen': 'Vollbild (f)', 'lb.info': 'Informationen (i)', 'lb.download': 'Original herunterladen',
    'lb.close': 'Schließen (Esc)', 'lb.prev': 'Vorheriges', 'lb.next': 'Nächstes',
    'lb.toFs': 'Klicken: Vollbild', 'lb.fromFs': 'Klicken: zurück zur Einzelansicht',
    'lb.endShow': 'Klicken: Diashow und Vollbild beenden',
    'slide.start': 'Diashow starten', 'slide.duration': 'Anzeigedauer pro Bild', 'slide.secTitle': '{n} Sekunden pro Bild',
    'info.file': 'Datei', 'info.folder': 'Ordner', 'info.date': 'Aufnahme', 'info.color': 'Farbe',
    'info.dims': 'Abmessungen', 'info.ratio': 'Seitenverhältnis', 'info.res': 'Auflösung', 'info.size': 'Dateigröße', 'info.make': 'Hersteller',
    'info.camera': 'Kamera', 'info.lens': 'Objektiv', 'info.aperture': 'Blende', 'info.focal': 'Brennweite',
    'info.kb': 'KB: {n} mm', 'info.exposure': 'Belichtung', 'info.iso': 'ISO',
    'info.analysing': 'wird analysiert …',
    'proc.pill': 'Analyse', 'proc.show': 'Fortschritt anzeigen', 'proc.title': 'Galerie wird vorbereitet',
    'proc.desc': 'Vorschaubilder werden erstellt, Metadaten gelesen und Bilder auf Farbe/Schwarzweiß analysiert.',
    'proc.eta0': 'Restzeit wird ermittelt …', 'proc.etaS': 'noch ca. {n} s', 'proc.etaM': 'noch ca. {n} min',
    'proc.bg': 'Im Hintergrund fortsetzen',
    'error': 'Fehler',
    'err.dir_missing': 'Bildordner nicht gefunden.', 'err.not_found': 'Ordner nicht gefunden.',
    'err.invalid_path': 'Ungültiger Pfad.', 'err.bad_request': 'Ungültige Anfrage.',
    'err.not_writable': 'Thumbnail-Ordner ist nicht beschreibbar.', 'err.server': 'Serverfehler.',
    'err.network': 'Netzwerkfehler – Server nicht erreichbar.', 'err.bad_response': 'Ungültige Server-Antwort (HTTP {status}).',
  }
};

/** Übersetzen: Strings mit {platzhaltern} oder Funktionen (Plural); Fallback EN → Schlüssel */
function t(key, p) {
  const d = I18N[lang.value] || {};
  const v = d[key] ?? I18N.en[key] ?? key;
  if (typeof v === 'function') return v(p || {});
  return p ? v.replace(/\{(\w+)\}/g, (m, k) => (p[k] ?? m)) : v;
}

const cookiePath = (() => { const s = CFG.self, i = s.lastIndexOf('/'); return i >= 0 ? s.slice(0, i + 1) : '/'; })();
function persistLang(l) {
  try { document.cookie = 'g_lang=' + l + '; max-age=31536000; path=' + cookiePath + '; SameSite=Lax'; } catch (e) {}
}

/* ================================================================ Formatierung (sprachabhängig) */

const locale      = () => LOCALES[lang.value] || 'en-US';
const fmtNum      = (n, d = 1) => Number(n).toLocaleString(locale(), { maximumFractionDigits: d });
const fmtInt      = n => Number(n).toLocaleString(locale());
const fmtBytes    = b => { const u = ['B','KB','MB','GB','TB']; let i = 0; while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; } return fmtNum(b, i ? 1 : 0) + ' ' + u[i]; };
/** "YYYY-MM-DD HH:MM:SS" → Date (numerischer Konstruktor: keine String-Interpretation, Safari-sicher) */
const toDate      = d => new Date(+d.slice(0, 4), +d.slice(5, 7) - 1, +d.slice(8, 10), +d.slice(11, 13) || 0, +d.slice(14, 16) || 0);
const fmtDate     = d => { try { return toDate(d).toLocaleDateString(locale(), { dateStyle: 'medium' }); } catch (e) { return d.slice(0, 10); } };
const fmtDateTime = d => { try { return toDate(d).toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return d.slice(0, 16); } };
/** Belichtungszeit → sprachneutraler Schlüssel ("1/250" bzw. "0.8" / "2") */
const expKey      = s => s >= 0.5 ? String(Math.round(s * 10) / 10) : '1/' + Math.round(1 / s);
const fmtExpKey   = k => (k.startsWith('1/') ? k : fmtNum(+k)) + ' s';
const fmtExp      = s => fmtExpKey(expKey(s));
const encDir      = p => encodeURIComponent(p).replace(/%2F/g, '/');
const dirUrl      = p => CFG.self + '?dir=' + encDir(p);
const cameraName  = i => {
  if (!i.model) return i.make || null;
  if (i.make && !i.model.toLowerCase().startsWith(i.make.toLowerCase())) return i.make + ' ' + i.model;
  return i.model;
};
// Sortierung unabhängig von der Oberflächensprache (Sprachwechsel sortiert nicht neu)
const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

/** Bei fehlgeschlagener Direkt-URL einmalig auf die PHP-Auslieferung umschalten */
function fallback(e, alt) {
  const el = e.target;
  if (!alt || el.dataset.fb) return false;
  el.dataset.fb = '1';
  el.src = alt;
  return true;
}
/** Bild geladen → Schimmer-Animation beenden (Klasse direkt am <img>, kollidiert nicht mit Vue-Bindings) */
function imgDone(e) { e.target.classList.add('ok'); }
function imgErr(e, alt) { if (!fallback(e, alt)) e.target.classList.add('ok'); }

/* ================================================================ Seitenverhältnis */

// Standardformate (lange : kurze Seite; Hoch-/Querformat wird NICHT unterschieden)
const RATIOS = [['1:1', 1], ['4:3', 4 / 3], ['3:2', 3 / 2], ['16:10', 16 / 10], ['16:9', 16 / 9], ['21:9', 21 / 9]];
const RATIO_TOL = 0.03;   // ±3 % → deckt Beschnitt, 64:27 (2,37) und 2,39:1 bei 21:9 ab; Klassen überschneiden sich nicht
const ratioOf  = i => (i.w && i.h) ? Math.max(i.w, i.h) / Math.min(i.w, i.h) : null;
function ratioKey(i) {
  const r = ratioOf(i);
  if (r === null) return null;
  let best = 'other', dev = RATIO_TOL;
  for (const [k, v] of RATIOS) { const d = Math.abs(r / v - 1); if (d <= dev) { dev = d; best = k; } }
  return best;
}
const ratioRank = k => k === 'other' ? RATIOS.length : RATIOS.findIndex(r => r[0] === k);

/* ================================================================ Facetten */

const PAGE = 120, MAX_BARS = 30, UNKNOWN = '__unknown__';
const PROC_BATCH = 4, PROC_WORKERS = 2, PROC_SMALL = 3;
const SLIDE_OPTIONS = [3, 5, 10, 30, 120], SLIDE_DEFAULT = 5, SLIDE_HINT_MS = 5000;
const C_BASE = '#4f9cf9', C_SEL = '#f5a524', C_DIM = 'rgba(79,156,249,.3)';
const PALETTE = ['#4f9cf9','#f5a524','#3ecf8e','#e5484d','#a78bfa','#22d3ee','#f472b6','#facc15','#94a3b8','#fb923c'];

const store = {
  get(k, d) { try { return localStorage.getItem(k) ?? d; } catch (e) { return d; } },
  set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
};

const MP = ['< 2 MP','2–5 MP','5–8 MP','8–12 MP','12–16 MP','16–24 MP','24–36 MP','36–50 MP','> 50 MP'];
const MP_MAX = [2, 5, 8, 12, 16, 24, 36, 50, Infinity];
const mpIdx = i => MP_MAX.findIndex(max => i.w * i.h / 1e6 < max);

/*
 * Facetten = Statistik-Dimensionen & Filter zugleich.
 *  val  – sprachNEUTRALER Wert (wird gefiltert/gezählt)
 *  fmt  – Anzeige in der aktuellen Sprache (optional)
 *  rank – Sortierschlüssel (optional, sonst nach Häufigkeit)
 */
const FACETS = [
  { key: 'color',    val: i => i.color, fmt: v => t('color.' + v), rank: i => i.color === 'color' ? 0 : i.color === 'bw' ? 1 : null },
  { key: 'make',     horizontal: true, val: i => i.make },
  { key: 'model',    horizontal: true, val: i => i.model },
  { key: 'lens',     horizontal: true, val: i => i.lens },
  { key: 'fnumber',  val: i => i.fnumber || null, fmt: v => 'f/' + fmtNum(+v), rank: i => i.fnumber },
  { key: 'focal',    val: i => i.focal ? Math.round(i.focal) : null, fmt: v => v + ' mm', rank: i => i.focal },
  { key: 'focal35',  val: i => i.focal35 || null, fmt: v => v + ' mm', rank: i => i.focal35 },
  { key: 'exposure', val: i => i.exposure ? expKey(i.exposure) : null, fmt: fmtExpKey, rank: i => i.exposure },
  { key: 'iso',      val: i => i.iso || null, fmt: v => 'ISO ' + v, rank: i => i.iso },
  { key: 'mp',       val: i => i.w ? mpIdx(i) : null, fmt: v => MP[+v], rank: i => i.w ? mpIdx(i) : null },
  { key: 'dims',     horizontal: true, val: i => i.w ? i.w + ' × ' + i.h : null },
  { key: 'ratio',    val: ratioKey, fmt: v => v === 'other' ? t('ratio.other') : v, rank: i => { const k = ratioKey(i); return k === null ? null : ratioRank(k); } },
  { key: 'orientation', type: 'doughnut', val: i => !i.w ? null : ratioKey(i) === '1:1' ? 'square' : i.w > i.h ? 'landscape' : 'portrait', fmt: v => t('orient.' + v) },
  { key: 'year',     val: i => i.date ? i.date.slice(0, 4) : null, rank: i => i.date ? +i.date.slice(0, 4) : null },
  { key: 'type',     type: 'doughnut', val: i => i.ext.toUpperCase().replace('JPEG', 'JPG') },
];
const FACET_BY_KEY = Object.fromEntries(FACETS.map(f => [f.key, f]));

function prep(img) {
  img._f = {}; img._r = {};
  for (const f of FACETS) {
    const v = f.val(img);
    img._f[f.key] = (v === null || v === undefined || v === '') ? UNKNOWN : String(v);
    img._r[f.key] = f.rank ? (f.rank(img) ?? Infinity) : 0;
  }
  return img;
}
const passes = (img, list) => list.every(([k, set]) => set.has(img._f[k]));
/** Sprachneutralen Facettenwert anzeigen */
function fmtVal(key, v) {
  if (v === UNKNOWN) return t('unknown');
  const f = FACET_BY_KEY[key];
  return f && f.fmt ? f.fmt(v) : v;
}
const fsElement = () => document.fullscreenElement || document.webkitFullscreenElement || null;

/** API-Fehler → sprachneutraler Fehlercode */
function apiError(j, status) {
  const e = new Error((j && (j.detail || j.error)) || 'HTTP ' + status);
  e.key = (j && typeof j.error === 'string') ? j.error : 'bad_response';
  e.params = { status };
  e.detail = (j && j.detail) || null;
  return e;
}
async function apiFetch(url, opts) {
  let r;
  try { r = await fetch(url, opts); }
  catch (e) { const x = new Error(e.message); x.key = 'network'; throw x; }
  let j = null;
  try { j = await r.json(); } catch (e) { throw apiError(null, r.status); }
  if (!r.ok || !j || j.error) throw apiError(j, r.status);
  return j;
}

/* Chart-Instanzen bewusst NICHT reaktiv halten */
const charts    = new Map();
const chartVals = new Map();   // Facette → sprachneutrale Werte je Balken/Segment (für Klick)
const canvases  = {};
const chartsOk  = typeof Chart !== 'undefined';

createApp({
  setup() {
    const title     = computed(() => CFG.titles[lang.value] || CFG.titles[CFG.langs[0]]);
    const dir       = ref(CFG.dir);
    const images    = shallowRef([]);
    const folders   = shallowRef([]);
    const loading   = ref(true);
    const error     = ref(null);              // { key, params, detail } → in jeder Sprache darstellbar
    const view      = ref(location.hash === '#stats' ? 'stats' : 'gallery');
    const recursive = ref(store.get('g.rec', '0') === '1');
    const sortBy    = ref(store.get('g.sort', 'name'));
    const filters   = reactive({});
    const drawer    = ref(false);
    const limit     = ref(PAGE);
    const lbPath    = ref(null);              // Einzelansicht merkt sich den PFAD, nicht die Position
    const lbEl      = ref(null);
    const info      = ref(window.innerWidth >= 900);
    const lbLoading = ref(true);
    const fs        = ref(false);
    let fsExitAt    = 0;

    // Diashow
    const playing   = ref(false);             // Diashow läuft
    const slideDur  = ref(SLIDE_DEFAULT);     // Sekunden pro Bild
    const fsHint    = ref(false);             // Start-Knopf sichtbar (erste 5 s im Vollbild)
    const slideOpts = ref(false);             // Dauer-Knöpfe sichtbar (nur beim ersten Bild nach dem Start)
    let hintTimer = 0, slideTimer = 0, wakeLock = null, wakePending = false;

    /* ---------- Sprache ---------- */
    function setLang(l) { if (CFG.langs.includes(l)) lang.value = l; }
    watch(lang, l => {
      persistLang(l);
      document.documentElement.lang = l;
      document.title = title.value;
      const mf = document.querySelector('link[rel="manifest"]');
      if (mf) mf.href = CFG.self + '?manifest=1&lang=' + l;
      document.querySelectorAll('meta[name="apple-mobile-web-app-title"],meta[name="application-name"]').forEach(m => m.content = title.value);
      if (view.value === 'stats') nextTick(renderCharts);
    });

    const errorText = computed(() => {
      const e = error.value; if (!e) return '';
      const k = 'err.' + e.key;
      const msg = (k in I18N.en) ? t(k, e.params) : t('err.server');
      return t('error') + ': ' + msg + (e.detail ? ' – ' + e.detail : '');
    });

    /* ---------- Daten laden & Navigation ---------- */
    let loadSeq = 0;
    async function load() {
      const seq = ++loadSeq;
      loading.value = true; error.value = null;
      try {
        const j = await apiFetch(CFG.self + '?api=data&dir=' + encodeURIComponent(dir.value), { cache: 'no-store' });
        if (seq !== loadSeq) return;            // veraltete Antwort verwerfen
        j.images.forEach(prep);
        images.value = j.images;
        folders.value = j.folders;
        if (j.canStore) enqueue(j.images.filter(i => i.pending).map(i => i.path));
      } catch (e) {
        if (seq !== loadSeq) return;
        error.value = { key: e.key || 'server', params: e.params || {}, detail: e.detail || null };
        images.value = []; folders.value = [];
      } finally {
        if (seq === loadSeq) {
          loading.value = false;
          limit.value = PAGE;
          nextTick(maybeMore);
        }
      }
    }
    function urlFor(path) { return dirUrl(path) + (view.value === 'stats' ? '#stats' : ''); }
    function go(path) {
      if (path === dir.value) return;
      dir.value = path;
      history.pushState({ dir: path }, '', urlFor(path));
      window.scrollTo(0, 0);
      load();
    }
    function setView(v) {
      view.value = v;
      history.replaceState(history.state, '', urlFor(dir.value));
    }
    function showInGallery() { recursive.value = true; setView('gallery'); }

    const crumbs = computed(() => {
      const out = []; let acc = '';
      for (const p of dir.value.split('/').filter(Boolean)) { acc = acc ? acc + '/' + p : p; out.push({ name: p, path: acc }); }
      return out;
    });

    /* ---------- Hintergrundverarbeitung (Thumbnails, Metadaten, Farbanalyse) ---------- */
    const proc = reactive({ total: 0, done: 0, failed: 0, running: false, bg: false, small: false, started: 0 });
    const procQueue = [];
    const procSeen  = new Set();   // pro Sitzung höchstens einmal je Bild → keine Endlosschleife
    const procBlocking = computed(() => proc.running && !proc.bg && !proc.small);
    const procPct = computed(() => proc.total ? Math.min(100, Math.round(proc.done / proc.total * 100)) : 0);
    const procEta = computed(() => {
      if (proc.done < PROC_BATCH) return t('proc.eta0');
      const s = Math.round((Date.now() - proc.started) / proc.done * (proc.total - proc.done) / 1000);
      return s < 60 ? t('proc.etaS', { n: Math.max(1, s) }) : t('proc.etaM', { n: Math.ceil(s / 60) });
    });
    function hideProc() { proc.bg = true; }
    function showProc() { proc.bg = false; proc.small = false; }

    function enqueue(paths) {
      const add = paths.filter(p => !procSeen.has(p));
      if (!add.length) return;
      add.forEach(p => { procSeen.add(p); procQueue.push(p); });
      if (proc.running) { proc.total += add.length; return; }
      Object.assign(proc, { total: add.length, done: 0, failed: 0, running: true, small: add.length <= PROC_SMALL, started: Date.now() });
      runProc();
    }

    /** Neue Datensätze vom Server übernehmen (Galerie + Statistik aktualisieren sich live) */
    function applyUpdates(recs, removed) {
      if (!recs.length && !removed.length) return;
      const map = new Map(recs.map(r => [r.path, prep(r)]));
      const rm  = new Set(removed);
      images.value = images.value.filter(i => !rm.has(i.path)).map(i => map.get(i.path) || i);
    }
    /** Verarbeitung fehlgeschlagen → Bild trotzdem anzeigen (Thumbnail dann direkt über ?thumb=) */
    function unpend(paths) {
      if (!paths.length) return;
      const s = new Set(paths);
      images.value = images.value.map(i => (i.pending && s.has(i.path)) ? Object.assign({}, i, { pending: false }) : i);
    }

    async function processBatch(batch) {
      const j = await apiFetch(CFG.self + '?api=process', {
        method: 'POST', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-Gallery': '1' },
        body: JSON.stringify({ paths: batch })
      });
      applyUpdates(j.images || [], j.removed || []);
      unpend(j.failed || []);
      return (j.failed || []).length;
    }

    async function runProc() {
      const worker = async () => {
        while (procQueue.length) {
          const batch = procQueue.splice(0, PROC_BATCH);
          try { proc.failed += await processBatch(batch); }
          catch (e) { proc.failed += batch.length; unpend(batch); }
          proc.done += batch.length;
        }
      };
      await Promise.all(Array.from({ length: PROC_WORKERS }, () => worker()));
      proc.running = false;
    }

    /* ---------- Filter (sprachneutrale Werte) ---------- */
    const scope = computed(() => (view.value === 'stats' || recursive.value)
      ? images.value
      : images.value.filter(i => i.folder === dir.value));

    const activeFilters = computed(() =>
      Object.entries(filters).filter(([, v]) => v && v.length).map(([k, v]) => [k, new Set(v)]));
    const activeCount = computed(() => activeFilters.value.reduce((s, [, set]) => s + set.size, 0));

    function toggle(k, v) {
      const arr = filters[k] || (filters[k] = []);
      const i = arr.indexOf(v);
      i >= 0 ? arr.splice(i, 1) : arr.push(v);
      if (!arr.length) delete filters[k];
    }
    const isOn = (k, v) => !!(filters[k] && filters[k].includes(v));
    function clearFilters() { Object.keys(filters).forEach(k => delete filters[k]); }
    const facetLabel = k => t('facet.' + k);

    const filtered = computed(() => {
      const list = scope.value.filter(i => passes(i, activeFilters.value));
      const byName = (a, b) => collator.compare(a.path, b.path);
      const cmp = {
        name: byName,
        dateDesc: (a, b) => (b.date || '').localeCompare(a.date || '') || byName(a, b),
        dateAsc:  (a, b) => (a.date || '\uffff').localeCompare(b.date || '\uffff') || byName(a, b),
        res:      (a, b) => (b.w * b.h) - (a.w * a.h) || byName(a, b),
      }[sortBy.value] || byName;
      return list.sort(cmp);
    });

    /* Facetten-Zählung: jede Facette wird mit allen ANDEREN Filtern gezählt (Cross-Filter) */
    const facetData = computed(() => {
      const res = {};
      for (const f of FACETS) {
        const others = activeFilters.value.filter(([k]) => k !== f.key);
        const map = new Map();
        for (const img of scope.value) {
          if (!passes(img, others)) continue;
          const v = img._f[f.key];
          let e = map.get(v);
          if (!e) { e = { value: v, count: 0, rank: img._r[f.key] }; map.set(v, e); }
          e.count++;
        }
        for (const v of (filters[f.key] || [])) if (!map.has(v)) map.set(v, { value: v, count: 0, rank: Infinity });
        res[f.key] = [...map.values()].sort((a, b) =>
          ((a.value === UNKNOWN) - (b.value === UNKNOWN)) ||
          (f.rank ? (a.rank - b.rank) : (b.count - a.count)) ||
          collator.compare(a.value, b.value));
      }
      return res;
    });

    /* ---------- Galerie: schrittweises Rendern ---------- */
    const visible = computed(() => filtered.value.slice(0, limit.value));
    function maybeMore() {
      if (view.value !== 'gallery' || limit.value >= filtered.value.length) return;
      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 1500) {
        limit.value += PAGE;
        nextTick(maybeMore);
      }
    }
    // Nur bei geänderter Auswahl zurücksetzen – NICHT bei Live-Updates (sonst springt die Seite)
    const resetLimit = () => { limit.value = PAGE; nextTick(maybeMore); };
    watch(activeFilters, resetLimit);
    watch(sortBy, resetLimit);
    watch(recursive, resetLimit);
    watch(filtered, () => nextTick(maybeMore));
    watch(view, () => nextTick(maybeMore));

    /* ---------- Statistik ---------- */
    const summary = computed(() => {
      const l = filtered.value; let size = 0, px = 0, min = null, max = null, bw = 0, known = 0; const models = new Set();
      for (const i of l) {
        size += i.size || 0; px += (i.w * i.h) || 0;
        if (i.model) models.add(i.model);
        if (i.color) { known++; if (i.color === 'bw') bw++; }
        if (i.date) { if (!min || i.date < min) min = i.date; if (!max || i.date > max) max = i.date; }
      }
      const y1 = min && min.slice(0, 4), y2 = max && max.slice(0, 4);
      return [
        [t('sum.images'), fmtInt(l.length)],
        [t('sum.size'), fmtBytes(size)],
        [t('sum.avgRes'), l.length ? fmtNum(px / l.length / 1e6) + ' MP' : '–'],
        [t('sum.models'), fmtInt(models.size)],
        [t('sum.bw'), known ? fmtNum(bw / known * 100, 0) + ' %' : '–'],
        [t('sum.period'), min ? (y1 === y2 ? y1 : y1 + '–' + y2) : '–'],
      ];
    });

    function chartEntries(f) {
      const arr = facetData.value[f.key];
      if (arr.length <= MAX_BARS) return arr;
      const top = new Set([...arr].sort((a, b) => b.count - a.count).slice(0, MAX_BARS));
      return arr.filter(e => top.has(e));
    }
    function chartHeight(f) {
      if (f.type === 'doughnut') return 280;
      if (f.horizontal) return Math.max(200, chartEntries(f).length * 24 + 50);
      return 280;
    }
    function setCanvas(key, el) { if (el) canvases[key] = el; else delete canvases[key]; }
    function toggleByIndex(key, idx) {
      const v = (chartVals.get(key) || [])[idx];
      if (v !== undefined) toggle(key, v);
    }

    function chartConfig(f) {
      const doughnut = f.type === 'doughnut';
      const catAxis = {
        ticks: {
          autoSkip: !f.horizontal,
          callback: function (v) { const l = String(this.getLabelForValue(v)); return l.length > 22 ? l.slice(0, 21) + '…' : l; }
        },
        grid: { display: false }
      };
      const valAxis = { beginAtZero: true, ticks: { precision: 0 } };
      return {
        type: doughnut ? 'doughnut' : 'bar',
        data: { labels: [], datasets: [{ label: '', data: [], backgroundColor: [], borderWidth: doughnut ? 2 : 0, borderColor: '#171a21', borderRadius: doughnut ? 0 : 4 }] },
        options: {
          responsive: true, maintainAspectRatio: false, animation: { duration: 250 },
          indexAxis: f.horizontal ? 'y' : 'x',
          onClick: (evt, els) => { if (els.length) toggleByIndex(f.key, els[0].index); },
          onHover: (evt, els) => { if (evt.native) evt.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
          plugins: {
            legend: doughnut
              ? { display: true, position: 'bottom', onClick: (e, item) => toggleByIndex(f.key, item.index) }
              : { display: false }
          },
          scales: doughnut ? {} : (f.horizontal ? { y: catAxis, x: valAxis } : { x: catAxis, y: valAxis })
        }
      };
    }

    function destroyCharts() { charts.forEach(c => c.destroy()); charts.clear(); chartVals.clear(); }
    function renderCharts() {
      if (view.value !== 'stats' || !chartsOk) return;
      for (const f of FACETS) {
        const el = canvases[f.key];
        let ch = charts.get(f.key);
        if (!el) { if (ch) { ch.destroy(); charts.delete(f.key); } continue; }
        if (ch && ch.canvas !== el) { ch.destroy(); ch = null; }
        if (!ch) { ch = new Chart(el, chartConfig(f)); charts.set(f.key, ch); }
        const entries = chartEntries(f);
        const sel = new Set(filters[f.key] || []);
        chartVals.set(f.key, entries.map(e => e.value));
        ch.options.locale = locale();
        ch.data.labels = entries.map(e => fmtVal(f.key, e.value));
        ch.data.datasets[0].label = t('sum.images');
        ch.data.datasets[0].data = entries.map(e => e.count);
        ch.data.datasets[0].backgroundColor = entries.map((e, i) => f.type === 'doughnut'
          ? PALETTE[i % PALETTE.length] + (sel.size && !sel.has(e.value) ? '4d' : '')
          : sel.has(e.value) ? C_SEL : (sel.size ? C_DIM : C_BASE));
        ch.update();
      }
    }
    watch(view, v => { if (v === 'stats') nextTick(renderCharts); else destroyCharts(); });
    watch(facetData, () => { if (view.value === 'stats') nextTick(renderCharts); });
    watch(loading, l => { if (l) destroyCharts(); });

    /* ---------- Einzelansicht (Lightbox) ---------- */
    const lbIndex = computed(() => lbPath.value === null ? -1 : filtered.value.findIndex(i => i.path === lbPath.value));
    const current = computed(() => lbIndex.value >= 0 ? filtered.value[lbIndex.value] : null);

    function open(i) {
      const img = filtered.value[i];
      if (!img) return;
      lbPath.value = img.path;
      document.body.style.overflow = 'hidden';
    }
    function close() {
      exitFs();
      lbPath.value = null;
      document.body.style.overflow = '';
    }
    function step(d) {
      const n = filtered.value.length, i = lbIndex.value;
      if (!n || i < 0) return;
      lbPath.value = filtered.value[(i + d + n) % n].path;
    }
    const next = () => step(1);
    const prev = () => step(-1);

    function onFullError(e) {
      if (!fallback(e, current.value && current.value.urlAlt)) lbLoading.value = false;
    }
    watch(lbPath, p => {
      clearTimeout(slideTimer);               // Diashow: Zeit für das neue Bild startet nach dem Laden
      slideOpts.value = false;                // Dauer-Knöpfe nur beim ersten Bild der Diashow
      if (!playing.value) clearHint();        // Start-Knopf nur beim ersten Bild im Vollbild
      if (p === null) return;
      lbLoading.value = true;                 // nur bei echtem Bildwechsel
      const l = filtered.value, n = l.length, i = lbIndex.value;
      if (i < 0) return;
      [1, -1].forEach(d => { const x = l[(i + d + n) % n]; if (x && x.path !== p) new Image().src = x.url; });
      if (i >= limit.value - 5) limit.value = Math.min(n, i + PAGE);
    });
    // Bild durch Filter-/Ordnerwechsel weggefallen → Einzelansicht sauber schließen
    watch(current, c => { if (lbPath.value !== null && !c) close(); });

    /* ---------- Vollbildmodus ---------- */
    function enterFs() {
      if (fs.value) return;
      fs.value = true;
      const el = lbEl.value;
      const req = el && (el.requestFullscreen || el.webkitRequestFullscreen);
      if (req && !fsElement()) {
        try { const p = req.call(el); if (p && p.catch) p.catch(() => {}); } catch (e) { /* CSS-Vollbild bleibt aktiv */ }
      }
      showStartHint();
    }
    function exitFs() {
      stopShow();
      clearHint();
      fs.value = false;
      if (fsElement()) {
        const ex = document.exitFullscreen || document.webkitExitFullscreen;
        try { const p = ex && ex.call(document); if (p && p.catch) p.catch(() => {}); } catch (e) {}
      }
    }
    function toggleFs() { fs.value ? exitFs() : enterFs(); }   // Klick aufs Bild: im Vollbild = Diashow + Vollbild beenden
    function onFsChange() {
      if (!fsElement() && fs.value) { fs.value = false; fsExitAt = Date.now(); }   // z. B. Esc im Browser-Vollbild
    }
    // Jedes Verlassen des Vollbilds (Klick, Esc, Browser) beendet auch die Diashow
    watch(fs, v => { if (!v) { stopShow(); clearHint(); } });

    /* ---------- Diashow ---------- */
    function clearHint() { clearTimeout(hintTimer); fsHint.value = false; }
    function showStartHint() {
      clearHint();
      if (filtered.value.length < 2) return;   // mit einem Bild gibt es nichts zu zeigen
      fsHint.value = true;
      hintTimer = setTimeout(() => { fsHint.value = false; }, SLIDE_HINT_MS);
    }
    function scheduleSlide() {
      clearTimeout(slideTimer);
      if (!playing.value || !fs.value || lbLoading.value) return;   // Zeit läuft erst, wenn das Bild geladen ist
      slideTimer = setTimeout(() => {
        slideOpts.value = false;
        const before = lbPath.value;
        next();                                  // am Ende automatisch wieder von vorn (step() rechnet modulo)
        if (lbPath.value === before) scheduleSlide();   // Auswahl auf 1 Bild geschrumpft → weiter takten
      }, slideDur.value * 1000);
    }
    function startShow() {
      if (!fs.value || filtered.value.length < 2) return;
      clearHint();
      playing.value = true;
      slideOpts.value = true;                    // Dauer-Knöpfe während des ersten Bildes zeigen
      acquireWake();
      scheduleSlide();
    }
    function setDur(s) {
      if (!SLIDE_OPTIONS.includes(s)) return;
      slideDur.value = s;
      scheduleSlide();                           // aktuelles Bild mit neuer Dauer
    }
    function stopShow() {
      clearTimeout(slideTimer);
      playing.value = false;
      slideOpts.value = false;
      releaseWake();
    }
    watch(lbLoading, l => { if (!l) scheduleSlide(); });

    // Bildschirm während der Diashow aktiv halten (falls vom Browser unterstützt)
    async function acquireWake() {
      if (!('wakeLock' in navigator) || wakeLock || wakePending) return;
      wakePending = true;
      try {
        const l = await navigator.wakeLock.request('screen');
        if (!playing.value) { l.release().catch(() => {}); return; }
        wakeLock = l;
        l.addEventListener('release', () => { if (wakeLock === l) wakeLock = null; });
      } catch (e) { /* nicht erlaubt / nicht unterstützt */ }
      finally { wakePending = false; }
    }
    function releaseWake() {
      const l = wakeLock; wakeLock = null;
      if (l) l.release().catch(() => {});
    }

    const fsMeta = computed(() => {
      const i = current.value; if (!i) return [];
      return [
        cameraName(i),
        i.focal ? fmtNum(i.focal) + ' mm' : null,
        i.fnumber ? 'f/' + fmtNum(i.fnumber) : null,
        i.iso ? 'ISO ' + i.iso : null,
        i.date ? fmtDate(i.date) : null,
      ].filter(Boolean);
    });

    let tx = 0, ty = 0;
    function touchStart(e) { tx = e.changedTouches[0].clientX; ty = e.changedTouches[0].clientY; }
    function touchEnd(e) {
      if (e.touches && e.touches.length) return;
      const dx = e.changedTouches[0].clientX - tx, dy = e.changedTouches[0].clientY - ty;
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) dx < 0 ? next() : prev();
    }

    function ratioInfo(i) {
      const k = ratioKey(i), r = ratioOf(i);
      if (k === null) return null;
      return (k === 'other' ? t('ratio.other') : k) + ' (' + fmtNum(r, 2) + ' : 1)';
    }

    const currentInfo = computed(() => {
      const i = current.value; if (!i) return [];
      return [
        [t('info.file'), i.name],
        [t('info.folder'), i.folder || t('home')],
        [t('info.date'), i.date ? fmtDateTime(i.date) : null],
        [t('info.color'), i.color ? t('color.' + i.color) : (i.pending ? t('info.analysing') : null)],
        [t('info.dims'), i.w ? i.w + ' × ' + i.h + ' px' : null],
        [t('info.ratio'), ratioInfo(i)],
        [t('info.res'), i.w ? fmtNum(i.w * i.h / 1e6) + ' MP' : null],
        [t('info.size'), fmtBytes(i.size || 0)],
        [t('info.make'), i.make],
        [t('info.camera'), i.model],
        [t('info.lens'), i.lens],
        [t('info.aperture'), i.fnumber ? 'f/' + fmtNum(i.fnumber) : null],
        [t('info.focal'), i.focal ? fmtNum(i.focal) + ' mm' + (i.focal35 ? ' (' + t('info.kb', { n: i.focal35 }) + ')' : '') : null],
        [t('info.exposure'), i.exposure ? fmtExp(i.exposure) : null],
        [t('info.iso'), i.iso],
      ].filter(r => r[1] !== null && r[1] !== undefined && r[1] !== '');
    });

    /* ---------- Sonstiges ---------- */
    watch(recursive, v => store.set('g.rec', v ? '1' : '0'));
    watch(sortBy, v => store.set('g.sort', v));

    onMounted(() => {
      const boot = document.getElementById('boot');
      if (boot) boot.remove();
      persistLang(lang.value);                  // auch eine per ?lang= gewählte Sprache merken
      document.documentElement.lang = lang.value;
      document.title = title.value;
      if (chartsOk) {
        Chart.defaults.color = '#a9adb7';
        Chart.defaults.borderColor = 'rgba(255,255,255,.08)';
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
      }
      history.replaceState({ dir: dir.value }, '', urlFor(dir.value));   // entfernt u. a. ?lang= aus der Adresszeile
      window.addEventListener('popstate', () => {
        dir.value = new URLSearchParams(location.search).get('dir') || '';
        view.value = location.hash === '#stats' ? 'stats' : 'gallery';
        close(); load();
      });
      window.addEventListener('scroll', maybeMore, { passive: true });
      window.addEventListener('resize', maybeMore);
      document.addEventListener('fullscreenchange', onFsChange);
      document.addEventListener('webkitfullscreenchange', onFsChange);
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && playing.value) acquireWake();   // Wake-Lock nach Tab-Wechsel erneuern
      });
      window.addEventListener('keydown', e => {
        if (e.ctrlKey || e.metaKey || e.altKey) return;   // z. B. Strg+F (Seitensuche) nicht abfangen
        if (current.value) {
          if (e.key === 'ArrowRight') { e.preventDefault(); next(); }
          else if (e.key === 'ArrowLeft') { e.preventDefault(); prev(); }
          else if (e.key === 'Escape') {
            if (fs.value) exitFs();
            else if (Date.now() - fsExitAt > 400) close();   // Esc hat gerade erst das Browser-Vollbild beendet
          }
          else if (e.key === 'f' || e.key === 'F') toggleFs();
          else if (e.key === 'i' && !fs.value) info.value = !info.value;
        } else if (drawer.value && e.key === 'Escape') drawer.value = false;
      });
      load();
    });

    return {
      t, lang, langs: CFG.langs, setLang, fmtVal, fmtInt, chartsOk, errorText,
      title, dir, folders, loading, error, view, recursive, sortBy, filters, drawer,
      lbEl, lbIndex, info, lbLoading, fs, current, currentInfo, fsMeta, crumbs, scope, filtered, visible,
      facetData, activeCount, summary, facets: FACETS, maxBars: MAX_BARS,
      proc, procBlocking, procPct, procEta, hideProc, showProc,
      playing, slideDur, fsHint, slideOpts, slideOptions: SLIDE_OPTIONS, startShow, setDur,
      go, setView, showInGallery, dirUrl, toggle, isOn, clearFilters, facetLabel,
      chartHeight, setCanvas, open, close, next, prev, touchStart, touchEnd,
      enterFs, toggleFs, fallback, imgDone, imgErr, onFullError
    };
  }
}).mount('#app');
</script>
</body>
</html>