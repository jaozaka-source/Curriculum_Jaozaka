<?php
/**
 * Première installation : création du compte chef de projet.
 * Accessible uniquement tant qu'aucun compte n'existe.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (has_users()) {
    redirect('login.php');
}

$error = '';
$nom = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $nom      = post_str('nom', 120);
    $email    = post_str('email', 160);
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    if ($nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Indiquez un nom et une adresse e-mail valide.';
    } elseif (mb_strlen($password) < 10) {
        $error = 'Le mot de passe doit contenir au moins 10 caractères.';
    } elseif ($password !== $confirm) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } else {
        $st = db()->prepare('INSERT INTO users (email, nom, password_hash) VALUES (?, ?, ?)');
        $st->execute([$email, $nom, password_hash($password, PASSWORD_DEFAULT)]);
        login_user((int)db()->lastInsertId());
        flash('Compte créé. Bienvenue dans votre intranet !');
        redirect('recap.php');
    }
}

page_header('Première connexion');
?>
    <h1>Créer votre compte</h1>
    <p class="muted">Aucun compte n'existe encore. Ce premier compte sera celui du chef de projet.</p>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>Nom complet
        <input name="nom" required maxlength="120" autocomplete="name" value="<?= e($nom) ?>">
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
