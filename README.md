# Objectif de l'application

L’application permet aux spectateurs de consulter les films à l’affiche, de voir les séances disponibles, de choisir leurs sièges et de réserver. Le paiement s’effectue au cinéma.

L’interface est développée avec React pour le frontend et Symfony gère la partie backend les règles de réservation et les échanges avec la base de données PostgreSQL.

# Organisation des branches

- `main` contient les versions stables de l’application.
- `develop` regroupe les fonctionnalités terminées et testées.
- `feature/nom-de-la-fonctionnalite` sert à développer une user story.

Les noms des branches utilisent des minuscules, sans accents et avec des tirets entre les mots.

Chaque branche `feature/...` est créée depuis `develop`. Après développement et vérification, une pull request permet d’intégrer les changements à `develop`. Les versions stables sont ensuite intégrées à `main`.