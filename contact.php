<?php
/**
 * Formulaire de contact — envoie un e-mail à jaozaka@gmail.com
 * Compatible hébergement mutualisé Hostinger (fonction mail()).
 *
 * IMPORTANT (Hostinger) : l'expéditeur (FROM_EMAIL) doit être une adresse
 * de votre domaine, idéalement une boîte créée dans hPanel > E-mails
 * (ex. contact@jaozaka.com). Sinon les messages risquent d'arriver en spam.
 */

const TO_EMAIL   = 'jaozaka@gmail.com';
const FROM_EMAIL = 'contact@jaozaka.com';   // ← à adapter à votre domaine
const FROM_NAME  = 'Site CV Jaozaka';

header('X-Content-Type-Options: nosniff');
$wantsJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

function respond(bool $ok, string $error = '', bool $json = true): void {
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($ok ? 200 : 400);
        echo json_encode(['ok' => $ok, 'error' => $error], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: contact.html?' . ($ok ? 'envoye=1' : 'erreur=1'));
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contact.html');
    exit;
}

// Pot de miel anti-robots : un humain ne remplit pas ce champ.
if (!empty($_POST['website'])) {
    respond(true, '', $wantsJson);
}

// Limite simple : 1 envoi par minute et par session.
session_start();
if (!empty($_SESSION['last_send']) && time() - $_SESSION['last_send'] < 60) {
    respond(false, 'Un message vient déjà d\'être envoyé. Merci de patienter une minute.', $wantsJson);
}

$clean = function (string $key, int $max): string {
    $v = trim((string)($_POST[$key] ?? ''));
    $v = str_replace(["\r", "\0"], '', $v);
    return mb_substr($v, 0, $max);
};
$oneLine = fn(string $s): string => str_replace("\n", ' ', $s);

$name    = $oneLine($clean('name', 120));
$company = $oneLine($clean('company', 120));
$email   = $oneLine($clean('email', 160));
$phone   = $oneLine($clean('phone', 40));
$subject = $oneLine($clean('subject', 80));
$message = $clean('message', 5000);
$consent = !empty($_POST['consent']);

$allowed = ['Proposition de mission', 'Proposition de poste', "Demande d'entretien", 'Autre demande'];
if (!in_array($subject, $allowed, true)) { $subject = 'Autre demande'; }

if ($name === '' || $message === '' || !$consent || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Vérifiez les champs obligatoires : nom, e-mail valide, message et consentement.', $wantsJson);
}

$body  = "Nouveau message depuis le site CV\n";
$body .= "==================================\n\n";
$body .= "Nom       : $name\n";
$body .= "Société   : " . ($company ?: '—') . "\n";
$body .= "E-mail    : $email\n";
$body .= "Téléphone : " . ($phone ?: '—') . "\n";
$body .= "Objet     : $subject\n\n";
$body .= "Message :\n$message\n\n";
$body .= "--\nEnvoyé le " . date('d/m/Y à H:i') . " depuis " . ($_SERVER['HTTP_HOST'] ?? 'le site') . "\n";

$encodedSubject = '=?UTF-8?B?' . base64_encode("[Site CV] $subject — $name") . '?=';
$encodedFrom    = '=?UTF-8?B?' . base64_encode(FROM_NAME) . '?=';

$headers  = "From: $encodedFrom <" . FROM_EMAIL . ">\r\n";
$headers .= "Reply-To: $email\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";

$sent = mail(TO_EMAIL, $encodedSubject, $body, $headers, '-f' . FROM_EMAIL);

if ($sent) {
    $_SESSION['last_send'] = time();
    respond(true, '', $wantsJson);
}
respond(false, "L'envoi a échoué. Écrivez-moi directement à jaozaka@gmail.com.", $wantsJson);
