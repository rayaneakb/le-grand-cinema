# Objectif de l'application

L’application permet aux spectateurs de consulter les films à l’affiche, de voir les séances disponibles, de choisir leurs sièges et de réserver. Le paiement s’effectue au cinéma.

L’interface est développée avec React pour le frontend et Symfony gère la partie backend, les règles de réservation et les échanges avec la base de données PostgreSQL.

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
php -S 127.0.0.1:8000 -t public router.php
```

## Frontend

Dans un autre terminal, depuis `frontend` :

```bash
npm ci
npm run dev -- --host 127.0.0.1
```

Ouvrir `http://127.0.0.1:5173`. Vite transmet les requêtes `/api` et les affiches `/demo` au serveur PHP sur le port `8000`. Les deux terminaux doivent rester ouverts pendant l’utilisation locale.

`npm run build` prépare les fichiers du frontend pour le déploiement. Le déploiement n’est pas encore configuré.

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

Les tests actuels de l’interface simulent les réponses de l’API. Ils couvrent le chargement, la programmation vide, la panne, la nouvelle tentative, une séance complète, le filtre par jour et un affichage de 390 pixels sans débordement horizontal.

`npm run lint` lance l’analyse du code frontend.

# Documentation

- [Modèle de données](docs/modele-donnees.md)
- [API de programmation](docs/api.md)
