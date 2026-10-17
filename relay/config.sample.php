<?php
/* C'mon Sports Challenge — configuration du relais.
   Copier ce fichier en config.php (même dossier, à côté de public/) et y
   coller la clé Brevo. config.php n'est jamais versionné ni servi par le web. */
return [
    // Clé API Brevo dédiée au relais (Brevo → Paramètres → Clés API)
    'BREVO_API_KEY'   => '',

    // Liste Brevo « CMON_SOLUTION_CHALLENGE »
    'BREVO_LIST_ID'   => 63,

    // Sites autorisés à appeler le relais (séparés par des virgules)
    'ALLOWED_ORIGINS' => 'https://challenge.cmon-sports.com',

    // Alerte « appel chaud » : destinataire(s), et expéditeur vérifié chez Brevo
    'NOTIFY_TO'       => 'douglas@cmon-sports.com',
    'NOTIFY_SENDER'   => 'noreply@cmon-sports.com',

    // Requêtes autorisées par minute et par adresse IP (0 = pas de limite)
    'RATE_LIMIT'      => 30,

    // À laisser vide. Seulement si l'hébergeur place un proxy devant PHP et
    // que tous les visiteurs semblent partager la même adresse : nom de la
    // variable serveur qui porte la vraie adresse, par ex. 'HTTP_X_FORWARDED_FOR'.
    'CLIENT_IP_HEADER' => '',
];
