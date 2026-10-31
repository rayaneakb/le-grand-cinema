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
