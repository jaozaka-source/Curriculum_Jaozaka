<?php
/**
 * Matériels & services : inventaire des ressources non humaines.
 * Consultation pour tous ; création et modification par les administrateurs.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user  = require_login();
$admin = is_admin($user);
$costs = sees_costs($user);

function find_ressource(int $id): ?array {
    $st = db()->prepare('SELECT * FROM ressources WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// ---------- Actions ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!$admin) { redirect('materiels.php'); }
    $id = post_int('id');
    $existing = $id ? find_ressource($id) : null;
    if ($id && !$existing) { redirect('materiels.php'); }

    if (($_POST['action'] ?? '') === 'delete') {
        $used = db()->prepare('SELECT COUNT(*) FROM affectations WHERE ressource_id = ?');
        $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) {
            db()->prepare('UPDATE ressources SET actif = 0 WHERE id = ?')->execute([$id]);
            flash('Cette ressource est affectée à des projets : elle a été désactivée au lieu d\'être supprimée.');
        } else {
            db()->prepare('DELETE FROM ressources WHERE id = ?')->execute([$id]);
            flash('Ressource supprimée.');
        }
        redirect('materiels.php');
    }

    $type = $existing ? $existing['type'] : post_choice('type', array_keys(TYPES_RESSOURCE), 'materiel');
    $categorieId = post_id('categorie_id');
    if ($categorieId && !isset(categories_list($type, $categorieId)[$categorieId])) { $categorieId = null; }
    $entiteId = post_id('entite_id');
    if ($entiteId && !isset(entites()[$entiteId])) { $entiteId = null; }

    $row = [
        'categorie_id'        => $categorieId,
        'nom'                 => post_str('nom', 160),
        'fournisseur'         => post_str('fournisseur', 160),
        'reference'           => post_str('reference', 120),
        'entite_id'           => $entiteId,
        'cout'                => post_decimal('cout', 2) ?? 0.0,
        'periodicite'         => $type === 'service' ? post_choice('periodicite', array_keys(PERIODICITES), 'unique') : 'unique',
        'numero_serie'        => $type === 'materiel' ? post_str('numero_serie', 120) : '',
        'quantite'            => $type === 'materiel' ? max(1, post_int('quantite')) : 1,
        'localisation'        => $type === 'materiel' ? post_str('localisation', 160) : '',
        'etat'                => $type === 'materiel' ? post_choice('etat', ETATS_MATERIEL, ETATS_MATERIEL[0]) : 'Disponible',
        'date_debut'          => post_date('date_debut'),
        'date_fin'            => $type === 'service' ? post_date('date_fin') : null,
        'date_renouvellement' => $type === 'service' ? post_date('date_renouvellement') : null,
        'commentaire'         => post_str('commentaire', 2000),
        'actif'               => isset($_POST['actif']) ? 1 : 0,
    ];
    if ($row['nom'] === '') {
        flash('Le nom est obligatoire.');
        redirect('materiels.php?action=' . ($id ? 'edit&id=' . $id : 'new&type=' . $type));
    }

    $cols = array_keys($row);
    if ($existing) {
        db()->prepare('UPDATE ressources SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?')
            ->execute([...array_values($row), $id]);
        flash('Ressource mise à jour.');
    } else {
        db()->prepare('INSERT INTO ressources (type, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')')
            ->execute([$type, ...array_values($row)]);
        flash(TYPES_RESSOURCE[$type] . ' ajouté.');
    }
    redirect('materiels.php');
}

// ---------- Formulaire ----------

$action = $_GET['action'] ?? '';
if ($admin && ($action === 'new' || $action === 'edit')) {
    $type = array_key_exists($_GET['type'] ?? '', TYPES_RESSOURCE) ? $_GET['type'] : 'materiel';
    $r = ['id' => 0, 'type' => $type, 'categorie_id' => null, 'nom' => '', 'fournisseur' => '', 'reference' => '',
          'entite_id' => null, 'cout' => null, 'periodicite' => 'unique', 'numero_serie' => '', 'quantite' => 1,
          'localisation' => '', 'etat' => 'Disponible', 'date_debut' => null, 'date_fin' => null,
          'date_renouvellement' => null, 'commentaire' => '', 'actif' => 1];
    if ($action === 'edit') {
        $r = find_ressource((int)($_GET['id'] ?? 0)) ?? redirect('materiels.php');
    }
    $type = $r['type'];
    $label = TYPES_RESSOURCE[$type];
    page_header($r['id'] ? 'Modifier : ' . $r['nom'] : 'Nouveau ' . mb_strtolower($label), 'materiels', $user);
    ?>
      <p><a class="back" href="materiels.php">← Matériels &amp; services</a></p>
      <?php if (!$r['id']): ?>
        <p class="tabs">
          <?php foreach (TYPES_RESSOURCE as $t => $l): ?>
            <a href="materiels.php?action=new&amp;type=<?= $t ?>"<?= $t === $type ? ' aria-current="page"' : '' ?>><?= e($l) ?></a>
          <?php endforeach; ?>
        </p>
      <?php endif; ?>
      <form method="post" class="form card">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <div class="row">
          <label>Nom
            <input name="nom" required maxlength="160" value="<?= e($r['nom']) ?>">
          </label>
          <label>Catégorie
            <select name="categorie_id">
              <option value="0">— Aucune —</option>
              <?= options(categories_list($type, $r['categorie_id'] !== null ? (int)$r['categorie_id'] : null), $r['categorie_id'] !== null ? (string)$r['categorie_id'] : null, true) ?>
            </select>
          </label>
        </div>
        <div class="row">
          <label>Fournisseur
            <input name="fournisseur" maxlength="160" value="<?= e($r['fournisseur']) ?>">
          </label>
          <label><?= $type === 'service' ? 'Référence du contrat' : 'Référence' ?>
            <input name="reference" maxlength="120" value="<?= e($r['reference']) ?>">
          </label>
          <label>Département / service propriétaire
            <select name="entite_id"><option value="0">— Aucun —</option><?= entite_options($r['entite_id'] !== null ? (int)$r['entite_id'] : null) ?></select>
          </label>
        </div>
        <div class="row">
          <label>Coût (€)<?= $type === 'materiel' ? ' <small>unitaire</small>' : '' ?>
            <input name="cout" type="number" min="0" step="0.01" inputmode="decimal" value="<?= e(num_value($r['cout'] !== null ? (float)$r['cout'] : null)) ?>">
          </label>
          <?php if ($type === 'service'): ?>
            <label>Périodicité du coût
              <select name="periodicite"><?= options(PERIODICITES, $r['periodicite'], true) ?></select>
            </label>
          <?php else: ?>
            <label>Quantité
              <input name="quantite" type="number" min="1" step="1" value="<?= (int)$r['quantite'] ?>">
            </label>
            <label>État
              <select name="etat"><?= options(ETATS_MATERIEL, $r['etat']) ?></select>
            </label>
          <?php endif; ?>
        </div>
        <?php if ($type === 'materiel'): ?>
          <div class="row">
            <label>Numéro de série
              <input name="numero_serie" maxlength="120" value="<?= e($r['numero_serie']) ?>">
            </label>
            <label>Localisation
              <input name="localisation" maxlength="160" value="<?= e($r['localisation']) ?>">
            </label>
            <label>Date d'acquisition
              <input name="date_debut" type="date" value="<?= e($r['date_debut']) ?>">
            </label>
          </div>
        <?php else: ?>
          <div class="row">
            <label>Début du contrat
              <input name="date_debut" type="date" value="<?= e($r['date_debut']) ?>">
            </label>
            <label>Fin du contrat
              <input name="date_fin" type="date" value="<?= e($r['date_fin']) ?>">
            </label>
            <label>Renouvellement
              <input name="date_renouvellement" type="date" value="<?= e($r['date_renouvellement']) ?>">
            </label>
          </div>
        <?php endif; ?>
        <label>Commentaire
          <textarea name="commentaire" rows="3" maxlength="2000"><?= e($r['commentaire']) ?></textarea>
        </label>
        <label class="check"><input type="checkbox" name="actif" value="1"<?= $r['actif'] ? ' checked' : '' ?>> Actif (proposé lors des affectations)</label>
        <div class="actions">
          <button class="btn btn-primary" type="submit">Enregistrer</button>
          <a class="btn btn-ghost" href="materiels.php">Annuler</a>
        </div>
      </form>
    <?php
    page_footer($user);
    exit;
}

// ---------- Liste ----------

$filtreType = array_key_exists($_GET['type'] ?? '', TYPES_RESSOURCE) ? $_GET['type'] : '';
$recherche  = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));

$sql = "SELECT r.*, c.nom AS categorie, e.nom AS entite,
               (SELECT COUNT(*) FROM affectations a WHERE a.ressource_id = r.id) AS nb_affectations
        FROM ressources r
        LEFT JOIN categories c ON c.id = r.categorie_id
        LEFT JOIN entites e ON e.id = r.entite_id
        WHERE 1 = 1";
$params = [];
if ($filtreType !== '') {
    $sql .= ' AND r.type = ?';
    $params[] = $filtreType;
}
if ($recherche !== '') {
    $sql .= " AND (r.nom || ' ' || r.fournisseur || ' ' || r.reference || ' ' || r.numero_serie || ' ' || COALESCE(c.nom, '')) LIKE ?";
    $params[] = '%' . $recherche . '%';
}
$st = db()->prepare($sql . ' ORDER BY r.actif DESC, r.type, r.nom COLLATE NOCASE');
$st->execute($params);
$ressources = $st->fetchAll();

page_header('Matériels & services', 'materiels', $user);
?>
      <div class="toolbar">
        <form method="get" class="filters">
          <label><span class="sr-only">Rechercher</span>
            <input type="search" name="q" value="<?= e($recherche) ?>" placeholder="Nom, fournisseur, référence…">
          </label>
          <label><span class="sr-only">Type</span>
            <select name="type" onchange="this.form.submit()">
              <option value="">Matériels et services</option>
              <?= options(TYPES_RESSOURCE, $filtreType, true) ?>
            </select>
          </label>
          <button class="btn btn-ghost" type="submit">Rechercher</button>
        </form>
        <?php if ($admin): ?>
          <span class="actions">
            <a class="btn btn-primary" href="materiels.php?action=new&amp;type=materiel">+ Matériel</a>
            <a class="btn btn-primary" href="materiels.php?action=new&amp;type=service">+ Service</a>
          </span>
        <?php endif; ?>
      </div>

      <?php if (!$ressources): ?>
        <div class="empty card">
          <p>Aucun matériel ni service<?= ($filtreType || $recherche !== '') ? ' pour ce filtre' : " pour l'instant" ?>.</p>
          <p class="muted">Exemples : serveurs, licences, abonnement cloud, support éditeur, prestation d'infogérance.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap card">
          <table>
            <thead><tr>
              <th>Nom</th><th>Type</th><th>Propriétaire</th><th>Détail</th>
              <?php if ($costs): ?><th>Coût</th><?php endif; ?>
              <th>Projets</th>
              <?php if ($admin): ?><th><span class="sr-only">Actions</span></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($ressources as $r): ?>
              <tr<?= $r['actif'] ? '' : ' class="inactive"' ?>>
                <td>
                  <strong><?= e($r['nom']) ?></strong>
                  <div class="sub"><?= e(implode(' · ', array_filter([$r['fournisseur'], $r['reference']], fn($v) => $v !== ''))) ?></div>
                </td>
                <td>
                  <?= e(TYPES_RESSOURCE[$r['type']]) ?>
                  <?php if ($r['categorie'] !== null): ?><div class="sub"><?= e($r['categorie']) ?></div><?php endif; ?>
                </td>
                <td><?= $r['entite'] !== null ? e($r['entite']) : '<span class="muted">—</span>' ?></td>
                <td>
                  <?php if ($r['type'] === 'materiel'): ?>
                    <?= e($r['etat']) ?> · qté <?= (int)$r['quantite'] ?>
                    <?php if ($r['localisation'] !== ''): ?><div class="sub"><?= e($r['localisation']) ?></div><?php endif; ?>
                  <?php else: ?>
                    <?= $r['date_fin'] ? 'Jusqu\'au ' . date_fr($r['date_fin']) : '<span class="muted">Sans échéance</span>' ?>
                    <?php if ($r['date_renouvellement']): ?><div class="sub">Renouvellement : <?= date_fr($r['date_renouvellement']) ?></div><?php endif; ?>
                  <?php endif; ?>
                  <?php if (!$r['actif']): ?><div class="sub">Inactif</div><?php endif; ?>
                </td>
                <?php if ($costs): ?>
                  <td><?= euros((float)$r['cout']) ?><?php if ($r['type'] === 'service' && $r['periodicite'] !== 'unique'): ?><div class="sub"><?= e(mb_strtolower(PERIODICITES[$r['periodicite']])) ?></div><?php endif; ?></td>
                <?php endif; ?>
                <td><?= (int)$r['nb_affectations'] ?></td>
                <?php if ($admin): ?>
                  <td class="row-actions">
                    <a href="materiels.php?action=edit&amp;id=<?= (int)$r['id'] ?>">Modifier</a>
                    <form method="post" data-confirm="Supprimer cette ressource ? Si elle est affectée à des projets, elle sera seulement désactivée.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="link danger" type="submit">Supprimer</button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
<?php
page_footer($user);
