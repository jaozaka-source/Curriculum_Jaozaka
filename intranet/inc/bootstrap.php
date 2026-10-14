<?php
/**
 * Intranet chef de projet — initialisation commune
 * Session, base SQLite, authentification, protections CSRF, utilitaires.
 */

declare(strict_types=1);

const DB_PATH = __DIR__ . '/../data/intranet.sqlite';

const STATUTS = ['À cadrer', 'En cours', 'En pause', 'Terminé', 'Annulé'];

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

session_name('intranet_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// ---------- Base de données ----------

function db(): PDO {
    static $pdo = null;
    if ($pdo) { return $pdo; }

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            email         TEXT NOT NULL UNIQUE COLLATE NOCASE,
            nom           TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            role          TEXT NOT NULL DEFAULT 'chef_projet',
            created_at    TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS programmes (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            nom         TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            statut      TEXT NOT NULL DEFAULT 'À cadrer',
            date_debut  TEXT,
            date_fin    TEXT,
            created_at  TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS projets (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            programme_id INTEGER REFERENCES programmes(id) ON DELETE SET NULL,
            nom          TEXT NOT NULL,
            description  TEXT NOT NULL DEFAULT '',
            statut       TEXT NOT NULL DEFAULT 'À cadrer',
            avancement   INTEGER NOT NULL DEFAULT 0 CHECK (avancement BETWEEN 0 AND 100),
            date_debut   TEXT,
            date_fin     TEXT,
            created_at   TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_programmes_owner ON programmes(owner_id);
        CREATE INDEX IF NOT EXISTS idx_projets_owner ON projets(owner_id);
        CREATE INDEX IF NOT EXISTS idx_projets_programme ON projets(programme_id);
    ");
    return $pdo;
}

function has_users(): bool {
    return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

// ---------- Authentification ----------

function current_user(): ?array {
    static $user = false;
    if ($user !== false) { return $user; }
    $user = null;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT id, email, nom, role FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $user = $st->fetch() ?: null;
    }
    return $user;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        redirect(has_users() ? 'login.php' : 'setup.php');
    }
    return $user;
}

function login_user(int $id): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
}

// ---------- Formulaires ----------

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Session expirée. Rechargez la page et recommencez.');
    }
}

function post_str(string $key, int $max = 2000): string {
    $v = trim(str_replace("\0", '', (string)($_POST[$key] ?? '')));
    return mb_substr($v, 0, $max);
}

/** Date au format AAAA-MM-JJ, ou null si vide/invalide. */
function post_date(string $key): ?string {
    $v = post_str($key, 10);
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

function post_statut(): string {
    $v = post_str('statut', 40);
    return in_array($v, STATUTS, true) ? $v : STATUTS[0];
}

// ---------- Utilitaires ----------

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash(?string $msg = null): ?string {
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function date_fr(?string $d): string {
    if (!$d) { return '—'; }
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return $dt ? $dt->format('d/m/Y') : '—';
}

function statut_badge(string $statut): string {
    $slug = [
        'À cadrer' => 'cadrer', 'En cours' => 'cours', 'En pause' => 'pause',
        'Terminé'  => 'termine', 'Annulé' => 'annule',
    ][$statut] ?? 'cadrer';
    return '<span class="badge badge-' . $slug . '">' . e($statut) . '</span>';
}

function progress(int $pct): string {
    $pct = max(0, min(100, $pct));
    return '<span class="progress" role="img" aria-label="Avancement ' . $pct . ' %">'
         . '<span class="bar"><span style="width:' . $pct . '%"></span></span>'
         . '<span class="pct">' . $pct . ' %</span></span>';
}

function statut_options(string $current): string {
    $html = '';
    foreach (STATUTS as $s) {
        $html .= '<option' . ($s === $current ? ' selected' : '') . '>' . e($s) . '</option>';
    }
    return $html;
}
