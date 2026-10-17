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

    // Attributs Brevo du prénom et du nom (PRENOM / NOM sur un compte en français,
    // FIRSTNAME / LASTNAME sur un compte en anglais). Jamais écrasés s'ils sont remplis.
    'FIRSTNAME_ATTR'  => 'PRENOM',
    'LASTNAME_ATTR'   => 'NOM',

    // Requêtes autorisées par minute et par adresse IP (0 = pas de limite)
    'RATE_LIMIT'      => 30,

    // À laisser vide. Seulement si l'hébergeur place un proxy devant PHP et
    // que tous les visiteurs semblent partager la même adresse : nom de la
    // variable serveur qui porte la vraie adresse, par ex. 'HTTP_X_FORWARDED_FOR'.
    'CLIENT_IP_HEADER' => '',
];
