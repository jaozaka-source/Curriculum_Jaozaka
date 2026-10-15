<?php
/**
 * Détail d'un projet et affectation des ressources :
 * personnes, services « à nommer » et matériels / services.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user   = require_login();
$projet = find_projet_visible((int)($_GET['id'] ?? $_POST['projet_id'] ?? 0), $user) ?? redirect('projets.php');
$pid    = (int)$projet['id'];
$edit   = can_edit_projet($user, $projet);
$costs  = sees_costs($user);
$self   = 'projet.php?id=' . $pid;

function find_affectation(int $id, int $pid): ?array {
    $st = db()->prepare('SELECT * FROM affectations WHERE id = ? AND projet_id = ?');
    $st->execute([$id, $pid]);
    return $st->fetch() ?: null;
}

/** Ressources (matériels et services) actives, ou celle déjà affectée. */
function ressources_options(?int $current): string {
    $st = db()->prepare('SELECT id, type, nom, reference FROM ressources WHERE actif = 1 OR id = ? ORDER BY type, nom');
    $st->execute([$current ?? 0]);
    $groups = [];
    foreach ($st->fetchAll() as $r) { $groups[$r['type']][] = $r; }
    $html = '<option value="0">— Choisir —</option>';
    foreach (TYPES_RESSOURCE as $type => $label) {
        if (empty($groups[$type])) { continue; }
        $html .= '<optgroup label="' . e($label) . 's">';
        foreach ($groups[$type] as $r) {
            $txt = $r['nom'] . ($r['reference'] !== '' ? ' (' . $r['reference'] . ')' : '');
            $html .= '<option value="' . (int)$r['id'] . '"' . ((int)$r['id'] === $current ? ' selected' : '') . '>' . e($txt) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

// ---------- Actions ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!$edit) {
        flash("Seul le chef de projet ou un administrateur peut modifier les affectations.");
        redirect($self);
    }
    $action = $_POST['action'] ?? '';
    $affId  = post_int('affectation_id');
    $aff    = $affId ? find_affectation($affId, $pid) : null;
    if ($affId && !$aff) { redirect($self); }

    if ($action === 'delete') {
        db()->prepare('DELETE FROM affectations WHERE id = ?')->execute([$affId]);
        flash('Affectation retirée.');
        redirect($self);
    }

    if ($action === 'nommer') {
        $uid = post_id('user_id');
        if ($aff && $aff['kind'] === 'entite' && $uid && isset(users_list()[$uid])) {
            db()->prepare("UPDATE affectations SET kind = 'personne', user_id = ?, entite_id = NULL WHERE id = ?")
                ->execute([$uid, $affId]);
            flash('Ressource nommée.');
        }
        redirect($self);
    }

    // Ajout ou modification
    $kind = $aff ? $aff['kind'] : post_choice('kind', ['personne', 'entite', 'ressource'], 'personne');
    $row  = [
        'user_id'      => null,
        'entite_id'    => null,
        'ressource_id' => null,
        'profil_id'    => post_id('profil_id'),
        'charge_jh'    => post_decimal('charge_jh') ?? 0.0,
        'quantite'     => max(1, post_int('quantite')),
        'date_debut'   => post_date('date_debut'),
        'date_fin'     => post_date('date_fin'),
        'commentaire'  => post_str('commentaire', 1000),
    ];
    if ($kind === 'personne') {
        $row['user_id'] = post_id('user_id');
        $ok = $row['user_id'] && isset(users_list()[$row['user_id']]);
        if (!$ok && $aff) { $row['user_id'] = (int)$aff['user_id']; $ok = true; }
    } elseif ($kind === 'entite') {
        $row['entite_id'] = post_id('entite_id');
        $ok = $row['entite_id'] && (entites()[$row['entite_id']]['type'] ?? '') === 'service';
    } else {
        $row['ressource_id'] = post_id('ressource_id');
        $row['profil_id'] = null;
        $row['charge_jh'] = 0.0;
        $ok = (bool)$row['ressource_id'];
    }
    if (!$ok) {
        flash('Choisissez la personne, le service ou la ressource à affecter.');
        redirect($self . ($aff ? '&edit=' . $affId : '&add=' . $kind) . '#affecter');
    }
    if ($row['date_debut'] && $row['date_fin'] && $row['date_fin'] < $row['date_debut']) {
        flash('La date de fin doit être postérieure à la date de début.');
        redirect($self . ($aff ? '&edit=' . $affId : '&add=' . $kind) . '#affecter');
    }

    if ($aff) {
        db()->prepare('UPDATE affectations SET user_id = ?, entite_id = ?, ressource_id = ?, profil_id = ?, charge_jh = ?,
                       quantite = ?, date_debut = ?, date_fin = ?, commentaire = ? WHERE id = ?')
            ->execute([...array_values($row), $affId]);
        flash('Affectation mise à jour.');
    } else {
        db()->prepare('INSERT INTO affectations (user_id, entite_id, ressource_id, profil_id, charge_jh, quantite,
                       date_debut, date_fin, commentaire, kind, projet_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([...array_values($row), $kind, $pid]);
        flash('Ressource affectée au projet.');
    }
    redirect($self);
}

// ---------- Données ----------

$affectations = affectations([$pid]);
$equipe   = array_filter($affectations, fn($a) => $a['kind'] !== 'ressource');
$materiel = array_filter($affectations, fn($a) => $a['kind'] === 'ressource');
$totalJh  = array_sum(array_map(fn($a) => (float)$a['charge_jh'], $equipe));
$coutRh   = array_sum(array_column($equipe, 'cout'));
$coutMat  = array_sum(array_column($materiel, 'cout'));
$aNommer  = count(array_filter($equipe, fn($a) => $a['kind'] === 'entite'));

$editAff  = $edit && isset($_GET['edit']) ? find_affectation((int)$_GET['edit'], $pid) : null;
$nommer   = $edit && isset($_GET['nommer']) ? find_affectation((int)$_GET['nommer'], $pid) : null;
$addKind  = in_array($_GET['add'] ?? '', ['personne', 'entite', 'ressource'], true) ? $_GET['add'] : 'personne';

page_header($projet['nom'], 'projets', $user);
?>
      <p><a class="back" href="projets.php">← Tous les projets</a></p>

      <section class="card project-head">
        <div class="ph-main">
          <p class="sub"><?= $projet['programme'] !== null ? 'Programme : ' . e($projet['programme']) : 'Sans programme' ?></p>
          <p><?= statut_badge($projet['statut']) ?> <?= progress((int)$projet['avancement']) ?></p>
          <?php if ($projet['description'] !== ''): ?><p><?= nl2br(e($projet['description'])) ?></p><?php endif; ?>
          <dl class="facts">
            <div><dt>Chef de projet</dt><dd><?= $projet['chef_nom'] !== null ? e(full_name($projet, 'chef_')) : '—' ?></dd></div>
            <div><dt>Début</dt><dd><?= date_fr($projet['date_debut']) ?></dd></div>
            <div><dt>Fin</dt><dd><?= date_fr($projet['date_fin']) ?></dd></div>
          </dl>
        </div>
        <?php if ($edit): ?>
          <div class="ph-actions">
            <a class="btn btn-ghost" href="projets.php?action=edit&amp;id=<?= $pid ?>">Modifier le projet</a>
            <form method="post" action="projets.php" data-confirm="Supprimer définitivement ce projet et ses affectations ?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $pid ?>">
              <button class="link danger" type="submit">Supprimer le projet</button>
            </form>
          </div>
        <?php endif; ?>
      </section>

      <section class="kpis" aria-label="Synthèse des ressources">
        <div class="kpi card"><span class="n"><?= jh($totalJh) ?></span><span class="l">J/H affectés</span></div>
        <div class="kpi card<?= $aNommer ? ' alert' : '' ?>"><span class="n"><?= $aNommer ?></span><span class="l">Ressources à nommer</span></div>
        <?php if ($costs): ?>
          <div class="kpi card"><span class="n"><?= euros($coutRh) ?></span><span class="l">Coût ressources humaines</span></div>
          <div class="kpi card"><span class="n"><?= euros($coutRh + $coutMat) ?></span><span class="l">Coût total estimé</span></div>
        <?php endif; ?>
      </section>

      <?php if ($nommer && $nommer['kind'] === 'entite'):
          $st = db()->prepare('SELECT u.id, u.prenom, u.nom FROM rattachements r JOIN users u ON u.id = r.user_id
                               WHERE r.entite_id = ? AND u.actif = 1 ORDER BY u.nom, u.prenom');
          $st->execute([$nommer['entite_id']]);
          $membres = [];
          foreach ($st->fetchAll() as $m) { $membres[(int)$m['id']] = full_name($m); }
          $autres = array_diff_key(users_list(), $membres); ?>
        <section class="card highlight" id="affecter">
          <h2>Nommer la ressource — <?= e(entites()[(int)$nommer['entite_id']]['nom'] ?? '') ?></h2>
          <p class="muted">Le profil, la charge et les dates de l'affectation sont conservés.</p>
          <form method="post" class="form inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="projet_id" value="<?= $pid ?>">
            <input type="hidden" name="action" value="nommer">
            <input type="hidden" name="affectation_id" value="<?= (int)$nommer['id'] ?>">
            <label>Personne
              <select name="user_id" required>
                <option value="">— Choisir —</option>
                <?php if ($membres): ?><optgroup label="Membres du service"><?php foreach ($membres as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                <optgroup label="Autres personnes"><?php foreach ($autres as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></optgroup>
              </select>
            </label>
            <div class="actions">
              <button class="btn btn-primary" type="submit">Nommer</button>
              <a class="btn btn-ghost" href="<?= $self ?>">Annuler</a>
            </div>
          </form>
        </section>
      <?php endif; ?>

      <section class="card">
        <h2>Équipe projet</h2>
        <?php if (!$equipe): ?>
          <p class="muted">Aucune personne ni service affecté.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr>
                <th>Ressource</th><th>Profil</th><th>Charge</th><th>Période</th>
                <?php if ($costs): ?><th>TJM</th><th>Coût</th><?php endif; ?>
                <?php if ($edit): ?><th><span class="sr-only">Actions</span></th><?php endif; ?>
              </tr></thead>
              <tbody>
              <?php foreach ($equipe as $a): ?>
                <tr>
                  <td>
                    <?php if ($a['kind'] === 'personne'): ?>
                      <a href="personne.php?id=<?= (int)$a['user_id'] ?>"><strong><?= e(full_name($a)) ?></strong></a>
                      <div class="sub"><?= e(TYPES_PERSONNE[$a['user_type']] ?? '') ?><?= $a['fonction'] !== '' ? ' · ' . e($a['fonction']) : '' ?></div>
                    <?php else: ?>
                      <span class="badge badge-pause">À nommer</span> <strong><?= e($a['entite_nom']) ?></strong>
                    <?php endif; ?>
                    <?php if ($a['commentaire'] !== ''): ?><div class="sub"><?= e($a['commentaire']) ?></div><?php endif; ?>
                  </td>
                  <td><?= $a['profil'] !== null ? e($a['profil']) : '<span class="muted">—</span>' ?></td>
                  <td><?= jh((float)$a['charge_jh']) ?> J/H</td>
                  <td><?= date_fr($a['date_debut']) ?> → <?= date_fr($a['date_fin']) ?></td>
                  <?php if ($costs): ?>
                    <td><?= euros((float)$a['tjm_applique']) ?></td>
                    <td><?= euros($a['cout']) ?></td>
                  <?php endif; ?>
                  <?php if ($edit): ?>
                    <td class="row-actions">
                      <?php if ($a['kind'] === 'entite'): ?><a href="<?= $self ?>&amp;nommer=<?= (int)$a['id'] ?>#affecter">Nommer</a><?php endif; ?>
                      <a href="<?= $self ?>&amp;edit=<?= (int)$a['id'] ?>#affecter">Modifier</a>
                      <form method="post" data-confirm="Retirer cette affectation du projet ?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="projet_id" value="<?= $pid ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="affectation_id" value="<?= (int)$a['id'] ?>">
                        <button class="link danger" type="submit">Retirer</button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <section class="card">
        <h2>Matériels &amp; services</h2>
        <?php if (!$materiel): ?>
          <p class="muted">Aucun matériel ni service affecté.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr>
                <th>Ressource</th><th>Type</th><th>Quantité</th><th>Période</th>
                <?php if ($costs): ?><th>Coût</th><?php endif; ?>
                <?php if ($edit): ?><th><span class="sr-only">Actions</span></th><?php endif; ?>
              </tr></thead>
              <tbody>
              <?php foreach ($materiel as $a): ?>
                <tr>
                  <td>
                    <strong><?= e($a['ressource_nom']) ?></strong>
                    <?php if ($a['ressource_categorie'] !== null): ?><div class="sub"><?= e($a['ressource_categorie']) ?></div><?php endif; ?>
                    <?php if ($a['commentaire'] !== ''): ?><div class="sub"><?= e($a['commentaire']) ?></div><?php endif; ?>
                  </td>
                  <td><?= e(TYPES_RESSOURCE[$a['ressource_type']] ?? '') ?><?= $a['ressource_type'] === 'service' ? ' · ' . e(PERIODICITES[$a['periodicite']] ?? '') : '' ?></td>
                  <td><?= (int)$a['quantite'] ?></td>
                  <td><?= date_fr($a['date_debut']) ?> → <?= date_fr($a['date_fin']) ?></td>
                  <?php if ($costs): ?><td><?= euros($a['cout']) ?></td><?php endif; ?>
                  <?php if ($edit): ?>
                    <td class="row-actions">
                      <a href="<?= $self ?>&amp;edit=<?= (int)$a['id'] ?>#affecter">Modifier</a>
                      <form method="post" data-confirm="Retirer cette ressource du projet ?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="projet_id" value="<?= $pid ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="affectation_id" value="<?= (int)$a['id'] ?>">
                        <button class="link danger" type="submit">Retirer</button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($edit && !$nommer):
          $f = $editAff ?? ['id' => 0, 'kind' => $addKind, 'user_id' => null, 'entite_id' => null, 'ressource_id' => null,
                            'profil_id' => null, 'charge_jh' => null, 'quantite' => 1, 'date_debut' => null,
                            'date_fin' => null, 'commentaire' => ''];
          $kind = $f['kind']; ?>
        <section class="card highlight" id="affecter">
          <h2><?= $f['id'] ? "Modifier l'affectation" : 'Affecter une ressource' ?></h2>
          <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="projet_id" value="<?= $pid ?>">
            <input type="hidden" name="affectation_id" value="<?= (int)$f['id'] ?>">

            <?php if (!$f['id']): ?>
              <fieldset class="choice">
                <legend>Que voulez-vous affecter ?</legend>
                <label><input type="radio" name="kind" value="personne"<?= $kind === 'personne' ? ' checked' : '' ?>> Une personne</label>
                <label><input type="radio" name="kind" value="entite"<?= $kind === 'entite' ? ' checked' : '' ?>> Un service (ressource à nommer)</label>
                <label><input type="radio" name="kind" value="ressource"<?= $kind === 'ressource' ? ' checked' : '' ?>> Un matériel ou un service</label>
              </fieldset>
            <?php else: ?>
              <input type="hidden" name="kind" value="<?= e($kind) ?>">
            <?php endif; ?>

            <div data-show-when="kind=personne">
              <label>Personne
                <select name="user_id"><?= user_options(users_list(), $f['user_id'] !== null ? (int)$f['user_id'] : null, '— Choisir —') ?></select>
              </label>
            </div>
            <div data-show-when="kind=entite">
              <label>Service <small>(la personne sera nommée plus tard)</small>
                <select name="entite_id"><option value="0">— Choisir —</option><?= entite_options($f['entite_id'] !== null ? (int)$f['entite_id'] : null, 'service') ?></select>
              </label>
            </div>
            <div data-show-when="kind=ressource">
              <div class="row">
                <label>Matériel ou service
                  <select name="ressource_id"><?= ressources_options($f['ressource_id'] !== null ? (int)$f['ressource_id'] : null) ?></select>
                </label>
                <label>Quantité
                  <input name="quantite" type="number" min="1" step="1" value="<?= (int)$f['quantite'] ?>">
                </label>
              </div>
            </div>

            <div data-show-when="kind=personne,entite">
              <div class="row">
                <label>Profil projet
                  <select name="profil_id"><?= profil_options($f['profil_id'] !== null ? (int)$f['profil_id'] : null) ?></select>
                </label>
                <label>Charge (J/H) <small>ex. 0,25 · 0,5 · 12,125</small>
                  <input name="charge_jh" type="number" min="0" step="0.001" inputmode="decimal" value="<?= e(num_value($f['charge_jh'] !== null ? (float)$f['charge_jh'] : null)) ?>">
                </label>
              </div>
            </div>

            <div class="row">
              <label>Date de début
                <input name="date_debut" type="date" value="<?= e($f['date_debut']) ?>">
              </label>
              <label>Date de fin
                <input name="date_fin" type="date" value="<?= e($f['date_fin']) ?>">
              </label>
            </div>
            <label>Commentaire
              <input name="commentaire" maxlength="1000" value="<?= e($f['commentaire']) ?>">
            </label>
            <div class="actions">
              <button class="btn btn-primary" type="submit"><?= $f['id'] ? 'Enregistrer' : 'Affecter' ?></button>
              <?php if ($f['id']): ?><a class="btn btn-ghost" href="<?= $self ?>">Annuler</a><?php endif; ?>
            </div>
          </form>
        </section>
      <?php endif; ?>
<?php
page_footer($user);
