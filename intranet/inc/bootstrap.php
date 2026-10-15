<?php
/**
 * Intranet chef de projet — initialisation commune
 * Session, base SQLite (schéma + données initiales), authentification, droits,
 * protections CSRF, lecture des formulaires et formatage.
 */

declare(strict_types=1);

const DB_PATH = __DIR__ . '/../data/intranet.sqlite';

const STATUTS = ['À cadrer', 'En cours', 'En pause', 'Terminé', 'Annulé'];

const ROLES = [
    'admin'        => 'Administrateur',
    'direction'    => 'Direction / PMO',
    'chef_projet'  => 'Chef de projet',
    'contributeur' => 'Contributeur',
    'lecteur'      => 'Lecteur',
];

const TYPES_PERSONNE = ['interne' => 'Interne', 'externe' => 'Externe'];
const NATURES        = ['titulaire' => 'Titulaire', 'interim' => 'Intérim'];
const TYPES_RESSOURCE = ['materiel' => 'Matériel', 'service' => 'Service'];
const ETATS_MATERIEL = ['Disponible', 'Affecté', 'En maintenance', 'Hors service'];
const PERIODICITES   = ['unique' => 'Coût unique', 'mensuel' => 'Mensuel', 'annuel' => 'Annuel'];

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
    migrate($pdo);
    return $pdo;
}

/**
 * Schéma versionné (PRAGMA user_version) : chaque entrée s'applique une seule fois.
 * Pour faire évoluer la base, ajouter une entrée à la fin, sans modifier les précédentes.
 */
function migrate(PDO $pdo): void {
    $migrations = [
        1 => function (PDO $pdo): void {
            $pdo->exec("
                CREATE TABLE users (
                    id            INTEGER PRIMARY KEY AUTOINCREMENT,
                    prenom        TEXT NOT NULL,
                    nom           TEXT NOT NULL,
                    email         TEXT UNIQUE COLLATE NOCASE,
                    telephone     TEXT NOT NULL DEFAULT '',
                    fonction      TEXT NOT NULL DEFAULT '',
                    type          TEXT NOT NULL DEFAULT 'interne',
                    societe       TEXT NOT NULL DEFAULT '',
                    tjm           REAL,
                    competences   TEXT NOT NULL DEFAULT '',
                    role          TEXT NOT NULL DEFAULT 'contributeur',
                    acces         INTEGER NOT NULL DEFAULT 0,
                    actif         INTEGER NOT NULL DEFAULT 1,
                    password_hash TEXT,
                    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
                );

                -- Départements et services : un seul arbre. La racine (Comité de direction) n'a pas de parent.
                CREATE TABLE entites (
                    id             INTEGER PRIMARY KEY AUTOINCREMENT,
                    type           TEXT NOT NULL CHECK (type IN ('departement', 'service')),
                    parent_id      INTEGER REFERENCES entites(id) ON DELETE RESTRICT,
                    nom            TEXT NOT NULL,
                    code           TEXT NOT NULL DEFAULT '',
                    description    TEXT NOT NULL DEFAULT '',
                    responsable_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                    adjoint_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
                    is_root        INTEGER NOT NULL DEFAULT 0
                );

                CREATE TABLE rattachements (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    entite_id  INTEGER NOT NULL REFERENCES entites(id) ON DELETE CASCADE,
                    nature     TEXT NOT NULL DEFAULT 'titulaire',
                    principal  INTEGER NOT NULL DEFAULT 0,
                    date_debut TEXT,
                    date_fin   TEXT,
                    UNIQUE (user_id, entite_id)
                );

                CREATE TABLE profils (
                    id        INTEGER PRIMARY KEY AUTOINCREMENT,
                    categorie TEXT NOT NULL,
                    nom       TEXT NOT NULL,
                    actif     INTEGER NOT NULL DEFAULT 1
                );

                CREATE TABLE categories (
                    id    INTEGER PRIMARY KEY AUTOINCREMENT,
                    type  TEXT NOT NULL CHECK (type IN ('materiel', 'service')),
                    nom   TEXT NOT NULL,
                    actif INTEGER NOT NULL DEFAULT 1
                );

                -- Matériels et services (prestations, abonnements…)
                CREATE TABLE ressources (
                    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                    type                TEXT NOT NULL CHECK (type IN ('materiel', 'service')),
                    categorie_id        INTEGER REFERENCES categories(id) ON DELETE SET NULL,
                    nom                 TEXT NOT NULL,
                    fournisseur         TEXT NOT NULL DEFAULT '',
                    reference           TEXT NOT NULL DEFAULT '',
                    entite_id           INTEGER REFERENCES entites(id) ON DELETE SET NULL,
                    cout                REAL NOT NULL DEFAULT 0,
                    periodicite         TEXT NOT NULL DEFAULT 'unique',
                    numero_serie        TEXT NOT NULL DEFAULT '',
                    quantite            INTEGER NOT NULL DEFAULT 1,
                    localisation        TEXT NOT NULL DEFAULT '',
                    etat                TEXT NOT NULL DEFAULT 'Disponible',
                    date_debut          TEXT,
                    date_fin            TEXT,
                    date_renouvellement TEXT,
                    commentaire         TEXT NOT NULL DEFAULT '',
                    actif               INTEGER NOT NULL DEFAULT 1
                );

                CREATE TABLE programmes (
                    id             INTEGER PRIMARY KEY AUTOINCREMENT,
                    chef_projet_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                    nom            TEXT NOT NULL,
                    description    TEXT NOT NULL DEFAULT '',
                    statut         TEXT NOT NULL DEFAULT 'À cadrer',
                    date_debut     TEXT,
                    date_fin       TEXT,
                    created_at     TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
                );

                CREATE TABLE projets (
                    id             INTEGER PRIMARY KEY AUTOINCREMENT,
                    chef_projet_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                    programme_id   INTEGER REFERENCES programmes(id) ON DELETE SET NULL,
                    nom            TEXT NOT NULL,
                    description    TEXT NOT NULL DEFAULT '',
                    statut         TEXT NOT NULL DEFAULT 'À cadrer',
                    avancement     INTEGER NOT NULL DEFAULT 0 CHECK (avancement BETWEEN 0 AND 100),
                    date_debut     TEXT,
                    date_fin       TEXT,
                    created_at     TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
                );

                -- Affectation d'une ressource à un projet : une personne, un service « à nommer » ou un matériel/service.
                CREATE TABLE affectations (
                    id           INTEGER PRIMARY KEY AUTOINCREMENT,
                    projet_id    INTEGER NOT NULL REFERENCES projets(id) ON DELETE CASCADE,
                    kind         TEXT NOT NULL CHECK (kind IN ('personne', 'entite', 'ressource')),
                    user_id      INTEGER REFERENCES users(id) ON DELETE CASCADE,
                    entite_id    INTEGER REFERENCES entites(id) ON DELETE CASCADE,
                    ressource_id INTEGER REFERENCES ressources(id) ON DELETE CASCADE,
                    profil_id    INTEGER REFERENCES profils(id) ON DELETE SET NULL,
                    charge_jh    REAL NOT NULL DEFAULT 0,
                    quantite     INTEGER NOT NULL DEFAULT 1,
                    date_debut   TEXT,
                    date_fin     TEXT,
                    commentaire  TEXT NOT NULL DEFAULT '',
                    created_at   TEXT NOT NULL DEFAULT (datetime('now'))
                );

                CREATE TABLE parametres (
                    cle    TEXT PRIMARY KEY,
                    valeur TEXT NOT NULL
                );

                CREATE INDEX idx_entites_parent ON entites(parent_id);
                CREATE INDEX idx_ratt_user ON rattachements(user_id);
                CREATE INDEX idx_ratt_entite ON rattachements(entite_id);
                CREATE INDEX idx_programmes_chef ON programmes(chef_projet_id);
                CREATE INDEX idx_projets_chef ON projets(chef_projet_id);
                CREATE INDEX idx_projets_programme ON projets(programme_id);
                CREATE INDEX idx_aff_projet ON affectations(projet_id);
                CREATE INDEX idx_aff_user ON affectations(user_id);
            ");
            seed($pdo);
        },
    ];

    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    foreach ($migrations as $v => $apply) {
        if ($v <= $version) { continue; }
        $pdo->beginTransaction();
        $apply($pdo);
        $pdo->exec('PRAGMA user_version = ' . (int)$v);
        $pdo->commit();
    }
}

/** Données de départ : racine de l'organisation, profils, catégories, paramètres. */
function seed(PDO $pdo): void {
    $pdo->exec("INSERT INTO entites (type, parent_id, nom, code, is_root) VALUES ('departement', NULL, 'Comité de direction', 'CODIR', 1)");

    $profils = [
        'Gouvernance'           => ['Sponsor', 'Directeur de programme', 'Membre du comité de pilotage', 'PMO'],
        'Pilotage'              => ['Chef de projet', 'Chef de projet technique', 'Chef de projet métier (MOA)', 'Scrum Master', 'Product Owner'],
        'Métier'                => ['Référent métier', 'Utilisateur clé', 'Responsable conduite du changement', 'Formateur'],
        'Conception'            => ["Architecte d'entreprise", 'Architecte technique', 'Architecte solution', 'Business analyst'],
        'Technique'             => ['Ingénieur système', 'Ingénieur réseau', 'Ingénieur cloud', 'DBA', 'Développeur', 'Intégrateur', 'DevOps'],
        'Sécurité & conformité' => ['RSSI', 'Expert cybersécurité', 'DPO', 'Auditeur', 'Juridique'],
        'Qualité'               => ['Responsable recette', 'Testeur', 'Responsable qualité'],
        'Exploitation'          => ['Responsable exploitation', 'Support N1', 'Support N2', 'Support N3', 'Responsable mise en production'],
        'Finances & achats'     => ['Contrôleur de gestion', 'Acheteur'],
        'Externe'               => ['Fournisseur / prestataire', 'Consultant externe', 'Éditeur'],
    ];
    $st = $pdo->prepare('INSERT INTO profils (categorie, nom) VALUES (?, ?)');
    foreach ($profils as $cat => $noms) {
        foreach ($noms as $nom) { $st->execute([$cat, $nom]); }
    }

    $categories = [
        'materiel' => ['Serveur', 'Poste de travail', 'Équipement réseau', 'Stockage', 'Licence logicielle', 'Environnement (recette, préprod)', 'Salle', 'Véhicule', 'Autre'],
        'service'  => ['Abonnement cloud', 'Logiciel SaaS', 'Support éditeur', 'Infogérance', 'Prestation au forfait', 'Maintenance', 'Télécom', 'Autre'],
    ];
    $st = $pdo->prepare('INSERT INTO categories (type, nom) VALUES (?, ?)');
    foreach ($categories as $type => $noms) {
        foreach ($noms as $nom) { $st->execute([$type, $nom]); }
    }

    $pdo->exec("INSERT INTO parametres (cle, valeur) VALUES ('tjm_interne', '0'), ('tjm_externe', '0')");
}

function has_users(): bool {
    return (int)db()->query('SELECT COUNT(*) FROM users WHERE acces = 1')->fetchColumn() > 0;
}

function param(string $cle): string {
    static $cache = null;
    if ($cache === null) {
        $cache = array_column(db()->query('SELECT cle, valeur FROM parametres')->fetchAll(), 'valeur', 'cle');
    }
    return $cache[$cle] ?? '';
}

// ---------- Authentification et droits ----------

function current_user(): ?array {
    static $user = false;
    if ($user !== false) { return $user; }
    $user = null;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT id, prenom, nom, email, role FROM users WHERE id = ? AND acces = 1 AND actif = 1');
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

function is_admin(array $user): bool {
    return $user['role'] === 'admin';
}

/** Voit tout le portefeuille (lecture). */
function sees_all(array $user): bool {
    return in_array($user['role'], ['admin', 'direction'], true);
}

/** Peut créer des programmes et des projets. */
function can_create(array $user): bool {
    return in_array($user['role'], ['admin', 'chef_projet'], true);
}

/** Voit les TJM et les coûts. */
function sees_costs(array $user): bool {
    return in_array($user['role'], ['admin', 'direction', 'chef_projet'], true);
}

function require_admin(): array {
    $user = require_login();
    if (!is_admin($user)) {
        http_response_code(403);
        exit('Accès réservé aux administrateurs.');
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

function post_int(string $key): int {
    return (int)($_POST[$key] ?? 0);
}

/** Identifiant facultatif : null si absent ou 0. */
function post_id(string $key): ?int {
    $v = (int)($_POST[$key] ?? 0);
    return $v > 0 ? $v : null;
}

/** Nombre décimal (accepte la virgule), arrondi à $decimals ; null si vide ou invalide. */
function post_decimal(string $key, int $decimals = 3): ?float {
    $v = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], post_str($key, 30));
    if ($v === '' || !is_numeric($v)) { return null; }
    return round(max(0.0, (float)$v), $decimals);
}

/** Date au format AAAA-MM-JJ, ou null si vide/invalide. */
function post_date(string $key): ?string {
    $v = post_str($key, 10);
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/** Valeur choisie dans une liste fermée ; $default sinon. */
function post_choice(string $key, array $allowed, string $default): string {
    $v = post_str($key, 60);
    return in_array($v, $allowed, true) ? $v : $default;
}

function post_statut(): string {
    return post_choice('statut', STATUTS, STATUTS[0]);
}

// ---------- Utilitaires ----------

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never {
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

function full_name(array $u, string $prefix = ''): string {
    return trim(($u[$prefix . 'prenom'] ?? '') . ' ' . ($u[$prefix . 'nom'] ?? ''));
}

function date_fr(?string $d): string {
    if (!$d) { return '—'; }
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return $dt ? $dt->format('d/m/Y') : '—';
}

/** Jours/homme : jusqu'à 3 décimales, sans zéros inutiles (0,25 · 1,5 · 12). */
function jh(float $v): string {
    return rtrim(rtrim(number_format($v, 3, ',', "\u{202F}"), '0'), ',');
}

function euros(float $v): string {
    return number_format($v, 0, ',', "\u{202F}") . "\u{00A0}€";
}

/** Valeur décimale pour un champ <input type="number"> (point décimal, sans zéros inutiles). */
function num_value(?float $v): string {
    if ($v === null) { return ''; }
    return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
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

/** <option> pour une liste de valeurs (tableau simple) ou de clés => libellés (tableau associatif). */
function options(array $items, ?string $current, bool $assoc = false): string {
    $html = '';
    foreach ($items as $k => $label) {
        $value = $assoc ? (string)$k : (string)$label;
        $html .= '<option value="' . e($value) . '"' . ($value === (string)$current ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
}

function statut_options(string $current): string {
    return options(STATUTS, $current);
}

require __DIR__ . '/model.php';
