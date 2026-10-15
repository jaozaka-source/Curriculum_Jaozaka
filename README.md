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

## Intranet chef de projet (`/intranet/`)

Interface privée de suivi de projets : **Récapitulatif**, **Programmes**, **Projets**, **Ressources** et **Administration**.
Un chef de projet gère plusieurs programmes et plusieurs projets ; un programme regroupe plusieurs projets.
Le chef de projet affecte à ses projets des personnes, des services « à nommer » et des matériels / services ;
le coût estimé est calculé à partir des TJM (propre à la personne, sinon TJM par défaut interne / externe).

```
intranet/index.php                Redirige vers la connexion ou le récapitulatif
intranet/setup.php                Création du premier compte administrateur (fermée dès qu'un compte existe)
intranet/login.php / logout.php   Connexion / déconnexion
intranet/recap.php                Indicateurs, échéances, ressources à nommer, projets par statut, programmes actifs
intranet/programmes.php           Programmes (chef de projet désigné)
intranet/projets.php              Liste filtrable des projets + création / modification
intranet/projet.php               Détail d'un projet + affectation des ressources (charge en J/H, 3 décimales)
intranet/ressources-humaines.php  Annuaire (internes / externes, rattachements, TJM)
intranet/personne.php             Fiche personne : rattachements, responsabilités, affectations
intranet/materiels.php            Matériels & services (coût unique, mensuel ou annuel)
intranet/organigramme.php         Arbre des départements et services, responsables et membres
intranet/admin-*.php              Utilisateurs, organisation, profils projet, catégories, TJM par défaut
intranet/inc/                     Code commun (base, droits, règles métier, gabarit) — non accessible depuis le web
intranet/data/                    Base SQLite créée automatiquement — non accessible, jamais dans Git
intranet/assets/                  Styles de l'intranet
```

Rôles : Administrateur (tout), Direction / PMO (lecture de tout), Chef de projet (ses programmes et projets),
Contributeur et Lecteur (projets auxquels ils sont affectés).

- PHP 8.1 ou plus + SQLite (inclus chez Hostinger) : rien à configurer, la base `data/intranet.sqlite` se crée au premier accès.
- Le schéma est versionné (`migrate()` dans `inc/bootstrap.php`) : les évolutions futures s'appliquent automatiquement sans perdre les données.
- ⚠️ **Juste après la mise en ligne**, ouvrir `https://jaozaka.com/intranet/` et créer le compte : tant qu'aucun compte n'existe, la page de création est ouverte à tous.
- Sauvegarde : télécharger `public_html/intranet/data/intranet.sqlite` via le Gestionnaire de fichiers de hPanel.

## Modifier le contenu

- Textes : directement dans les fichiers `.html`.
- Logos JRConsulting et monentrepriselocale.fr : remplacer `assets/img/clients/jrconsulting.png` et `assets/img/clients/monentrepriselocale.png` (mêmes noms).
- CV : remplacer `assets/cv/CV_Jaozaka_Razafimahazo.pdf` (garder le même nom).
- Si le domaine n'est pas `jaozaka.com` : adapter `sitemap.xml`, `robots.txt`, les balises `canonical` / `og:` des pages et `FROM_EMAIL`.
