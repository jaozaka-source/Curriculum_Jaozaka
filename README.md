# Curriculum_Jaozaka

Site CV de **Jaozaka Razafimahazo** — Directeur / chef de projet infrastructure & sécurité.

Site statique (HTML / CSS / JavaScript) + un petit script PHP pour le formulaire de contact.
Aucune compilation, aucune base de données : il fonctionne tel quel sur un hébergement mutualisé Hostinger.

## Contenu

```
index.html            Accueil : pitch, bandeau clients, compétences clés, méthode, bouton contact
competences.html      Compétences chef de projet + compétences techniques + projets par domaine
experiences.html      Toutes les expériences (dépliables, filtrables par tag)
contact.html          Formulaire de contact, téléchargement du CV, LinkedIn
contact.php           Envoi du formulaire par e-mail à jaozaka@gmail.com
assets/css/style.css  Styles
assets/js/main.js     Menu mobile, animation d'accueil, filtres, envoi du formulaire
assets/img/           Photo, favicon, logos clients
assets/cv/            CV en PDF (téléchargeable)
assets/fonts/         Police Archivo hébergée localement (licence OFL, pas d'appel à Google)
.htaccess             HTTPS forcé + cache navigateur
robots.txt, sitemap.xml
```

## Mettre en ligne sur Hostinger (via GitHub)

1. **Pousser les fichiers dans ce dépôt** (branche `main`), à la racine du dépôt.
2. Dans **hPanel** : *Sites web* → *Gérer* → menu *Avancé* → **Git**.
3. *Créer un nouveau dépôt* :
   - Dépôt : `https://github.com/jaozaka-source/Curriculum_Jaozaka.git`
   - Branche : `main`
   - Répertoire d'installation : **laisser vide** (= `public_html`).
     ⚠️ `public_html` doit être **vide** (supprimer le `default.php` créé par Hostinger via le Gestionnaire de fichiers).
4. Cliquer **Créer**, puis **Déployer**.
5. Mise à jour automatique : cliquer **Déploiement automatique**, copier l'URL du webhook, puis sur GitHub :
   *Settings* → *Webhooks* → *Add webhook* → coller l'URL dans *Payload URL* → *Add webhook*.
   Chaque `git push` mettra alors le site à jour.

## Formulaire de contact

Le script `contact.php` utilise la fonction `mail()` de PHP, disponible chez Hostinger.

Pour une bonne délivrabilité (éviter les spams) :
1. Créer une boîte e-mail sur votre domaine dans hPanel → *E-mails* (ex. `contact@jaozaka.com`).
2. Vérifier que `FROM_EMAIL` en haut de `contact.php` correspond à cette adresse.

Les messages arrivent sur `jaozaka@gmail.com` ; « Répondre » répond directement au visiteur.
Protection anti-spam : champ piège invisible + 1 envoi par minute et par visiteur.

## Modifier le contenu

- Textes : directement dans les fichiers `.html`.
- CV : remplacer `assets/cv/CV_Jaozaka_Razafimahazo.pdf` (garder le même nom).
- Si le domaine n'est pas `jaozaka.com` : adapter `sitemap.xml`, `robots.txt`, les balises `canonical` / `og:` des pages et `FROM_EMAIL`.
