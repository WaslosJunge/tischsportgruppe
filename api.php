<?php
/* ================================================================
   Kickerlos – kleiner Speicherdienst
   ----------------------------------------------------------------
   GET  api.php   ->  liefert {"rev":N,"state":{...}}
   POST api.php   ->  erwartet {"rev":N,"state":{...}}
                      speichert nur, wenn N noch aktuell ist,
                      sonst Status 409 mit dem neueren Stand.

   Gespeichert wird in daten/stand.json. Sonst nichts.
   Getestet ab PHP 7.0.
   ================================================================ */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$ordner = __DIR__ . '/daten';
$datei  = $ordner . '/stand.json';
$max    = 400000; // 400 KB reichen fuer sehr viele Runden

$leer = [
    'rev'   => 0,
    'state' => ['v' => 3, 'players' => [], 'pairMode' => 'dice', 'draw' => null],
];

function antwort(array $daten, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
    antwort(['fehler' => 'Der Ordner "daten" fehlt und kann nicht angelegt werden.'], 500);
}

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- Lesen ---------- */

if ($methode === 'GET') {
    if (!is_file($datei)) {
        antwort($leer);
    }
    $roh = @file_get_contents($datei);
    $dat = json_decode((string) $roh, true);
    antwort(is_array($dat) && isset($dat['state']) ? $dat : $leer);
}

/* ---------- Schreiben ---------- */

if ($methode === 'POST') {
    $roh = file_get_contents('php://input');
    if ($roh === false || strlen($roh) > $max) {
        antwort(['fehler' => 'Zu viele Daten auf einmal.'], 413);
    }

    $ein = json_decode($roh, true);
    if (!is_array($ein) || !isset($ein['state']) || !is_array($ein['state'])) {
        antwort(['fehler' => 'Die Daten sind nicht lesbar.'], 400);
    }

    $basis = isset($ein['rev']) ? (int) $ein['rev'] : 0;

    $fh = @fopen($datei, 'c+');
    if ($fh === false) {
        antwort(['fehler' => 'daten/stand.json ist nicht beschreibbar – Rechte auf 664, den Ordner auf 775 setzen.'], 500);
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        antwort(['fehler' => 'Die Datei ist gerade gesperrt. Gleich nochmal.'], 503);
    }

    $inhalt = stream_get_contents($fh);
    $alt    = json_decode((string) $inhalt, true);
    $revAlt = (is_array($alt) && isset($alt['rev'])) ? (int) $alt['rev'] : 0;

    if ($basis !== $revAlt) {
        flock($fh, LOCK_UN);
        fclose($fh);
        antwort([
            'fehler' => 'konflikt',
            'rev'    => $revAlt,
            'state'  => (is_array($alt) && isset($alt['state'])) ? $alt['state'] : $leer['state'],
        ], 409);
    }

    $neu = ['rev' => $revAlt + 1, 'state' => $ein['state']];

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($neu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    antwort($neu);
}

antwort(['fehler' => 'Nur GET und POST.'], 405);
