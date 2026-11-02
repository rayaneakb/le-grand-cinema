# API du Grand Cinéma

## Consulter la programmation

`GET /api/films`

Cette route est publique. Elle renvoie les films à l’affiche, avec leurs séances à venir et non annulées. Un film sans séance reste présent avec une liste `screenings` vide. Les films sont triés par titre, puis les séances par date de début.

### Réponse réussie

Statut HTTP `200`, format `application/json`.

```json
{
  "films": [
    {
      "id": 2,
      "title": "Au-delà des étoiles",
      "synopsis": "Une équipe d’explorateurs reçoit un mystérieux signal.",
      "durationMinutes": 138,
      "mainActors": "Sarah Duval, Malik Benali",
      "posterUrl": "/demo/posters/etoiles.svg",
      "screenings": [
        {
          "id": 11,
          "startsAt": "2026-11-01T15:00:00+00:00",
          "room": "Salle 2",
          "availableSeats": 300,
          "full": false
        }
      ]
    }
  ],
  "generatedAt": "2026-11-01T09:00:00+00:00"
}
```

Cet exemple utilise les données fictives de démonstration. Les identifiants et les dates dépendent du contenu de la base.

| Champ | Signification |
|---|---|
| `films` | Liste des films à l’affiche. Vide si aucun film n’est à l’affiche. |
| `durationMinutes` | Durée du film en minutes entières. |
| `posterUrl` | Adresse de l’affiche. Les affiches de démonstration sont servies localement. |
| `screenings` | Séances futures et programmées de ce film. |
| `startsAt` | Date et heure au format ISO 8601, en UTC. React les affiche à l’heure de Paris. |
| `room` | Nom de la salle. |
| `availableSeats` | Nombre de sièges de la salle moins les places actives des réservations confirmées de cette séance. |
| `full` | `true` lorsqu’aucune place n’est disponible. La séance reste dans la réponse. |
| `generatedAt` | Date de préparation de la réponse, en UTC. |

Une place libérée et une réservation annulée ne sont pas comptées comme occupées. Une place réservée pour une autre séance ne réduit pas les disponibilités de cette séance.

La réponse porte l’en-tête `Cache-Control: no-store`. React redemande la programmation toutes les 30 secondes. Ce rafraîchissement ne bloque pas les sièges et ne remplace pas le contrôle qui sera effectué à la confirmation d’une réservation.

### Programmation vide et erreurs

Une programmation vide renvoie un statut `200` et `"films": []`. React affiche alors "Aucun film à l’affiche pour le moment."

Une méthode non autorisée, par exemple `POST /api/films`, renvoie un statut `405`.

Si la requête échoue ou renvoie un statut d’erreur, React affiche un message d’indisponibilité avec le bouton "Réessayer". Une erreur ne doit pas être présentée comme une programmation vide.

## Vérifications

Les tests backend dans `backend/tests/Controller/FilmControllerTest.php` interrogent l’API avec une base PostgreSQL dédiée. Ils vérifient les filtres, les disponibilités, l’accès public et le refus de POST.

Les tests navigateur dans `frontend/tests/programming.spec.js` simulent les réponses de cette route pour vérifier les états de l’interface. Ils ne valident pas à eux seuls la connexion à PostgreSQL.

## Inscription d’un client

`POST /api/register`

Le corps de la demande doit être un objet JSON avec ces quatre champs.

```json
{
  "firstName": "Élodie",
  "lastName": "O'Connor",
  "email": "elodie@example.com",
  "password": "une phrase facile à retenir"
}
```

Le nom et le prénom sont obligatoires et limités à 100 caractères chacun. Le mail doit être valide et ne pas dépasser 180 caractères. Le mot de passe doit contenir entre 10 et 128 caractères. Les espaces sont acceptés et conservés dans le mot de passe. Les espaces en début et fin des noms et du mail sont retirés. Le mail est enregistré en minuscules.

| Statut | Résultat |
| --- | --- |
| 201 | Compte créé, réponse `{"status":"pending_verification","emailSent":true}` |
| 400 | JSON invalide ou contenu qui n’est pas un objet |
| 409 | Un compte utilise déjà ce mail |
| 415 | Contenu envoyé dans un autre format que JSON |
| 422 | Champs absents, invalides ou supplémentaires |
| 405 | Méthode autre que POST |

Les erreurs de validation sont regroupées par champ dans `errors`. Le mot de passe et son empreinte ne sont jamais renvoyés. Le rôle client est attribué par Symfony. Un champ `roles` envoyé dans la demande est refusé.

Le compte est enregistré sans date de vérification du mail et sans ouvrir de session. Un mail contient le lien d’activation valable une heure. Si le service mail échoue, le compte reste enregistré et la réponse contient `emailSent: false`. Le formulaire React est accessible sur `/inscription`. Ces routes ne sont pas encore déployées.

## Vérifier une adresse mail

`GET /api/verify-email`

Le mail ouvre la page React `/activation`. Celle-ci transmet les paramètres du lien à cette route Symfony. Le lien contient l’identifiant du compte, une empreinte de son adresse mail, une expiration et une signature vérifiée par Symfony. Modifier le lien ou changer le mail du compte rend le lien invalide.

| Statut | Résultat |
| --- | --- |
| 200 | Adresse vérifiée, ou déjà vérifiée si le lien a déjà servi |
| 400 | Lien invalide, signature absente ou compte introuvable |
| 410 | Lien expiré |

Une activation réussie enregistre `emailVerifiedAt` en UTC. Elle n’ouvre pas de session. React affiche le résultat de la vérification et propose le renvoi du mail si le lien est refusé. Les paramètres du lien sont retirés de la barre d’adresse après réception du résultat.

## Renvoyer le mail d’activation

`POST /api/resend-verification`

Envoyer un objet JSON avec le champ `email`. Un compte existant dont le mail n’est pas vérifié reçoit un nouveau lien valable une heure. Aucun nouveau compte n’est créé. Un ancien lien encore valide reste utilisable jusqu’à son expiration ; après activation, les liens ne modifient plus le compte.

Une réponse `202` affiche le même message pour un compte absent, déjà vérifié ou en attente. Un mail invalide renvoie `422`, un JSON invalide `400` et un format autre que JSON `415`. Une panne du service mail renvoie `503`.

Les demandes de renvoi sont limitées à une par minute pour une même adresse, y compris si aucun compte ne correspond. Un dépassement renvoie `429` avec l’en-tête `Retry-After`. Le compteur utilise le cache du serveur ; il peut être réinitialisé lors d’un redéploiement et devra être partagé si plusieurs instances sont déployées.

## Connexion et session

`GET /api/csrf` prépare le formulaire et renvoie `csrfToken`. Le navigateur conserve le cookie de session associé.

`POST /api/login` reçoit un objet JSON avec `email` et `password`, accompagné de l’en-tête `X-CSRF-Token` obtenu précédemment. Le mail est normalisé comme à l’inscription.

| Statut | Résultat |
| --- | --- |
| 200 | `{"status":"authenticated"}`, nouvelle session authentifiée |
| 401 | Adresse mail ou mot de passe incorrect |
| 403 | Jeton CSRF invalide ou adresse mail non vérifiée après saisie du bon mot de passe |
| 429 | Trop de tentatives de connexion |

Symfony limite les échecs à cinq sur cinq minutes pour une même combinaison mail et adresse IP. Une limite supplémentaire par adresse IP réduit les essais sur plusieurs comptes. Ces compteurs utilisent le cache du serveur et peuvent être réinitialisés au redéploiement.

`GET /api/session` renvoie `{"user":null}` pour un visiteur. Après connexion, la réponse contient le nom, le prénom, le mail, l’identifiant et les rôles du compte courant, ainsi que `csrfToken`, `logoutToken` et `expiresAt` (date limite d’inactivité, timestamp Unix). Elle ne contient ni mot de passe ni empreinte. Consulter cette route ne prolonge pas la session.

`POST /api/activity` reçoit `X-CSRF-Token` et renouvelle la date d’activité du compte connecté. React ne l’appelle que si une interaction au clavier, au pointeur ou au défilement a eu lieu, au plus une fois par minute. Sans connexion, la route renvoie `401` ; sans jeton valable, `403`.

Après dix minutes sans activité, Symfony invalide la session avant de recharger le compte. Les rafraîchissements de la programmation ne prolongent pas ce délai. React masque aussi les informations du compte après inactivité.

`POST /api/logout` reçoit le champ de formulaire `_csrf_token` avec la valeur `logoutToken`. Symfony invalide la session et renvoie `{"status":"logged_out"}`. Un GET ou une demande sans protection CSRF ne doit pas déconnecter le compte.

Le cookie `cinema_session` est HttpOnly et SameSite=Lax. En production, il est aussi Secure. L’identifiant de session est renouvelé à la connexion. En production, les données de session sont stockées dans la table technique PostgreSQL `sessions` ; en développement elles restent dans le stockage natif de Symfony.

## Récupérer un mot de passe

`POST /api/forgot-password` reçoit un objet JSON avec `email`. La réponse `202` est identique pour une adresse inconnue, un compte non activé et un compte activé. Seul un compte activé reçoit un mail. Les demandes sont limitées à une par minute et par adresse (`429` en cas de dépassement). Une erreur d’envoi renvoie `503` sans modifier le mot de passe.

Le mail contient un lien signé vers `/reinitialiser-mot-de-passe`, valable une heure. Sa signature et sa version sont liées au compte, à son mail et à l’empreinte actuelle du mot de passe. Le lien ne contient ni mot de passe ni empreinte enregistrée en base. La version est calculée avec un HMAC utilisant le secret de l’application.

`POST /api/reset-password` reçoit un objet JSON avec `url` (le lien complet reçu) et `password` (le nouveau mot de passe). Un lien invalide, expiré ou déjà utilisé renvoie `400`. Un mot de passe invalide renvoie `422`, sans consommer le lien. Le minimum est de 10 caractères pour un client et de 15 pour le personnel, avec un maximum de 128 caractères.

Le changement renvoie `200` et `{"status":"password_changed","message":"Votre mot de passe est modifié. Connectez-vous avec le nouveau."}`. Il n’ouvre pas de session. Les sessions existantes sont refusées lors de leur prochaine requête à Symfony, car l’empreinte du mot de passe a changé.

Une mise à jour SQL conditionnée par l’ancienne empreinte empêche deux utilisations concurrentes du lien. Après le changement, tous les liens de réinitialisation précédents deviennent invalides. Demander un autre mail ne révoque pas un lien encore valable tant que le mot de passe reste inchangé.

## Plan de salle

`GET /api/screenings/{id}/seats` est accessible sans compte. La réponse contient la séance (film, horaire UTC et salle), le tarif actuel en centimes et les sièges de cette salle. Chaque siège possède un identifiant, une rangée, un numéro et une disponibilité.

Un siège est indisponible si une place non libérée existe pour cette séance. Les réservations des autres séances ne l’occupent pas. Aucune coordonnée client n’est transmise et la réponse n’est pas mise en cache.

- `200` pour le plan, y compris une séance complète.
- `404` pour une séance inconnue ou un film retiré de l’affiche.
- `410` pour une séance annulée ou déjà commencée.
- `503` si le tarif n’est pas configuré.

La sélection dans React ne bloque pas les sièges. Le total affiché est un aperçu au tarif fourni par Symfony. La confirmation revérifie les disponibilités dans une transaction.

## Confirmer une réservation

`POST /api/reservations` est accessible avec ou sans compte. Le navigateur demande d’abord un jeton à `GET /api/csrf`, puis le transmet dans l’en-tête `X-CSRF-Token` avec le cookie de session.

Exemple de corps JSON

```json
{
  "screeningId": 17,
  "seatIds": [101, 102],
  "firstName": "Camille",
  "lastName": "Martin",
  "email": "camille@example.com",
  "expectedUnitPriceCents": 1000,
  "confirmationKey": "c01b3fe3-43cc-4a59-8000-123456789abc"
}
```

Les identifiants de sièges viennent du plan de la séance. `expectedUnitPriceCents` correspond au tarif affiché dans le récapitulatif. Symfony lit le tarif en base et calcule le total. Un changement de tarif renvoie `409 price_changed` avec le nouveau tarif, sans créer de réservation. Le client doit confirmer à nouveau.

`confirmationKey` est une valeur aléatoire créée par le navigateur pour cette demande. Le serveur la lie à la session et conserve une empreinte des données. Une nouvelle tentative identique retrouve la même réservation, même si la première réponse a été perdue. Cette clé ne donne pas accès à une réservation depuis une autre session.

La séance est verrouillée pendant la transaction. Si un siège est occupé, aucun siège de la demande n’est enregistré. L’index unique des places actives empêche également les doublons. Une place d’une autre salle est refusée.

- `201` pour une nouvelle réservation confirmée.
- `200` pour une nouvelle tentative identique déjà enregistrée, avec `replayed: true`.
- `400` ou `415` pour un corps ou un format incorrect.
- `403` pour un jeton CSRF invalide.
- `404` pour une séance inconnue.
- `409 seats_unavailable` si un siège est pris, avec `unavailableSeatIds`.
- `409 price_changed` pour un tarif modifié. Une clé réutilisée avec d’autres données est également refusée en `409`.
- `410` pour une séance annulée, commencée ou retirée de l’affiche.
- `422` pour des données invalides.
- `503` si le tarif n’est pas configuré.

La réponse confirmée contient la référence, les coordonnées fournies, le film, la salle, l’horaire, les sièges, le prix unitaire et le total enregistrés. Elle n’est pas mise en cache. La réservation est rattachée au compte connecté uniquement si son adresse vérifiée correspond à l’adresse de contact.

Le brouillon avant connexion reste temporairement dans l’onglet. Après confirmation et acceptation du mail, il est supprimé. Une réponse réseau incertaine conserve la clé et propose de vérifier le résultat sans changer les coordonnées ni les places. Les sièges encore libres restent sélectionnés après un conflit.

Le mail de réservation est envoyé via Brevo après l’enregistrement. La réponse contient `emailSent` et `emailRetryAfter` (secondes avant un nouvel essai). `emailSent: true` signifie que le service d’envoi a accepté le mail, sans garantir sa livraison dans la boîte de réception.

Un échec du transport conserve le statut confirmé et les places. Le client peut refaire le même `POST` avec les mêmes données et la même clé après le délai indiqué. Symfony retrouve la réservation existante et retente seulement le mail. Les essais sont espacés d’au moins une minute. Un envoi déjà enregistré comme accepté n’est pas répété.

Les dates `confirmation_email_attempted_at` et `confirmation_email_sent_at` suivent ces opérations en base. Le verrou de la réservation évite deux envois simultanés. Si Brevo accepte le mail mais que le processus s’arrête avant l’enregistrement de cette acceptation, un nouvel essai peut produire un second mail ; la réservation reste unique.

## Consulter et annuler une réservation

`GET /api/reservations/{reference}` renvoie le récapitulatif et le statut. L’accès exige soit le lien signé reçu par mail, soit une session dont l’adresse vérifiée correspond à celle de la réservation. Cela permet aussi à un compte créé après une réservation sans compte de la retrouver avec la même adresse vérifiée.

La confirmation fournit `managementUrl`, également inclus dans le mail. Le lien pointe vers `/reservations/{reference}`. Sa signature couvre l’adresse configurée, la référence et l’expiration. Il reste valable 30 jours après la séance. Le navigateur transmet sa chaîne de paramètres telle quelle à l’API. Une référence seule ne donne aucun accès et une signature modifiée ou expirée est refusée en `403`. Pour un compte connecté sans lien, une réservation d’un autre client renvoie `404`.

La réponse contient `status`, `canCancel` et `cancellationDeadline`, en plus du récapitulatif. Les réponses ne sont pas mises en cache. La politique `no-referrer` empêche le navigateur de transmettre le lien personnel dans l’en-tête Referer.

`POST /api/reservations/{reference}/cancel` utilise le même contrôle d’accès et exige le jeton `X-CSRF-Token` de la session. Le corps n’est pas nécessaire. Ouvrir le lien avec `GET` ne déclenche aucune annulation.

Symfony verrouille la réservation puis vérifie l’heure côté serveur. L’annulation est acceptée jusqu’à deux heures avant la séance, limite incluse. Le statut `cancelled`, la date d’annulation et la libération des sièges sont enregistrés dans une seule transaction. Les lignes des places sont conservées avec une date de libération pour garder l’historique.

- `200` pour une annulation ou une réservation déjà annulée, avec le statut à jour.
- `403` pour un lien ou un jeton CSRF invalide, ou un accès sans authentification ni lien.
- `404` pour une réservation inconnue ou appartenant à un autre compte.
- `409` si le délai d’annulation est dépassé. La réservation reste active.

Un nouvel essai d’annulation ne modifie pas les places reprises entre-temps par un autre client. Le PDF utilise ce même accès sécurisé.


## Historique du compte client

`GET /api/account/reservations?page=1` exige un compte connecté avec une adresse vérifiée. Symfony sélectionne les réservations à cette adresse, y compris celles effectuées sans compte avant l’inscription. Un paramètre d’adresse mail envoyé par le navigateur ne change pas cette sélection.

La réponse contient `reservations`, `page` et `hasMore`. Chaque réservation fournit sa référence, son statut, le film, la salle, la date de séance, les sièges et le total enregistré. Les résultats sont triés par date de séance décroissante, puis par identifiant décroissant, avec 20 réservations par page. Les réservations annulées restent dans l’historique. Le champ `cancellationOrigin` vaut `client` ou `cinema`. Il reste `null` pour une ancienne annulation dont l’origine est inconnue. Une annulation de séance conserve l’origine des réservations déjà annulées par leur client.

Un visiteur reçoit `401`, un compte non vérifié `403` et un numéro de page invalide `422`. La réponse n’est pas mise en cache. Le lien du compte vers `/reservations/{reference}` utilise la session ; le détail vérifie à nouveau que la réservation appartient à cette adresse.


## Télécharger la confirmation PDF

`GET /api/reservations/{reference}/pdf` reprend le contrôle d’accès du récapitulatif. Un client connecté doit avoir la même adresse vérifiée que celle de la réservation. Sans compte, les paramètres du lien signé reçu par mail doivent être conservés.

Une réservation confirmée renvoie `200`, un contenu `application/pdf` et un téléchargement nommé `reservation-{reference}.pdf`. Une réservation annulée renvoie `409` au format JSON. Les refus d’accès restent `403` ou `404`, comme pour le récapitulatif.

Symfony génère le document à partir de la réservation enregistrée, avec son tarif conservé, ses sièges, ses coordonnées et l’heure de séance affichée pour Paris. Le document indique que le paiement se fait au cinéma. Les coordonnées de démonstration du cinéma sont configurées dans `config/services.yaml`.

Le modèle PHP est dans `templates/pdf/reservation.php`. Les valeurs sont échappées avant leur insertion dans le HTML. [Dompdf](https://github.com/dompdf/dompdf) génère le PDF ; les ressources distantes, le JavaScript et l’exécution de PHP dans le document sont désactivés. Aucun PDF client n’est enregistré dans le dossier public et la réponse utilise `Cache-Control: no-store, private`.

Un PDF déjà téléchargé reste lisible hors ligne après une annulation. Le document ne suffit donc pas à prouver qu’une réservation est encore active : le personnel contrôle son statut en base au guichet.


## Administration du cinéma

Les routes `/api/admin` exigent `ROLE_EMPLOYEE`. `ROLE_DIRECTOR` hérite de ce droit et autorise aussi le changement de tarif et les statistiques. Un visiteur doit se connecter (`401`) et un client authentifié reste refusé (`403`). Les réponses de gestion ne sont pas mises en cache.

`GET /api/admin/settings` renvoie `ticketPriceCents` et `canChangePrice`.

`POST /api/admin/settings/tariff` exige la direction et un jeton `X-CSRF-Token`. Le corps JSON contient uniquement `price`, une chaîne en euros, par exemple `"10,50"`. Les valeurs nulles, négatives, non numériques et les montants avec plus de deux décimales sont refusés en `422`. Symfony convertit le montant en centimes sans calcul flottant. La limite de stockage est celle de l’entier PostgreSQL, soit 2 147 483 647 centimes. Le nouveau tarif s’applique aux nouvelles réservations ; les prix déjà enregistrés ne changent pas.

`GET /api/admin/reservations?q=...&page=1` recherche une référence exacte ou une adresse mail exacte, sans distinction de majuscules. La réponse contient `reservations`, `page` et `hasMore`, avec 20 résultats par page, triés par date de réservation décroissante. Le récapitulatif inclut le statut et les coordonnées nécessaires au contrôle au guichet. Cette route ne valide pas un paiement et ne modifie pas la réservation.

`GET /api/admin/statistics?from=2030-01-01&to=2030-01-07` exige la direction. Les dates désignent les jours de séance à Paris, bornes incluses, sur une période d’au plus un an. Les réservations créées en dehors de cette période restent prises en compte si leur séance s’y déroule.

La réponse contient `screenings` et `totals`. Les séances annulées sont exclues. Les réservations confirmées et annulées sont comptées séparément. Le taux d’occupation correspond aux sièges actifs des réservations confirmées divisés par la capacité de la séance, avec une décimale. Le taux global utilise la somme des capacités des séances de la période, et non la moyenne de leurs pourcentages. Sans séance, les nombres valent zéro et le taux est `null`.

Ces statistiques concernent uniquement les réservations en ligne. Elles ne représentent ni les entrées réellement contrôlées au cinéma ni les achats effectués uniquement au guichet.


## Gestion des films

Ces routes sont réservées aux employés et à la direction. Les réponses ne sont pas mises en cache.

- `GET /api/admin/films` renvoie les films, y compris ceux retirés de l’affiche.
- `POST /api/admin/films` ajoute un film.
- `POST /api/admin/films/{id}` modifie un film.

Les deux écritures utilisent `multipart/form-data` avec le jeton `X-CSRF-Token`. Les champs attendus sont `title`, `synopsis`, `mainActors`, `durationMinutes` (entier positif) et `isShowing` (`1` ou `0`). Le fichier `poster` est obligatoire à la création et facultatif à la modification. Aucune URL ni aucun identifiant Cloudinary n’est accepté depuis le navigateur.

Réponses d’écriture : `201` à la création, `200` après modification, `422` pour un formulaire ou un fichier invalide, `404` si le film est introuvable, `409` si la nouvelle durée crée un conflit de programmation, `503` si l’enregistrement échoue. Les plafonds des textes sont de 255 caractères pour le titre, 20 000 pour le synopsis et 5 000 pour les acteurs.

Les écritures de programmation utilisent le verrou transactionnel PostgreSQL `7812601` pour vérifier les chevauchements sans modifications concurrentes. La gestion des séances devra utiliser ce même verrou.


## Gestion des séances

Accès employé ou direction, avec jeton CSRF `api` dans `X-CSRF-Token` pour les écritures.

- `GET /api/admin/screenings?date=YYYY-MM-DD` renvoie les séances du jour à Paris, y compris annulées, les salles et les films à l’affiche.
- `POST /api/admin/screenings` crée une séance. JSON attendu `filmId`, `roomId` (entiers) et `startsAt` au format `YYYY-MM-DDTHH:mm`, heure de Paris.
- `POST /api/admin/screenings/{id}` modifie une séance avec les mêmes champs.
- `POST /api/admin/screenings/{id}/cancel` annule la séance et ses réservations actives, puis libère les places. Un second appel ne crée pas de nouvelle notification.
- `POST /api/admin/screenings/{id}/retry-emails` relance les notifications non envoyées, par lots de dix.

Les écritures renvoient `id`, `message` et `pendingEmails` (sauf la relance, qui renvoie les deux derniers). La création renvoie `201`, les autres actions `200`. Un formulaire invalide renvoie `422`, une séance inconnue `404`, un conflit de programmation ou de délai `409`. Aucun mail réel n’est envoyé par les tests.

Le contrôle d’une heure de marge et les modifications de durée partagent le même verrou transactionnel. La séance est verrouillée avant ses réservations pour sérialiser une création de réservation concurrente. Une notification en échec n’annule pas la modification déjà enregistrée.

## Retrouver un lien de réservation

`POST /api/reservation-link` accepte un objet JSON avec `reference` et `email`, sans compte. La réponse 202 est identique pour une réservation trouvée ou inconnue. Le lien signé part uniquement à l’adresse enregistrée, pour une séance datant de moins de 30 jours. Une réservation annulée conserve son statut. Aucune réservation n’est créée.

Une demande par minute est permise pour une adresse, avec dix demandes par IP sur quinze minutes. Les saisies invalides renvoient 422 et les demandes trop fréquentes 429. Un échec de transport est journalisé sans adresse ni lien personnel.

## Corriger une adresse de réservation

`POST /api/admin/reservations/{reference}/email` est réservé au personnel et exige le jeton CSRF. Le JSON contient `email`, `expectedEmail`, `firstName`, `lastName` et `requesterVerified` à `true`. Le personnel demande au client son nom et son prénom, puis confirme la nouvelle adresse avec lui.

La correction conserve le compte propriétaire, les sièges, la référence et le prix. Elle invalide les anciens liens personnels et renvoie la confirmation à la nouvelle adresse. L’adresse de connexion du compte reste inchangée. Une réservation annulée ou une adresse modifiée entre-temps renvoie 409. Une saisie invalide renvoie 422. Un échec d’envoi conserve la correction et permet une nouvelle demande de lien.

## Vérification des mots de passe

L’inscription, la réinitialisation et la création interne du personnel refusent une liste de mots de passe courants et vérifient les fuites connues via Pwned Passwords. Seuls les cinq premiers caractères du hachage SHA-1 sont envoyés au service. La comparaison du reste se fait côté Symfony. Ce hachage de recherche ne remplace pas le hachage sécurisé du mot de passe enregistré.

Le contrôle attend au maximum cinq secondes. Une indisponibilité demande de réessayer et ne valide pas silencieusement le mot de passe. Les tests utilisent des réponses simulées.

Après un blocage de connexion, le titulaire d’un compte vérifié reçoit une alerte avec un lien vers la demande de réinitialisation. L’alerte est limitée à une par adresse sur quinze minutes. Elle ne contient aucun mot de passe. Un échec de transport ne débloque pas la connexion et ne révèle pas l’existence du compte.
