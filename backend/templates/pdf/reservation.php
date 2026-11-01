<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Confirmation de réservation</title>
    <style>
        @page {
            margin: 38pt 42pt;
        }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10pt;
            color: #202d32;
            line-height: 1.5;
        }

        .brand {
            font-size: 19pt;
            font-weight: bold;
            color: #28554e;
            margin-bottom: 4pt;
        }

        .subtitle {
            color: #687570;
            margin: 0;
        }

        .header {
            border-bottom: 2pt solid #28554e;
            padding-bottom: 17pt;
            margin-bottom: 23pt;
        }

        h1 {
            font-size: 21pt;
            line-height: 1.3;
            margin: 0 0 10pt;
        }

        h2 {
            font-size: 12pt;
            color: #28554e;
            margin: 22pt 0 6pt;
        }

        .reference {
            background: #e9f2ed;
            padding: 12pt 15pt;
            margin-bottom: 20pt;
        }

        .reference strong {
            font-size: 14pt;
        }

        .label {
            color: #66746e;
            font-size: 9pt;
            margin-bottom: 3pt;
        }

        .value {
            margin: 0 0 11pt;
            overflow-wrap: anywhere;
        }

        .film {
            font-size: 15pt;
            font-weight: bold;
        }

        .total {
            border-top: 1pt solid #dce3de;
            border-bottom: 1pt solid #dce3de;
            padding: 12pt 0;
            margin-top: 18pt;
            font-size: 12pt;
        }

        .notice {
            margin-top: 20pt;
            color: #4d5c57;
            font-size: 9pt;
        }

        .contact {
            margin-top: 24pt;
            font-size: 9pt;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">Le Grand Cinéma</div>
        <p class="subtitle">Le plaisir du grand écran</p>
    </div>
    <h1>Confirmation de réservation</h1>
    <div class="reference">
        <div class="label">Numéro de réservation</div>
        <strong><?= $escape($booking['reference']) ?></strong>
    </div>
    <div class="label">Film</div>
    <p class="value film"><?= $escape($booking['filmTitle']) ?></p>
    <div class="label">Séance - heure de Paris</div>
    <p class="value"><?= $date->format('d/m/Y à H:i') ?> · <?= $escape($booking['room']) ?></p>
    <div class="label">Sièges réservés (<?= count($booking['seats']) ?>)</div>
    <p class="value"><?= $escape(implode(', ', $booking['seats'])) ?></p>
    <h2>Vos coordonnées</h2>
    <p class="value"><?= $escape($booking['firstName'] . ' ' . $booking['lastName']) ?><br><?= $escape($booking['email']) ?></p>
    <div class="total">
        Tarif par place <?= $unitPrice ?> €<br>
        <strong>Total à régler au cinéma <?= $total ?> €</strong>
    </div>
    <div class="notice">
        <strong>Paiement au cinéma. Ce document ne vaut pas preuve de paiement.</strong><br>
        Présentez cette confirmation au guichet. Merci d’arriver environ 20 minutes avant la séance.<br>
        Annulation en ligne possible jusqu’au <?= $cancellationDeadline->format('d/m/Y à H:i') ?>.<br>
        Une réservation annulée n’est plus valable. Le personnel vérifie son statut au guichet.
    </div>
    <div class="contact">
        <strong>Le Grand Cinéma</strong><br>
        <?= $escape($address) ?><br>
        <?= $escape($phone) ?> · <?= $escape($email) ?>
    </div>
</body>
</html>
