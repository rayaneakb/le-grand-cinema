# Tests et qualité du code

## Contrôles disponibles

| Outil | Contrôle | Commande |
| --- | --- | --- |
| PHPUnit | Validation des données, API, permissions et règles de réservation sur PostgreSQL | `php bin/phpunit` depuis `backend` |
| Playwright | Parcours React dans Chromium avec réponses de l’API simulées | `npm run test:e2e` depuis `frontend` |
| Playwright avec Symfony | Réservation, PDF, annulation, historique et droits avec une base temporaire | `npm run test:real` depuis `frontend` |
| Oxlint | Analyse du code JavaScript et React | `npm run lint` depuis `frontend` |
| Vite | Compilation du frontend | `npm run build` depuis `frontend` |
| Doctrine | Correspondance entre les entités et le schéma installé | `php bin/console doctrine:schema:validate` depuis `backend` |

Les tests unitaires de `RegistrationValidatorTest` vérifient les champs d’inscription sans démarrer Symfony ni utiliser la base. Les tests de contrôleurs et de services vérifient les échanges avec Symfony et PostgreSQL. Les tests de concurrence lancent deux processus PHP séparés sur une base dédiée.

La suite de 79 tests contrôle les états de l’interface avec une API simulée. Trois tests supplémentaires exécutent le parcours avec React, Symfony et PostgreSQL, sans intercepter les réponses. Les essais manuels complètent ces contrôles pour les mails Brevo et les affiches Cloudinary.

La commande `npm run test:real` crée une base locale temporaire, applique les migrations et prépare un film, une salle et trois comptes fictifs. Les mails sont désactivés. Après les essais, elle vérifie les montants et la libération des sièges, puis supprime uniquement cette base. Les ports 8003 et 5175 doivent être libres. Le rôle PostgreSQL doit pouvoir créer une base, ou utiliser `PG_ADMIN_USER=rayane npm run test:real` sur le poste de développement.

Les trois tests ont réussi le 2 novembre 2026. Ils vérifient la réservation sans compte, le téléchargement d’un PDF réel, l’annulation, la récupération de cette réservation après connexion, la recherche au guichet et les droits de modification du tarif et de consultation des statistiques.

## Couverture PHP

Mesure locale du 2 novembre 2026, sur l’ensemble de `backend/src`, avec PHP 8.4.25, PHPUnit 13.4.5 et PCOV 1.0.12.

| Mesure | Résultat |
| --- | --- |
| Tests PHP | 116 réussis |
| Assertions | 615 |
| Lignes exécutées | 1 230 sur 1 596, soit 77,06 % |
| Méthodes entièrement couvertes | 100 sur 239, soit 41,84 % |

Cette mesure regroupe les tests unitaires et les tests d’intégration. Les fichiers non exécutés restent dans le calcul. PCOV mesure les lignes exécutées, sans couvrir tous les chemins possibles. Le code des processus PHP enfants utilisés pour la concurrence n’est pas collecté dans ce rapport.

Pour générer les rapports avec PCOV déjà chargé dans PHP, depuis `backend` :

```bash
php -d pcov.enabled=1 bin/phpunit --coverage-html coverage/backend --coverage-clover coverage/backend.xml --coverage-text=coverage/backend.txt
```

Sur le poste de développement, l’extension a été compilée séparément pour PHP 8.4 et se charge uniquement pour la commande de couverture :

```bash
php -d extension=var/tools/pcov.so -d pcov.directory=src bin/phpunit --coverage-html coverage/backend --coverage-clover coverage/backend.xml --coverage-text=coverage/backend.txt
```

Le fichier `var/tools/pcov.so` dépend du système et de la version PHP. Il reste local et ne fait pas partie du dépôt. Une autre installation peut utiliser [PCOV](https://github.com/krakjoe/pcov) ou Xdebug selon la [documentation PHPUnit](https://docs.phpunit.de/en/13.4/code-coverage.html).

Ouvrir `backend/coverage/backend/index.html` pour consulter le détail par fichier. Les rapports générés sont exclus de Git. Il faut les régénérer après une modification du code ou des tests avant d’en reprendre les chiffres dans le dossier.

## Risque de double réservation

Deux clients peuvent sélectionner le même siège avant de confirmer. Une simple vérification à l’affichage laisserait passer une double réservation.

La confirmation verrouille la séance, relit les disponibilités et enregistre la réservation dans une transaction. L’index unique partiel de PostgreSQL interdit aussi deux places actives pour le même siège et la même séance. Si un siège manque, aucune des places demandées par le second client n’est réservée.

`ReservationConcurrencyTest` confirme deux demandes concurrentes sur le même siège et vérifie qu’une seule réservation est créée. Il vérifie également que deux envois avec la même clé de confirmation retrouvent une seule réservation.

## Correction d’un message de conflit invisible

Pendant l’essai de création des séances, un horaire trop proche d’une séance existante était refusé, mais le message apparaissait après toute la liste. L’utilisateur pouvait penser que le bouton ne fonctionnait pas.

Le message apparaît dans la modale, sous le bouton d’enregistrement. Le formulaire conserve les valeurs saisies pour les corriger.

Le test Playwright « un conflit reste visible dans la modale avec l’horaire saisi » reproduit ce cas avec douze séances affichées. Il vérifie que le message est visible dans la fenêtre et que l’horaire saisi est conservé.


## Sauvegarde et restauration

Le 1er novembre 2026, un export PostgreSQL a été restauré dans une base locale distincte. Les données des onze tables et les valeurs des séquences correspondaient à la source. Le contrôle porte notamment sur les quatre réservations, leurs huit sièges et les montants enregistrés. Une réservation restaurée a ensuite été relue par l’API Symfony, avec vérification de son statut et de son total.

Depuis la racine du projet, avec les outils PostgreSQL et k6 installés :

```bash
php backend/bin/verify-backup.php --keep
php backend/bin/run-load-check.php
```

Le compte PostgreSQL utilisé pour créer la copie doit avoir le droit de créer une base. Sur le poste de développement, les paramètres sont :

```bash
PG_BIN_DIRECTORY=/opt/homebrew/opt/postgresql@17/bin PG_ADMIN_USER=rayane php backend/bin/verify-backup.php --keep
php backend/bin/run-load-check.php
```

L’option `--keep` conserve la copie pour le contrôle par l’API et le test de charge. Sans cette option, la copie est supprimée après comparaison. Après un essai avec `--keep`, supprimer uniquement la base temporaire nommée dans le rapport. Les exports et le rapport de restauration restent dans `backend/var/backup-check`, hors de Git. Ils contiennent des données de la base locale et ne doivent pas être publiés.

## Test de charge

Le scénario k6 utilise une copie restaurée de la base et un serveur Symfony isolé sur `127.0.0.1:8002`, avec quatre processus PHP. Les mails sont désactivés. Il alterne consultation des films, disponibilité des sièges, confirmation puis annulation de réservations fictives. Les paliers montent à 10, 50 et 100 utilisateurs virtuels sur environ 80 secondes.

Résultats du 1er novembre 2026 :

| Mesure | Résultat |
| --- | --- |
| Requêtes HTTP | 8 152 |
| Échecs HTTP | 0 |
| Vérifications k6 | 8 151 réussies, aucune en échec |
| Réservations créées puis annulées | 338 |
| Temps de réponse p95, films | 19,55 ms |
| Temps de réponse p95, sièges | 18,27 ms |
| Temps de réponse p95, confirmation | 18,42 ms |
| Sièges actifs en double après l’essai | 0 |
| Réservations de test encore actives | 0 |
| Incohérences de montant, de salle ou de séance | 0 |

Les seuils k6 sont respectés. Les réponses de conflit attendues ne sont pas classées comme des erreurs HTTP, mais leur contenu est contrôlé. Ces mesures concernent les réponses de l’API sur le poste local, sans réseau distant ni affichage React. Elles ne démontrent pas la capacité de l’offre gratuite Render. Le chargement complet de la page est mesuré séparément ci-dessous.

Le premier essai a été écarté, car les journaux avaient bloqué la sortie du serveur de test. Après correction du dispositif, l’essai ci-dessus a été exécuté sur une nouvelle copie de la base.

Le rapport k6 est généré dans `backend/coverage/load-summary.json`. Le rapport de restauration indique aussi le résultat de la lecture par l’API et des contrôles de cohérence après la charge. Les bases temporaires utilisées pour ces essais ont été supprimées après vérification.

## Fenêtres de l’administration

Les formulaires des films et des séances s’ouvrent dans une modale. Les tests vérifient le maintien du focus dans la fenêtre, sa fermeture avec Échap, le retour au bouton d’ouverture et l’absence de débordement horizontal sur un écran de 390 pixels. La modification d’une séance affiche son horaire actuel. Lorsqu’elle possède des réservations, les champs du film et de la salle restent désactivés.

## Affichage sur différentes tailles d’écran

La recette `responsive.spec.js` parcourt les pages à 320, 390, 768 et 1 440 pixels de large dans Chromium. Elle utilise des noms, une adresse mail, un titre et un synopsis longs. Elle couvre la programmation, la connexion, l’inscription, l’activation, les mots de passe, le compte, la réservation et son annulation, les étapes de sélection jusqu’à la confirmation, ainsi que les résultats et les modales de l’administration.

Les contrôles vérifient l’absence de débordement horizontal de la page et des modales, ainsi que l’accès aux boutons d’enregistrement et de fermeture. Un débordement des listes déroulantes avec les longs titres a été corrigé pendant cette recette. Les réponses de l’API sont simulées. Ces essais utilisent des tailles de fenêtre, sans reproduire un appareil physique ni Safari.

## Chargement du site déployé

Le 2 novembre 2026, vingt navigations à chaud ont été mesurées dans Chromium à 1 440 × 900 pixels, sur le site Render. Le chronométrage s’arrête quand la programmation, les polices et les affiches visibles sont chargées. Les vingt chargements sont sous trois secondes, avec un p95 de 821 ms. Le premier chargement réussi de cette série a pris 1 139 ms.

Un essai précédent a renvoyé HTTP 503 avant cette série. Sa cause n’est pas établie et le temps de réveil après mise en veille n’a pas été mesuré. Le seuil à chaud est respecté sur cet échantillon, sans limitation réseau ni utilisateurs concurrents. Ce contrôle ne garantit pas une disponibilité permanente du service gratuit.
