<?php
/**
 * Première installation : création du compte administrateur.
 * Accessible uniquement tant qu'aucun compte avec accès n'existe.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (has_users()) {
    redirect('login.php');
}

$error = '';
$prenom = $nom = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $prenom   = post_str('prenom', 80);
    $nom      = post_str('nom', 80);
    $email    = post_str('email', 160);
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    if ($prenom === '' || $nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Indiquez votre prénom, votre nom et une adresse e-mail valide.';
    } elseif (mb_strlen($password) < 10) {
        $error = 'Le mot de passe doit contenir au moins 10 caractères.';
    } elseif ($password !== $confirm) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } else {
        $st = db()->prepare("INSERT INTO users (prenom, nom, email, role, acces, password_hash)
                             VALUES (?, ?, ?, 'admin', 1, ?)");
        $st->execute([$prenom, $nom, $email, password_hash($password, PASSWORD_DEFAULT)]);
        login_user((int)db()->lastInsertId());
        flash('Compte administrateur créé. Bienvenue dans votre intranet !');
        redirect('recap.php');
    }
}

page_header('Première connexion');
?>
    <h1>Créer votre compte</h1>
    <p class="muted">Aucun compte n'existe encore. Ce premier compte sera <strong>administrateur</strong> : il pourra ensuite créer les autres utilisateurs.</p>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>Prénom
        <input name="prenom" required maxlength="80" autocomplete="given-name" value="<?= e($prenom) ?>">
      </label>
      <label>Nom
        <input name="nom" required maxlength="80" autocomplete="family-name" value="<?= e($nom) ?>">
      </label>
      <label>E-mail
        <input name="email" type="email" required maxlength="160" autocomplete="username" value="<?= e($email) ?>">
      </label>
      <label>Mot de passe <small>(10 caractères minimum)</small>
        <input name="password" type="password" required minlength="10" autocomplete="new-password">
      </label>
      <label>Confirmer le mot de passe
        <input name="password_confirm" type="password" required minlength="10" autocomplete="new-password">
      </label>
      <button class="btn btn-primary" type="submit">Créer le compte</button>
    </form>
<?php
page_footer();
