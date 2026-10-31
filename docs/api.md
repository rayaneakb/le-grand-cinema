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
