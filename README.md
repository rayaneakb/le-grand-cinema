# Objectif de l'application

L’application permet aux spectateurs de consulter les films à l’affiche, de voir les séances disponibles, de choisir leurs sièges et de réserver. Le paiement s’effectue au cinéma.

React gère l’interface. Symfony applique les règles de réservation et communique avec PostgreSQL.

# Organisation des branches

- `main` contient les versions stables de l’application.
- `develop` regroupe les fonctionnalités terminées et testées.
- `feature/nom-de-la-fonctionnalite` sert à développer une user story.

Les noms des branches utilisent des minuscules, sans accents et avec des tirets entre les mots.

Chaque branche `feature/...` est créée depuis `develop`. Après développement et vérification, une pull request permet d’intégrer les changements à `develop`. Les versions stables sont ensuite intégrées à `main`.

# Installation locale

Environnement utilisé pour le développement : PHP 8.4, Composer, PostgreSQL 17 et Node.js 24 avec npm.

## Backend

Depuis `backend`, installer les dépendances avec `composer install`. Copier `.env.example` vers `.env`, puis renseigner un secret local dans `.env.local` et la connexion PostgreSQL dans `DATABASE_URL`.

Exemple de configuration à adapter, avec le mot de passe encodé pour une URL si nécessaire :

```dotenv
APP_SECRET=remplacer-par-un-secret-local
DATABASE_URL="postgresql://cinema:MOT_DE_PASSE@127.0.0.1:5432/le_grand_cinema?serverVersion=17&charset=utf8"
```

Le rôle PostgreSQL `cinema` et la base `le_grand_cinema` doivent exister. Les fichiers locaux contenant les secrets sont exclus de Git.

Appliquer les migrations, puis charger les données fictives si la base est vide :

```bash
php bin/console doctrine:migrations:migrate
php bin/console app:load-demo
```

La commande de démonstration ajoute trois films, dix salles, 1 700 sièges, vingt séances réparties sur cinq jours à partir de sa date d’exécution et un tarif de 10 €. Elle ne supprime aucune donnée et refuse d’ajouter la démonstration si des films, salles ou paramètres existent déjà. Les horaires ne se déplacent pas automatiquement les jours suivants.

Démarrer le serveur local depuis `backend` :

```bash
php -d upload_max_filesize=6M -d post_max_size=8M -S 127.0.0.1:8000 -t public router.php
```

## Frontend

Dans un autre terminal, depuis `frontend` :

```bash
npm ci
npm run dev -- --host 127.0.0.1
```

Ouvrir `http://127.0.0.1:5173`. Vite transmet les requêtes `/api` et les affiches `/demo` au serveur PHP sur le port `8000`. Les deux terminaux doivent rester ouverts pendant l’utilisation locale.

`npm run build` prépare les fichiers du frontend pour le déploiement.

# Vérifications

## API et base de données

Créer une base locale distincte `le_grand_cinema_test`, appartenant au même rôle PostgreSQL. Préparer `backend/.env.test.local` avec un secret de test et le même `DATABASE_URL` que dans `.env.local`. La configuration Doctrine ajoute automatiquement le suffixe `_test` en environnement de test.

Depuis `backend` :

```bash
php bin/console doctrine:migrations:migrate --env=test
php bin/phpunit --testdox
```

Les tests utilisent des données temporaires, annulées par une transaction après chaque scénario. Ils refusent de démarrer sur une base dont le nom ne se termine pas par `_test`.

## Interface

Depuis `frontend` :

```bash
npx playwright install chromium
npm run test:e2e
```

Pour observer les tests dans l’interface Playwright :

```bash
npm run test:e2e -- --ui
```

Playwright démarre un serveur sur le port `5174`, distinct du site ouvert sur `5173`. Fermer son interface avant de lancer une seconde exécution qui utiliserait aussi ce port.

Les tests de l’interface simulent les réponses de l’API. Ils couvrent la programmation, les comptes, la réservation, les annulations, le PDF et l’administration, ainsi que les erreurs et plusieurs affichages sur téléphone. Une seconde suite exécute trois parcours avec Symfony et une base PostgreSQL locale temporaire. Depuis `frontend`, lancer `npm run test:real` avec un rôle PostgreSQL autorisé à créer une base. Sur le poste de développement, utiliser `PG_ADMIN_USER=rayane npm run test:real`. Les ports 8003 et 5175 doivent être libres. La base de recette est supprimée après le contrôle et aucun mail réel n’est envoyé. Les essais Brevo et Cloudinary restent séparés.

`npm run lint` lance l’analyse du code frontend.

# Documentation

- [Modèle de données](docs/modele-donnees.md)
- [Routes de l’API](docs/api.md)
- [Tests, qualité et couverture](docs/tests.md)

# Déploiement sur Render

Le Dockerfile compile React, puis installe Symfony dans une image PHP 8.4 avec Apache. Apache sert l’interface et transmet les routes `/api` à Symfony sous la même adresse.

Créer un Web Service lié au dépôt GitHub avec ces paramètres.

| Paramètre | Valeur |
| --- | --- |
| Language | Docker |
| Branch | develop pour la démonstration |
| Region | Frankfurt |
| Root Directory | Vide |
| Dockerfile Path | ./Dockerfile |
| Docker Command | Vide, le Dockerfile définit le démarrage |
| Compute | Free |
| Health Check Path | /api/status |
| Auto-Deploy | Off pour le premier déploiement manuel |

Ajouter les variables dans Render, sans les enregistrer dans le dépôt.

| Variable | Valeur |
| --- | --- |
| APP_ENV | prod |
| APP_DEBUG | 0 |
| APP_SECRET | Secret généré dans Render |
| DEFAULT_URI | Adresse publique HTTPS du service Render |
| DATABASE_URL | URL de connexion directe fournie par Neon |
| LOAD_DEMO_DATA | 1 pour charger la démonstration sur une base vide |

Le projet Neon utilise PostgreSQL 18. Ajouter `serverVersion=18&charset=utf8` aux paramètres de son URL, en conservant `sslmode=require` et les autres paramètres fournis. La configuration PostgreSQL 17 du développement local reste dans `.env.local`.

Au démarrage, le conteneur applique les migrations et prépare le cache Symfony. Avec `LOAD_DEMO_DATA=1`, il tente aussi de charger les données fictives. La commande conserve les données déjà présentes et ne décale pas leurs dates. Remettre cette variable à `0` après le premier chargement.

La route `/api/status` renvoie `200` si Symfony peut interroger PostgreSQL et `503` si la connexion échoue. Elle ne renvoie aucun détail de connexion.

Les logs de démarrage indiquent le début et la fin des migrations, de la préparation du cache et du lancement d’Apache. Une étape en échec affiche son nom et son code de sortie. La connexion PostgreSQL utilise un délai de 10 secondes par tentative de connexion. Ce délai ne limite pas la durée des requêtes SQL.

Après le déploiement, vérifier l’accueil, les affiches, `/api/films` et `/api/status`. Les instances gratuites peuvent se mettre en veille et nécessiter un temps de réveil. Les données restent dans Neon, indépendamment du conteneur Render.

## Envoi des mails

Symfony Mailer utilise l’API HTTPS de Brevo. Définir ces variables dans `backend/.env.local` pour les essais et dans Render pour le site déployé.

```dotenv
MAILER_DSN="brevo+api://CLE_API_BREVO@default"
MAILER_FROM="Le Grand Cinéma <le.grand.cinema.contact@gmail.com>"
```

La clé doit autoriser les emails transactionnels. Elle ne doit pas être placée dans le frontend ni enregistrée dans Git. Encoder les caractères réservés de la clé si nécessaire pour le format URL du DSN.

Les tests automatisés utilisent `null://null` et n’envoient aucun mail réel. Pour envoyer un mail réel de vérification de la connexion, depuis `backend` :

```bash
php bin/console mailer:test destinataire@example.com --from="Le Grand Cinéma <le.grand.cinema.contact@gmail.com>"
```

Les liens d’activation utilisent `DEFAULT_URI`. En local, utiliser `http://127.0.0.1:5173` pour ouvrir l’écran React d’activation, avec les deux serveurs démarrés sur le même ordinateur. Sur Render, elle correspond à l’adresse publique HTTPS du service. L’expéditeur Gmail vérifié peut être remplacé par un domaine `brevosend.com` lors de l’envoi par Brevo.

## Comptes et connexion

Les pages `/inscription`, `/activation`, `/connexion` et `/compte` sont servies par React. Les routes d’API associées sont détaillées dans `docs/api.md`. La récupération du mot de passe est accessible sur `/mot-de-passe-oublie` et `/reinitialiser-mot-de-passe`. Le compte permet aussi de retrouver ses réservations et celles effectuées sans compte avec la même adresse mail vérifiée.

La migration `Version20261031160000` crée la table technique `sessions`, utilisée par Symfony en production. Les sessions passent par une connexion PDO distincte, avec les mêmes paramètres PostgreSQL et SSL que Doctrine. Cette table est exclue des comparaisons du modèle ORM, car elle ne représente pas une entité métier.

Sur Render, les cookies de session nécessitent HTTPS. La configuration de production tient compte des en-têtes du proxy pour le protocole et l’adresse IP. Elle est prévue pour un conteneur accessible par le proxy Render.


### Confirmation PDF

Le client peut télécharger sa confirmation depuis le récapitulatif de réservation ou son compte. Les réservations sans compte sont accessibles par le lien personnel reçu par mail. Une réservation annulée ne permet plus de télécharger le PDF.

Dompdf est installé avec Composer. Le conteneur PHP inclut l’extension `mbstring` nécessaire à la génération. Le modèle se trouve dans `backend/templates/pdf/reservation.php` et les coordonnées du cinéma sont définies dans `backend/config/services.yaml`.


### Comptes du personnel

Depuis `backend`, lancer `php bin/console app:create-staff`. La commande demande l’adresse mail, le prénom, le nom, le rôle et un mot de passe masqué de 15 à 128 caractères. Elle refuse une adresse déjà utilisée et ne modifie jamais les droits d’un compte existant.

L’administrateur du projet crée et valide ces comptes. L’inscription publique crée uniquement des clients. Aucun mot de passe n’est passé dans les arguments de commande ni enregistré en clair. L’employé et la direction se connectent sur la même page que les clients, puis accèdent à « Administration » dans le menu du compte.

L’espace `/admin` permet de rechercher les réservations par référence ou adresse mail. La direction peut également modifier le tarif unique et consulter les statistiques par date de séance. Le personnel gère les films, leurs affiches et les séances.


### Affiches des films

Renseigner `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY` et `CLOUDINARY_API_SECRET` dans `backend/.env.local`, puis dans les variables Render avant le déploiement. Ces identifiants restent côté Symfony.

Le formulaire accepte JPEG, PNG et WebP jusqu’à 5 Mo. Symfony vérifie le contenu du fichier. Une nouvelle affiche est envoyée à Cloudinary avant l’enregistrement du film. L’ancienne est supprimée après la sauvegarde en base. En cas d’échec de suppression, le film reste enregistré et un message invite à retirer l’ancienne image dans Cloudinary. Si la réponse de Cloudinary se perd, une image peut rester stockée sans être liée à un film.

Le conteneur accepte les transferts jusqu’à 6 Mo par fichier et 8 Mo par requête ; l’application limite les affiches à 5 Mo. Pour les essais locaux, lancer le serveur PHP depuis `backend` avec ces mêmes plafonds :

```bash
php -d upload_max_filesize=6M -d post_max_size=8M -S 127.0.0.1:8000 -t public router.php
```

Les séances à venir sont recalculées lorsqu’une durée change. Une modification qui ne laisse plus une heure de marge entre deux séances dans la même salle est refusée, sans modifier le film ni les séances.


### Programmation des séances

L’administration affiche les séances par date, avec les salles et les films disponibles. Les horaires saisis sont ceux de Paris ; ils sont enregistrés en UTC. Symfony calcule la fin du film et refuse les chevauchements ou une marge inférieure à une heure dans une même salle.

Sans réservation, le film, la salle et l’horaire peuvent être modifiés. Avec des réservations, seul l’horaire peut changer, si l’ancien et le nouveau début sont tous deux à au moins 24 heures du moment de la modification. Une séance annulée ou déjà commencée ne peut plus être modifiée. L’annulation par le cinéma reste possible avant le début, même à moins de 24 heures. Elle annule les réservations et libère les sièges sans supprimer l’historique.

Les notifications sont conservées dans `screening_notice` dans la même transaction que le changement. Après validation, Symfony tente un lot de dix envois. Les messages restants peuvent être relancés depuis l’administration, avec une minute entre deux tentatives en échec. Aucun traitement automatique en arrière-plan n’est configuré. Si le fournisseur accepte un mail puis que la réponse se perd, une nouvelle tentative peut envoyer ce mail deux fois.
