<?php
/**
 * Récapitulatif : vue d'ensemble des programmes et projets visibles par l'utilisateur.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user  = require_login();
$today = date('Y-m-d');
$costs = sees_costs($user);

[$cond, $params] = projets_visibles_sql($user);
$st = db()->prepare("
    SELECT pr.*, pg.nom AS programme
    FROM projets pr LEFT JOIN programmes pg ON pg.id = pr.programme_id
    WHERE $cond
");
$st->execute($params);
$projets = $st->fetchAll();
$ids     = array_map('intval', array_column($projets, 'id'));

[$pcond, $pparams] = programmes_visibles_sql($user);
$st = db()->prepare("SELECT COUNT(*) FROM programmes p WHERE $pcond");
$st->execute($pparams);
$nbProgrammes = (int)$st->fetchColumn();

$actif     = fn($p) => !in_array($p['statut'], ['Terminé', 'Annulé'], true);
$nbEnCours = count(array_filter($projets, fn($p) => $p['statut'] === 'En cours'));
$enRetard  = array_filter($projets, fn($p) => $actif($p) && $p['date_fin'] && $p['date_fin'] < $today);

$parStatut = array_count_values(array_column($projets, 'statut'));

$echeances = array_filter($projets, fn($p) => $actif($p) && $p['date_fin']);
usort($echeances, fn($a, $b) => strcmp($a['date_fin'], $b['date_fin']));
$echeances = array_slice($echeances, 0, 6);

$affectations = affectations($ids);
$totalJh  = array_sum(array_map(fn($a) => $a['kind'] !== 'ressource' ? (float)$a['charge_jh'] : 0.0, $affectations));
$totalCout = array_sum(array_column($affectations, 'cout'));
$aNommer  = array_filter($affectations, fn($a) => $a['kind'] === 'entite');
$nomsProjets = array_column($projets, 'nom', 'id');

// Programmes actifs visibles, avec l'avancement moyen de leurs projets
$st = db()->prepare("
    SELECT p.id, p.nom, p.statut, COUNT(pr.id) AS nb, COALESCE(ROUND(AVG(pr.avancement)), 0) AS avancement
    FROM programmes p LEFT JOIN projets pr ON pr.programme_id = p.id
    WHERE $pcond AND p.statut NOT IN ('Terminé', 'Annulé')
    GROUP BY p.id ORDER BY p.nom
");
$st->execute($pparams);
$programmesActifs = $st->fetchAll();

$tjmManquant = is_admin($user) && ((float)param('tjm_interne') <= 0 || (float)param('tjm_externe') <= 0);

page_header('Récapitulatif', 'recap', $user);
?>
      <p class="hello">Bonjour <?= e($user['prenom']) ?>, voici l'état de votre portefeuille.</p>

      <?php if ($tjmManquant): ?>
        <p class="notice">Les TJM par défaut ne sont pas encore définis : les coûts estimés seront incomplets.
          <a href="admin-parametres.php">Définir les TJM par défaut</a></p>
      <?php endif; ?>

      <section class="kpis" aria-label="Indicateurs clés">
        <a class="kpi card" href="programmes.php"><span class="n"><?= $nbProgrammes ?></span><span class="l">Programmes</span></a>
        <a class="kpi card" href="projets.php"><span class="n"><?= count($projets) ?></span><span class="l">Projets</span></a>
        <a class="kpi card" href="projets.php?statut=En+cours"><span class="n"><?= $nbEnCours ?></span><span class="l">Projets en cours</span></a>
        <div class="kpi card<?= $enRetard ? ' alert' : '' ?>"><span class="n"><?= count($enRetard) ?></span><span class="l">En retard</span></div>
        <div class="kpi card"><span class="n"><?= jh($totalJh) ?></span><span class="l">J/H affectés</span></div>
        <div class="kpi card<?= $aNommer ? ' alert' : '' ?>"><span class="n"><?= count($aNommer) ?></span><span class="l">Ressources à nommer</span></div>
        <?php if ($costs): ?>
          <div class="kpi card kpi-wide"><span class="n"><?= euros($totalCout) ?></span><span class="l">Coût total estimé du portefeuille</span></div>
        <?php endif; ?>
      </section>

      <?php if (!$nbProgrammes && !$projets): ?>
        <div class="empty card">
          <p>Votre portefeuille est vide pour l'instant.</p>
          <?php if (can_create($user)): ?>
            <p class="actions">
              <a class="btn btn-primary" href="programmes.php?action=new">Créer un programme</a>
              <a class="btn btn-ghost" href="projets.php?action=new">Créer un projet</a>
            </p>
          <?php endif; ?>
        </div>
      <?php else: ?>
      <div class="grid-2">
        <section class="card">
          <h2>Prochaines échéances</h2>
          <?php if (!$echeances): ?>
            <p class="muted">Aucune échéance renseignée sur les projets actifs.</p>
          <?php else: ?>
            <ul class="list">
              <?php foreach ($echeances as $p): ?>
                <li>
                  <a href="projet.php?id=<?= (int)$p['id'] ?>"><strong><?= e($p['nom']) ?></strong></a>
                  <span class="sub"><?= $p['programme'] !== null ? e($p['programme']) . ' · ' : '' ?><?= statut_badge($p['statut']) ?></span>
                  <span class="date<?= $p['date_fin'] < $today ? ' late' : '' ?>"><?= date_fr($p['date_fin']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>

        <section class="card">
          <h2>Ressources à nommer</h2>
          <?php if (!$aNommer): ?>
            <p class="muted">Toutes les ressources affectées sont nommées.</p>
          <?php else: ?>
            <ul class="list">
              <?php foreach (array_slice($aNommer, 0, 8) as $a): ?>
                <li>
                  <a href="projet.php?id=<?= (int)$a['projet_id'] ?>&amp;nommer=<?= (int)$a['id'] ?>#affecter"><strong><?= e($a['entite_nom']) ?></strong></a>
                  <span class="sub"><?= e($nomsProjets[$a['projet_id']] ?? '') ?><?= $a['profil'] !== null ? ' · ' . e($a['profil']) : '' ?></span>
                  <span class="date"><?= jh((float)$a['charge_jh']) ?> J/H</span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>
      </div>

      <div class="grid-2">
        <section class="card">
          <h2>Projets par statut</h2>
          <ul class="list">
            <?php foreach (STATUTS as $s): ?>
              <li>
                <a href="projets.php?statut=<?= urlencode($s) ?>"><?= statut_badge($s) ?></a>
                <span class="date"><?= (int)($parStatut[$s] ?? 0) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>

        <section class="card">
          <h2>Programmes actifs</h2>
          <?php if (!$programmesActifs): ?>
            <p class="muted">Aucun programme actif.</p>
          <?php else: ?>
            <ul class="list">
              <?php foreach ($programmesActifs as $p): ?>
                <li>
                  <a href="projets.php?programme=<?= (int)$p['id'] ?>"><strong><?= e($p['nom']) ?></strong></a>
                  <span class="sub"><?= (int)$p['nb'] ?> projet<?= $p['nb'] > 1 ? 's' : '' ?></span>
                  <?= progress((int)$p['avancement']) ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>
      </div>
      <?php endif; ?>
<?php
page_footer($user);
