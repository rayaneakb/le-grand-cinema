# Organisation des user stories

Les 21 user stories du bloc 2 disposent chacune d’une branche `feature/us-XX-nom` et d’une pull request. Le tableau conserve les numéros utilisés dans le dossier. Les comptes ont été repris avant les parcours qui nécessitent une connexion.

## Reprise du 2 novembre 2026

Le développement initial regroupait plusieurs stories dans les PR 6 et 8. Cette reprise répartit le code existant par story à partir du modèle de données, dans `integration/user-stories`. Les branches sont créées successivement depuis cette intégration, puis fusionnées par pull request. Les commits et leurs dates d’origine sont conservés.

Les fichiers communs sont ajoutés avec le premier parcours qui les utilise. Les contrôleurs partagés sont complétés par leurs routes et méthodes au fil des stories. Certains composants partagés contiennent aussi la préparation du parcours suivant. Ces étapes intermédiaires ne constituent pas toutes des versions livrables.

Cette reconstruction est une reprise après le développement initial. Elle ne démontre pas que chaque story avait été développée séparément dès le départ. La PR de synthèse intègre le résultat dans `develop`. Pour les prochains développements, chaque nouvelle story partira directement de `develop`.

## Branches et pull requests

| US | Fonction | Branche | Pull request |
| --- | --- | --- | --- |
| 01 | Consulter les films et les séances | `feature/us-01-consulter-programmation` | [PR 22](https://github.com/rayaneakb/le-grand-cinema/pull/22) |
| 02 | Choisir les sièges disponibles | `feature/us-02-choisir-sieges` | [PR 23](https://github.com/rayaneakb/le-grand-cinema/pull/23) |
| 03 | Confirmer une réservation | `feature/us-03-confirmer-reservation` | [PR 24](https://github.com/rayaneakb/le-grand-cinema/pull/24) |
| 04 | Recevoir la confirmation par mail | `feature/us-04-confirmation-mail` | [PR 25](https://github.com/rayaneakb/le-grand-cinema/pull/25) |
| 05 | Annuler une réservation | `feature/us-05-annuler-reservation` | [PR 26](https://github.com/rayaneakb/le-grand-cinema/pull/26) |
| 06 | Télécharger la confirmation PDF | `feature/us-06-confirmation-pdf` | [PR 27](https://github.com/rayaneakb/le-grand-cinema/pull/27) |
| 07 | Recevoir à nouveau son lien personnel | `feature/us-07-renvoyer-lien` | [PR 28](https://github.com/rayaneakb/le-grand-cinema/pull/28) |
| 08 | Ajouter un film et son affiche | `feature/us-08-ajouter-film` | [PR 29](https://github.com/rayaneakb/le-grand-cinema/pull/29) |
| 09 | Modifier un film | `feature/us-09-modifier-film` | [PR 30](https://github.com/rayaneakb/le-grand-cinema/pull/30) |
| 10 | Créer une séance | `feature/us-10-creer-seance` | [PR 31](https://github.com/rayaneakb/le-grand-cinema/pull/31) |
| 11 | Modifier une séance | `feature/us-11-modifier-seance` | [PR 32](https://github.com/rayaneakb/le-grand-cinema/pull/32) |
| 12 | Annuler une séance et prévenir les clients | `feature/us-12-annuler-seance` | [PR 33](https://github.com/rayaneakb/le-grand-cinema/pull/33) |
| 13 | Retrouver une réservation au guichet | `feature/us-13-rechercher-reservation` | [PR 34](https://github.com/rayaneakb/le-grand-cinema/pull/34) |
| 14 | Corriger le mail de réservation au guichet | `feature/us-14-corriger-mail` | [PR 35](https://github.com/rayaneakb/le-grand-cinema/pull/35) |
| 15 | Créer et activer un compte client | `feature/us-15-creer-compte` | [PR 18](https://github.com/rayaneakb/le-grand-cinema/pull/18) |
| 16 | Se connecter | `feature/us-16-connexion` | [PR 19](https://github.com/rayaneakb/le-grand-cinema/pull/19) |
| 17 | Se déconnecter | `feature/us-17-deconnexion` | [PR 20](https://github.com/rayaneakb/le-grand-cinema/pull/20) |
| 18 | Réinitialiser le mot de passe | `feature/us-18-reinitialiser-mot-de-passe` | [PR 21](https://github.com/rayaneakb/le-grand-cinema/pull/21) |
| 19 | Retrouver ses réservations | `feature/us-19-historique-reservations` | [PR 36](https://github.com/rayaneakb/le-grand-cinema/pull/36) |
| 20 | Modifier le tarif unique | `feature/us-20-modifier-tarif` | [PR 37](https://github.com/rayaneakb/le-grand-cinema/pull/37) |
| 21 | Consulter les statistiques en ligne | `feature/us-21-statistiques` | [PR 38](https://github.com/rayaneakb/le-grand-cinema/pull/38) |

## Vérifications

Chaque étape a fait l’objet d’un contrôle de syntaxe des fichiers PHP modifiés et d’un contrôle du diff. Sur l’assemblage final, les 118 tests PHPUnit passent avec 629 assertions, ainsi que les 79 tests Playwright avec API simulée et les 3 parcours avec Symfony et PostgreSQL. Le contrôle JavaScript et la compilation du frontend passent également. Les commandes sont détaillées dans [la documentation des tests](tests.md).

Avant l’ajout de cette documentation, les 196 fichiers suivis de l’assemblage sont identiques, octet pour octet, au commit `17391e6` déjà validé. Aucun secret local ni mot de passe de démonstration n’est ajouté au dépôt. Le site Render conserve sa version pendant cette reprise.

La fonctionnalité complète demandée en question 6 reste consultable dans la [PR 4 vers develop](https://github.com/rayaneakb/le-grand-cinema/pull/4), avec sa documentation dans [docs/api.md](api.md).
