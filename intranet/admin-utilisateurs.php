<?php
/**
 * Administration › Utilisateurs : personnes (internes / externes), accès à l'intranet,
 * rôle, TJM et rattachements à plusieurs départements ou services.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user = require_admin();

function find_user(int $id): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Nombre d'administrateurs actifs avec accès, hors $exceptId. */
function autres_admins(int $exceptId): int {
    $st = db()->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND acces = 1 AND actif = 1 AND id <> ?");
    $st->execute([$exceptId]);
    return (int)$st->fetchColumn();
}

// ---------- Actions ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? 'save';
    $id = post_int('id');
    $existing = $id ? find_user($id) : null;
    if ($id && !$existing) { redirect('admin-utilisateurs.php'); }
    $back = 'admin-utilisateurs.php?action=edit&id=' . $id;

    // --- Rattachements ---
    if ($action === 'ratt_add' && $existing) {
        $entiteId = post_id('entite_id');
        if (!$entiteId || !isset(entites()[$entiteId])) {
            flash('Choisissez un département ou un service.');
            redirect($back . '#rattachements');
        }
        $principal = isset($_POST['principal']) ? 1 : 0;
        $count = db()->prepare('SELECT COUNT(*) FROM rattachements WHERE user_id = ?');
        $count->execute([$id]);
        if ((int)$count->fetchColumn() === 0) { $principal = 1; }
        if ($principal) {
            db()->prepare('UPDATE rattachements SET principal = 0 WHERE user_id = ?')->execute([$id]);
        }
        db()->prepare('INSERT INTO rattachements (user_id, entite_id, nature, principal, date_debut, date_fin) VALUES (?, ?, ?, ?, ?, ?)
                       ON CONFLICT (user_id, entite_id) DO UPDATE SET nature = excluded.nature, principal = excluded.principal,
                       date_debut = excluded.date_debut, date_fin = excluded.date_fin')
            ->execute([$id, $entiteId, post_choice('nature', array_keys(NATURES), 'titulaire'), $principal,
                       post_date('date_debut'), post_date('date_fin')]);
        flash('Rattachement enregistré.');
        redirect($back . '#rattachements');
    }
    if (($action === 'ratt_delete' || $action === 'ratt_principal') && $existing) {
        $rid = post_int('rattachement_id');
        if ($action === 'ratt_delete') {
            db()->prepare('DELETE FROM rattachements WHERE id = ? AND user_id = ?')->execute([$rid, $id]);
            // S'il ne reste qu'un rattachement sans principal, il le devient.
            db()->prepare('UPDATE rattachements SET principal = 1 WHERE user_id = ? AND id = (SELECT MIN(id) FROM rattachements WHERE user_id = ?)
                           AND NOT EXISTS (SELECT 1 FROM rattachements WHERE user_id = ? AND principal = 1)')
                ->execute([$id, $id, $id]);
            flash('Rattachement retiré.');
        } else {
            db()->prepare('UPDATE rattachements SET principal = (id = ?) WHERE user_id = ?')->execute([$rid, $id]);
            flash('Rattachement principal modifié.');
        }
        redirect($back . '#rattachements');
    }

    // --- Suppression ---
    if ($action === 'delete' && $existing) {
        if ($id === (int)$user['id']) {
            flash('Vous ne pouvez pas supprimer votre propre compte.');
            redirect('admin-utilisateurs.php');
        }
        $used = db()->prepare('SELECT (SELECT COUNT(*) FROM affectations WHERE user_id = ?)
                                    + (SELECT COUNT(*) FROM projets WHERE chef_projet_id = ?)
                                    + (SELECT COUNT(*) FROM programmes WHERE chef_projet_id = ?)');
        $used->execute([$id, $id, $id]);
        if ((int)$used->fetchColumn() > 0) {
            db()->prepare('UPDATE users SET actif = 0, acces = 0 WHERE id = ?')->execute([$id]);
            flash('Cette personne intervient sur des projets : elle a été désactivée au lieu d\'être supprimée.');
        } else {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('Personne supprimée.');
        }
        redirect('admin-utilisateurs.php');
    }

    // --- Création / modification ---
    $email    = post_str('email', 160);
    $acces    = isset($_POST['acces']) ? 1 : 0;
    $actif    = isset($_POST['actif']) ? 1 : 0;
    $role     = post_choice('role', array_keys(ROLES), 'contributeur');
    $password = (string)($_POST['password'] ?? '');
    $type     = post_choice('type', array_keys(TYPES_PERSONNE), 'interne');
    $tjm      = post_decimal('tjm', 2);

    $errors = [];
    if (post_str('prenom') === '' || post_str('nom') === '') { $errors[] = 'Le prénom et le nom sont obligatoires.'; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "L'adresse e-mail n'est pas valide."; }
    if ($acces && $email === '') { $errors[] = "Une adresse e-mail est nécessaire pour accéder à l'intranet."; }
    if ($email !== '') {
        $dup = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
        $dup->execute([$email, $id]);
        if ((int)$dup->fetchColumn() > 0) { $errors[] = 'Cette adresse e-mail est déjà utilisée.'; }
    }
    if ($password !== '' && mb_strlen($password) < 10) { $errors[] = 'Le mot de passe doit contenir au moins 10 caractères.'; }
    if ($acces && $password === '' && empty($existing['password_hash'])) {
        $errors[] = "Définissez un mot de passe pour donner accès à l'intranet.";
    }
    if ($existing && $id === (int)$user['id'] && ($role !== 'admin' || !$acces || !$actif) && autres_admins($id) === 0) {
        $errors[] = 'Vous êtes le seul administrateur : vous ne pouvez pas retirer votre propre accès administrateur.';
    }
    if ($errors) {
        $_SESSION['form_errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        redirect($id ? $back : 'admin-utilisateurs.php?action=new');
    }

    $row = [
        'prenom'      => post_str('prenom', 80),
        'nom'         => post_str('nom', 80),
        'email'       => $email !== '' ? $email : null,
        'telephone'   => post_str('telephone', 40),
        'fonction'    => post_str('fonction', 160),
        'type'        => $type,
        'societe'     => $type === 'externe' ? post_str('societe', 160) : '',
        'tjm'         => ($tjm !== null && $tjm > 0) ? $tjm : null,
        'competences' => post_str('competences', 1000),
        'role'        => $role,
        'acces'       => $acces,
        'actif'       => $actif,
    ];
    if ($password !== '') {
        $row['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }
    $cols = array_keys($row);
    if ($existing) {
        db()->prepare('UPDATE users SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?')
            ->execute([...array_values($row), $id]);
        flash('Personne mise à jour.');
    } else {
        db()->prepare('INSERT INTO users (' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols) - 1) . ')')
            ->execute(array_values($row));
        $id = (int)db()->lastInsertId();
        flash('Personne créée. Vous pouvez maintenant la rattacher à des départements ou des services.');
    }
    redirect('admin-utilisateurs.php?action=edit&id=' . $id . '#rattachements');
}

// ---------- Formulaire ----------

$action = $_GET['action'] ?? '';
if ($action === 'new' || $action === 'edit') {
    $u = ['id' => 0, 'prenom' => '', 'nom' => '', 'email' => '', 'telephone' => '', 'fonction' => '', 'type' => 'interne',
          'societe' => '', 'tjm' => null, 'competences' => '', 'role' => 'contributeur', 'acces' => 0, 'actif' => 1,
          'password_hash' => null];
    if ($action === 'edit') {
        $u = find_user((int)($_GET['id'] ?? 0)) ?? redirect('admin-utilisateurs.php');
    }
    $errors = $_SESSION['form_errors'] ?? [];
    if (!empty($_SESSION['form_data'])) {
        $d = $_SESSION['form_data'];
        foreach (['prenom', 'nom', 'email', 'telephone', 'fonction', 'type', 'societe', 'competences', 'role'] as $k) {
            $u[$k] = (string)($d[$k] ?? $u[$k]);
        }
        $u['tjm'] = ($d['tjm'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$d['tjm']) : null;
        $u['acces'] = isset($d['acces']) ? 1 : 0;
        $u['actif'] = isset($d['actif']) ? 1 : 0;
    }
    unset($_SESSION['form_errors'], $_SESSION['form_data']);

    $uid = (int)$u['id'];
    $rattachements = [];
    if ($uid) {
        $st = db()->prepare('SELECT * FROM rattachements WHERE user_id = ? ORDER BY principal DESC, id');
        $st->execute([$uid]);
        $rattachements = $st->fetchAll();
    }
    $tjmDefaut = (float)param($u['type'] === 'externe' ? 'tjm_externe' : 'tjm_interne');

    page_header($uid ? full_name($u) : 'Nouvelle personne', 'admin-utilisateurs', $user);
    ?>
      <p><a class="back" href="admin-utilisateurs.php">← Tous les utilisateurs</a><?php if ($uid): ?> · <a class="back" href="personne.php?id=<?= $uid ?>">Voir la fiche</a><?php endif; ?></p>
      <?php foreach ($errors as $err): ?><p class="error" role="alert"><?= e($err) ?></p><?php endforeach; ?>

      <form method="post" class="form card">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $uid ?>">
        <h2>Identité</h2>
        <div class="row">
          <label>Prénom <input name="prenom" required maxlength="80" value="<?= e($u['prenom']) ?>"></label>
          <label>Nom <input name="nom" required maxlength="80" value="<?= e($u['nom']) ?>"></label>
        </div>
        <div class="row">
          <label>E-mail <input name="email" type="email" maxlength="160" value="<?= e($u['email']) ?>"></label>
          <label>Téléphone <input name="telephone" maxlength="40" value="<?= e($u['telephone']) ?>"></label>
        </div>
        <label>Fonction (intitulé de poste) <input name="fonction" maxlength="160" value="<?= e($u['fonction']) ?>"></label>

        <h2>Ressource</h2>
        <fieldset class="choice">
          <legend>Type</legend>
          <?php foreach (TYPES_PERSONNE as $k => $l): ?>
            <label><input type="radio" name="type" value="<?= $k ?>"<?= $u['type'] === $k ? ' checked' : '' ?>> <?= e($l) ?></label>
          <?php endforeach; ?>
        </fieldset>
        <div class="row">
          <div data-show-when="type=externe">
            <label>Société <input name="societe" maxlength="160" value="<?= e($u['societe']) ?>"></label>
          </div>
          <label>TJM (€) <small>laisser vide pour le TJM par défaut (interne : <?= euros((float)param('tjm_interne')) ?>, externe : <?= euros((float)param('tjm_externe')) ?>)</small>
            <input name="tjm" type="number" min="0" step="0.01" inputmode="decimal" value="<?= e(num_value($u['tjm'] !== null ? (float)$u['tjm'] : null)) ?>" placeholder="<?= e(num_value($tjmDefaut)) ?>">
          </label>
        </div>
        <label>Compétences <small>(mots-clés séparés par des virgules)</small>
          <input name="competences" maxlength="1000" value="<?= e($u['competences']) ?>">
        </label>

        <h2>Accès à l'intranet</h2>
        <label class="check"><input type="checkbox" name="acces" value="1"<?= $u['acces'] ? ' checked' : '' ?>> Peut se connecter à l'intranet</label>
        <div class="row">
          <label>Rôle
            <select name="role"><?= options(ROLES, $u['role'], true) ?></select>
          </label>
          <label><?= empty($u['password_hash']) ? 'Mot de passe' : 'Nouveau mot de passe <small>(laisser vide pour ne pas changer)</small>' ?>
            <input name="password" type="password" minlength="10" autocomplete="new-password">
          </label>
        </div>
        <label class="check"><input type="checkbox" name="actif" value="1"<?= $u['actif'] ? ' checked' : '' ?>> Active (décocher pour une personne partie : elle reste dans l'historique des projets)</label>

        <div class="actions">
          <button class="btn btn-primary" type="submit">Enregistrer</button>
          <a class="btn btn-ghost" href="admin-utilisateurs.php">Annuler</a>
        </div>
      </form>

      <?php if ($uid): ?>
        <section class="card" id="rattachements">
          <h2>Rattachements</h2>
          <p class="muted">Une personne peut appartenir à plusieurs départements ou services (par exemple pendant un intérim).</p>
          <?php if ($rattachements): ?>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Département / service</th><th>Nature</th><th>Période</th><th>Principal</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($rattachements as $r): ?>
                  <tr>
                    <td><strong><?= e(entites()[(int)$r['entite_id']]['nom'] ?? '') ?></strong><div class="sub"><?= e(entite_path((int)$r['entite_id'])) ?></div></td>
                    <td><?= e(NATURES[$r['nature']] ?? '') ?></td>
                    <td><?= ($r['date_debut'] || $r['date_fin']) ? date_fr($r['date_debut']) . ' → ' . date_fr($r['date_fin']) : '—' ?></td>
                    <td>
                      <?php if ($r['principal']): ?><span class="badge badge-cours">Principal</span><?php else: ?>
                        <form method="post"><?= csrf_field() ?>
                          <input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="action" value="ratt_principal">
                          <input type="hidden" name="rattachement_id" value="<?= (int)$r['id'] ?>">
                          <button class="link" type="submit">Définir principal</button>
                        </form>
                      <?php endif; ?>
                    </td>
                    <td class="row-actions">
                      <form method="post" data-confirm="Retirer ce rattachement ?"><?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="action" value="ratt_delete">
                        <input type="hidden" name="rattachement_id" value="<?= (int)$r['id'] ?>">
                        <button class="link danger" type="submit">Retirer</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <form method="post" class="form subform">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $uid ?>">
            <input type="hidden" name="action" value="ratt_add">
            <h3>Ajouter un rattachement</h3>
            <div class="row">
              <label>Département ou service
                <select name="entite_id" required><option value="">— Choisir —</option><?= entite_options(null) ?></select>
              </label>
              <label>Nature
                <select name="nature"><?= options(NATURES, 'titulaire', true) ?></select>
              </label>
            </div>
            <div class="row">
              <label>Du <input name="date_debut" type="date"></label>
              <label>Au <input name="date_fin" type="date"></label>
            </div>
            <label class="check"><input type="checkbox" name="principal" value="1"<?= $rattachements ? '' : ' checked' ?>> Rattachement principal</label>
            <div class="actions"><button class="btn btn-primary" type="submit">Ajouter</button></div>
          </form>
        </section>
      <?php endif; ?>
    <?php
    page_footer($user);
    exit;
}

// ---------- Liste ----------

$users = db()->query('SELECT * FROM users ORDER BY actif DESC, nom COLLATE NOCASE, prenom COLLATE NOCASE')->fetchAll();
$principaux = [];
foreach (db()->query('SELECT user_id, entite_id FROM rattachements WHERE principal = 1')->fetchAll() as $r) {
    $principaux[(int)$r['user_id']] = (int)$r['entite_id'];
}

page_header('Utilisateurs', 'admin-utilisateurs', $user);
?>
      <div class="toolbar">
        <p class="muted"><?= count($users) ?> personne<?= count($users) > 1 ? 's' : '' ?> · les personnes sans accès apparaissent comme ressources, sans pouvoir se connecter.</p>
        <a class="btn btn-primary" href="admin-utilisateurs.php?action=new">+ Nouvelle personne</a>
      </div>
      <div class="table-wrap card">
        <table>
          <thead><tr><th>Personne</th><th>Type</th><th>Rattachement principal</th><th>Rôle</th><th>Accès</th><th>TJM</th><th><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): $uid = (int)$u['id']; ?>
            <tr<?= $u['actif'] ? '' : ' class="inactive"' ?>>
              <td><strong><?= e(full_name($u)) ?></strong><div class="sub"><?= e($u['email'] ?? '') ?></div></td>
              <td><?= e(TYPES_PERSONNE[$u['type']] ?? '') ?><?php if ($u['type'] === 'externe' && $u['societe'] !== ''): ?><div class="sub"><?= e($u['societe']) ?></div><?php endif; ?></td>
              <td><?= isset($principaux[$uid]) ? e(entites()[$principaux[$uid]]['nom'] ?? '') : '<span class="muted">—</span>' ?></td>
              <td><?= e(ROLES[$u['role']] ?? '') ?></td>
              <td><?= !$u['actif'] ? '<span class="badge badge-annule">Inactif</span>' : ($u['acces'] ? 'Oui' : '<span class="muted">Non</span>') ?></td>
              <td><?= $u['tjm'] !== null ? euros((float)$u['tjm']) : '<span class="muted">défaut</span>' ?></td>
              <td class="row-actions">
                <a href="admin-utilisateurs.php?action=edit&amp;id=<?= $uid ?>">Modifier</a>
                <?php if ($uid !== (int)$user['id']): ?>
                  <form method="post" data-confirm="Supprimer cette personne ? Si elle intervient sur des projets, elle sera seulement désactivée.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $uid ?>">
                    <button class="link danger" type="submit">Supprimer</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
<?php
page_footer($user);
