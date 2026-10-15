<?php
/**
 * Règles métier partagées : organisation (arbre), visibilité des projets,
 * listes de choix et valorisation des affectations.
 */

// ---------- Organisation ----------

/** Toutes les entités, indexées par id, avec les noms des responsables. */
function entites(): array {
    static $all = null;
    if ($all !== null) { return $all; }
    $rows = db()->query("
        SELECT e.*, r.prenom AS resp_prenom, r.nom AS resp_nom, a.prenom AS adj_prenom, a.nom AS adj_nom
        FROM entites e
        LEFT JOIN users r ON r.id = e.responsable_id
        LEFT JOIN users a ON a.id = e.adjoint_id
        ORDER BY e.is_root DESC, e.type, e.nom COLLATE NOCASE
    ")->fetchAll();
    $all = array_column($rows, null, 'id');
    return $all;
}

/** Enfants de chaque entité : [parent_id => [id, …]], départements avant services. */
function entites_children(): array {
    $children = [];
    foreach (entites() as $id => $e) {
        if ($e['parent_id'] !== null) {
            $children[(int)$e['parent_id']][] = $id;
        }
    }
    return $children;
}

function entite_root_id(): int {
    foreach (entites() as $id => $e) {
        if ($e['is_root']) { return (int)$id; }
    }
    throw new RuntimeException('Racine de l\'organisation introuvable.');
}

/** Ids de l'entité et de toutes ses descendantes. */
function entite_descendants(int $id): array {
    $children = entites_children();
    $out = [$id];
    for ($i = 0; $i < count($out); $i++) {
        foreach ($children[$out[$i]] ?? [] as $c) { $out[] = $c; }
    }
    return $out;
}

/** Chemin lisible : « Comité de direction › Infrastructure › Réseau ». */
function entite_path(int $id): string {
    $all = entites();
    $parts = [];
    while (isset($all[$id])) {
        array_unshift($parts, $all[$id]['nom']);
        $id = (int)$all[$id]['parent_id'];
    }
    return implode(' › ', $parts);
}

/**
 * Entités dans l'ordre de l'arbre avec leur profondeur, pour des listes indentées.
 * @return array<int, array{id:int, depth:int, e:array}>
 */
function entites_tree_flat(?callable $filter = null): array {
    $all = entites();
    $children = entites_children();
    $out = [];
    $walk = function (int $id, int $depth) use (&$walk, &$out, $all, $children, $filter): void {
        if (!$filter || $filter($all[$id])) {
            $out[] = ['id' => $id, 'depth' => $depth, 'e' => $all[$id]];
        }
        foreach ($children[$id] ?? [] as $c) { $walk($c, $depth + 1); }
    };
    $walk(entite_root_id(), 0);
    return $out;
}

/** <option> indentées des entités. $only : 'departement' | 'service' | null ; $exclude : ids à masquer. */
function entite_options(?int $current, ?string $only = null, array $exclude = []): string {
    $html = '';
    foreach (entites_tree_flat() as $row) {
        $e = $row['e'];
        if (in_array($row['id'], $exclude, true) || ($only && $e['type'] !== $only)) { continue; }
        $prefix = str_repeat("\u{00A0}\u{00A0}\u{00A0}", $row['depth']) . ($e['type'] === 'service' ? '◦ ' : '');
        $html .= '<option value="' . $row['id'] . '"' . ($row['id'] === $current ? ' selected' : '') . '>'
               . e($prefix . $e['nom']) . '</option>';
    }
    return $html;
}

function entite_type_label(string $type): string {
    return $type === 'service' ? 'Service' : 'Département';
}

// ---------- Personnes ----------

/** Personnes actives, pour les listes de choix : [id => « Prénom Nom »]. */
function users_list(?array $roles = null): array {
    $sql = 'SELECT id, prenom, nom FROM users WHERE actif = 1';
    $params = [];
    if ($roles) {
        $sql .= ' AND role IN (' . implode(',', array_fill(0, count($roles), '?')) . ')';
        $params = $roles;
    }
    $st = db()->prepare($sql . ' ORDER BY nom COLLATE NOCASE, prenom COLLATE NOCASE');
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll() as $u) { $out[(int)$u['id']] = full_name($u); }
    return $out;
}

/** <option> de personnes, avec une première option vide. */
function user_options(array $users, ?int $current, string $empty = '— Aucun —'): string {
    $html = '<option value="0">' . e($empty) . '</option>';
    foreach ($users as $id => $name) {
        $html .= '<option value="' . $id . '"' . ($id === $current ? ' selected' : '') . '>' . e($name) . '</option>';
    }
    return $html;
}

/** Chefs de projet possibles : administrateurs et chefs de projet actifs. */
function chefs_list(): array {
    return users_list(['admin', 'chef_projet']);
}

/** TJM applicable à une personne : le sien, sinon le TJM par défaut de son type. */
function tjm_personne(?float $tjm, string $type): float {
    if ($tjm !== null && $tjm > 0) { return $tjm; }
    return (float)param($type === 'externe' ? 'tjm_externe' : 'tjm_interne');
}

// ---------- Profils et catégories ----------

/** Profils regroupés par catégorie : [catégorie => [id => nom]]. Les inactifs sont exclus sauf $keep. */
function profils_grouped(?int $keep = null): array {
    $st = db()->prepare('SELECT id, categorie, nom FROM profils WHERE actif = 1 OR id = ? ORDER BY categorie, nom');
    $st->execute([$keep ?? 0]);
    $out = [];
    foreach ($st->fetchAll() as $p) { $out[$p['categorie']][(int)$p['id']] = $p['nom']; }
    return $out;
}

function profil_options(?int $current): string {
    $html = '<option value="0">— Profil —</option>';
    foreach (profils_grouped($current) as $cat => $items) {
        $html .= '<optgroup label="' . e($cat) . '">';
        foreach ($items as $id => $nom) {
            $html .= '<option value="' . $id . '"' . ($id === $current ? ' selected' : '') . '>' . e($nom) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

function categories_list(string $type, ?int $keep = null): array {
    $st = db()->prepare('SELECT id, nom FROM categories WHERE type = ? AND (actif = 1 OR id = ?) ORDER BY nom');
    $st->execute([$type, $keep ?? 0]);
    return array_column($st->fetchAll(), 'nom', 'id');
}

// ---------- Visibilité des programmes et projets ----------

/** Condition SQL (alias pr) limitant les projets à ceux que l'utilisateur peut voir. */
function projets_visibles_sql(array $user): array {
    if (sees_all($user)) {
        return ['1 = 1', []];
    }
    $affecte = "EXISTS (SELECT 1 FROM affectations a WHERE a.projet_id = pr.id AND a.kind = 'personne' AND a.user_id = ?)";
    if ($user['role'] === 'chef_projet') {
        return ["(pr.chef_projet_id = ? OR $affecte)", [$user['id'], $user['id']]];
    }
    return [$affecte, [$user['id']]];
}

/** Condition SQL (alias p) limitant les programmes : les siens, ou ceux qui contiennent un projet visible. */
function programmes_visibles_sql(array $user): array {
    if (sees_all($user)) {
        return ['1 = 1', []];
    }
    [$cond, $params] = projets_visibles_sql($user);
    $sql = "EXISTS (SELECT 1 FROM projets pr WHERE pr.programme_id = p.id AND $cond)";
    if ($user['role'] === 'chef_projet') {
        return ["(p.chef_projet_id = ? OR $sql)", [$user['id'], ...$params]];
    }
    return [$sql, $params];
}

function can_edit_projet(array $user, array $projet): bool {
    return is_admin($user)
        || ($user['role'] === 'chef_projet' && (int)$projet['chef_projet_id'] === (int)$user['id']);
}

function can_edit_programme(array $user, array $programme): bool {
    return can_edit_projet($user, $programme);
}

/** Projet visible par l'utilisateur, ou null. */
function find_projet_visible(int $id, array $user): ?array {
    [$cond, $params] = projets_visibles_sql($user);
    $st = db()->prepare("SELECT pr.*, pg.nom AS programme, u.prenom AS chef_prenom, u.nom AS chef_nom
                         FROM projets pr
                         LEFT JOIN programmes pg ON pg.id = pr.programme_id
                         LEFT JOIN users u ON u.id = pr.chef_projet_id
                         WHERE pr.id = ? AND $cond");
    $st->execute([$id, ...$params]);
    return $st->fetch() ?: null;
}

// ---------- Valorisation ----------

/** Nombre de périodes facturées d'un service récurrent sur la durée de l'affectation (1 si pas de dates). */
function nb_periodes(string $periodicite, ?string $debut, ?string $fin): int {
    if ($periodicite === 'unique' || !$debut || !$fin || $fin < $debut) { return 1; }
    $d = new DateTime($debut);
    $f = new DateTime($fin);
    $diff = $d->diff($f);
    $mois = $diff->y * 12 + $diff->m + ($diff->d > 0 ? 1 : 0);
    $mois = max(1, $mois);
    return $periodicite === 'mensuel' ? $mois : (int)ceil($mois / 12);
}

/**
 * Affectations d'un ou plusieurs projets, avec libellés et coût calculé.
 * Coût : personne → charge × TJM (le sien ou celui par défaut de son type) ;
 *        service à nommer → charge × TJM interne par défaut ;
 *        matériel/service → coût × quantité × nombre de périodes.
 */
function affectations(array $projetIds): array {
    if (!$projetIds) { return []; }
    $in = implode(',', array_fill(0, count($projetIds), '?'));
    $st = db()->prepare("
        SELECT a.*, pf.nom AS profil,
               u.prenom, u.nom, u.type AS user_type, u.tjm AS user_tjm, u.fonction,
               en.nom AS entite_nom, en.type AS entite_type,
               r.nom AS ressource_nom, r.type AS ressource_type, r.cout AS ressource_cout, r.periodicite,
               c.nom AS ressource_categorie
        FROM affectations a
        LEFT JOIN profils pf ON pf.id = a.profil_id
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN entites en ON en.id = a.entite_id
        LEFT JOIN ressources r ON r.id = a.ressource_id
        LEFT JOIN categories c ON c.id = r.categorie_id
        WHERE a.projet_id IN ($in)
        ORDER BY CASE a.kind WHEN 'personne' THEN 0 WHEN 'entite' THEN 1 ELSE 2 END, a.date_debut IS NULL, a.date_debut, a.id
    ");
    $st->execute($projetIds);
    $rows = $st->fetchAll();
    foreach ($rows as &$a) {
        $charge = (float)$a['charge_jh'];
        if ($a['kind'] === 'personne') {
            $a['tjm_applique'] = tjm_personne($a['user_tjm'] !== null ? (float)$a['user_tjm'] : null, (string)$a['user_type']);
            $a['cout'] = $charge * $a['tjm_applique'];
        } elseif ($a['kind'] === 'entite') {
            $a['tjm_applique'] = (float)param('tjm_interne');
            $a['cout'] = $charge * $a['tjm_applique'];
        } else {
            $a['tjm_applique'] = null;
            $a['cout'] = (float)$a['ressource_cout'] * (int)$a['quantite']
                       * nb_periodes((string)$a['periodicite'], $a['date_debut'], $a['date_fin']);
        }
    }
    unset($a);
    return $rows;
}

/** Totaux par projet : [projet_id => ['jh' => …, 'cout' => …, 'a_nommer' => …]]. */
function totaux_affectations(array $projetIds): array {
    $out = [];
    foreach (affectations($projetIds) as $a) {
        $t = &$out[(int)$a['projet_id']];
        $t ??= ['jh' => 0.0, 'cout' => 0.0, 'a_nommer' => 0];
        $t['cout'] += $a['cout'];
        if ($a['kind'] !== 'ressource') { $t['jh'] += (float)$a['charge_jh']; }
        if ($a['kind'] === 'entite') { $t['a_nommer']++; }
        unset($t);
    }
    return $out;
}
