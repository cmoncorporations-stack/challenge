# C'mon Sports Challenge

**Simulateur de gestion marketing d'un club de sport, par C'mon Sports.**

▶ **Découvrir : [challenge.cmon-sports.com](https://challenge.cmon-sports.com/)** · **Jouer : [challenge.cmon-sports.com/jouer/](https://challenge.cmon-sports.com/jouer/)**

Vous êtes recruté·e comme Responsable Marketing Digital d'un club de sport suisse.
Chaque semaine, répartissez vos points d'action entre contenu, campagnes, collecte de
données, sponsors et conformité. Faites progresser votre score DMOS, valorisez votre
fanbase, satisfaites vos sponsors et atteignez les objectifs du comité directeur…
sous peine de licenciement.

## Le jeu

- **4 clubs jouables** (hockey, football, basketball, volleyball), en 1re ou 2e division
- **3 niveaux de difficulté** — patience du comité directeur, fréquence des scénarios d'ego
- **Saison de 26 semaines** : 3 points d'action par semaine, un match simulé chaque semaine
- **Promotion / relégation / coupe d'Europe** : monter fait grimper les revenus, mais aussi
  les charges et les attentes des fans
- **Score DMOS** (6 piliers /60), **valorisation Fan Lab**, portefeuille sponsors,
  taux de pénétration du bassin CRM
- **Mode défi** : un code fige la saison entière — mêmes événements pour tous, scores comparables
- **Carte de partage** : une image 1200×630 du bilan, générée dans le navigateur

### Le rôle de C'mon Sports

Sans accompagnement, le club pilote à l'aveugle : score DMOS affiché en fourchette,
valeur de la fanbase inconnue, qualité des données invisible. L'onglet **C'mon Sports**
donne accès aux vraies solutions, regroupées par famille — **Audit** (Fan Equity Audit),
**Fan Intelligence** (Fan Lab Insight & Pro), **Fan Management** (Fan Data Factory),
Blue Penguin, Griddy Picks — qui révèlent ces indicateurs et transforment durablement
le tableau de bord.

### Trois langues

Français, allemand (orthographe suisse) et anglais, au choix depuis le pied de page.
Le français est la **langue source** : il reste écrit en clair dans le balisage, dans les
tables de contenu et à l'appel de `tr()`, si bien que le fichier se relit sans dictionnaire.
Les autres langues le remplacent au démarrage depuis l'objet `TX` ; une clé absente
retombe automatiquement sur le français plutôt que d'afficher un identifiant technique.

Les valeurs envoyées au CRM restent volontairement en français (rôle déclaré à
l'inscription) afin de rester comparables d'une langue à l'autre ; la langue d'usage
part de son côté dans l'attribut `CC_LANG`.

## Technique

Site statique en JavaScript vanilla : aucun build, aucune dépendance, aucun framework.

- `index.html` — **landing page** interactive : aperçu animé d'une saison, comparateur
  des 4 clubs (radar de maturité data), mini-simulateur de 4 semaines, bascule « brouillard des
  données », score DMOS expliqué en langage simple et présentation des solutions C'mon Sports.
  Trilingue, elle partage la clé de langue du jeu (`mktclubgm_lang`) et propose
  « Reprendre ma partie » quand une sauvegarde existe sur l'appareil.
- `jouer/index.html` — **le jeu**, single-file. Même origine que la landing : sauvegardes
  et langue sont conservées. `jouer/?club=hc|fc|bb|vb` met en avant le club choisi
  sur la landing à l'écran de sélection.
- `assets/` — logo C'mon Sports Challenge en SVG : `logo-challenge-blanc.svg` (fonds sombres,
  utilisé par le site) et `logo-challenge-noir.svg` (fonds clairs).
- `fonts/` — polices Raleway auto-hébergées.

```bash
# lancer en local
python3 -m http.server 4174
# puis ouvrir http://localhost:4174 (landing) ou http://localhost:4174/jouer/ (jeu)
```

Le fichier fonctionne aussi en ouverture directe (`file://`), sans synchronisation CRM.

La synchronisation avec Brevo passe par un petit relais serveur, dans `relay/`
(voir [Relais CRM](#relais-crm)).

## Confidentialité (RGPD / nLPD)

Privacy by design : **la progression ne quitte jamais le navigateur**. Pas de cookie, pas
de traceur, pas d'outil d'analyse d'audience. Codes PIN (hachés SHA-256 salés) et
sauvegardes vivent dans le `localStorage` de l'appareil.

Seule sortie, le CRM, annoncée à l'inscription : tout compte créé rejoint la liste Brevo
`CMON_SOLUTION_CLUBCATALIST` (n° 63) avec le marqueur du même nom, les informations
facultatives du formulaire, les signaux d'usage `CC_*` et, s'il est rempli, le questionnaire
de positionnement — base légale : intérêt légitime, avec droit d'opposition.

Les envois marketing, eux, restent soumis à la case d'opt-in, décochée par défaut. Sans
elle, un **nouveau** contact entre au CRM exclu des campagnes ; un contact **déjà connu**
garde le statut d'envoi qu'il avait (le jeu ne désinscrit personne).

Export et effacement des données en un clic depuis le pied de page.

## Relais CRM

Le jeu est un fichier statique : une clé Brevo qu'il contiendrait serait publique, et elle
ouvre le CRM entier. Le dossier `relay/` contient donc un petit relais PHP, hébergé en Suisse
chez Infomaniak, qui garde la clé côté serveur et n'expose que deux routes :

- `POST /sync` — inscrit ou met à jour un joueur dans la liste 63, transmet ses événements
  d'usage et, le cas échéant, envoie l'alerte « appel chaud » ;
- `GET /health` — diagnostic utilisé par le panneau **⚙️ Admin**.

Garde-fous : le relais ne touche jamais à la liste noire d'un contact existant ; `COMPANY`,
`JOB_TITLE` et `SPORT` ne sont remplis que s'ils sont vides ; `OPT_IN` ne peut que passer à
vrai ; seules les valeurs canoniques du jeu sont acceptées ; CORS limité au domaine du jeu ;
30 requêtes par minute et par IP (l'adresse n'est conservée que hachée, une minute au plus) ;
la réponse ne dit pas si l'adresse existait déjà.

```
relay/
├── public/index.php    seul fichier exposé au web (+ .htaccess)
├── src/Relay.php       logique du relais
├── config.sample.php   modèle de configuration → à copier en config.php (clé Brevo)
├── var/                compteurs de débit et cache, inscriptible par PHP
└── tests/run.php       tests, sans dépendance : php relay/tests/run.php
```

PHP 8.1 ou plus récent, avec l'extension cURL (présente par défaut chez Infomaniak).

### Déployer chez Infomaniak

1. **Clé Brevo** — dans Brevo (Paramètres → Clés API), créer une clé dédiée
   (« cmon-sports-challenge-relay »), puis **révoquer l'ancienne clé** qui était saisie dans le
   navigateur de l'administrateur.
2. **Site** — dans le Manager Infomaniak, sur l'hébergement web, ajouter un site ou un
   sous-domaine, par exemple `relay.cmon-sports.com`, en PHP 8.1 ou plus, avec un certificat
   SSL (Let's Encrypt, gratuit). Si le Manager le permet, faire pointer le dossier racine du
   site sur `relay/public`.
3. **Fichiers** — envoyer le contenu de `relay/` (FTP/SFTP ou gestionnaire de fichiers du
   Manager) : `public/`, `src/`, `var/`, `.htaccess` et `config.sample.php`.
4. **Clé** — sur le serveur, copier `config.sample.php` en `config.php` et y coller la clé.
   Ce fichier reste hors du dossier public ; il n'est jamais versionné.
5. **Contrôle** — ouvrir `https://relay.cmon-sports.com/health` dans un navigateur : la réponse
   `{"ok":false,"error":"origin"}` est **normale** (le relais répond et refuse ce qui ne vient
   pas du jeu).
6. **Branchement** — reporter `https://relay.cmon-sports.com` dans `RELAY_URL`, en tête de la
   section « Synchronisation CRM » de `jouer/index.html`, puis publier.
7. Dans le jeu, **⚙️ Admin → Tester le relais**, puis **Synchroniser maintenant**.

Si le dossier racine ne peut pas viser `relay/public`, le faire pointer sur `relay/` : le
`.htaccess` de `relay/` redirige vers le point d'entrée et interdit tout le reste. Sans
réécriture d'URL, `RELAY_URL` prend la forme `https://relay.cmon-sports.com/index.php`
(ou `…/public/index.php`).

Les comptes créés avant la mise en service sont restés en file sur l'appareil de chaque
joueur : ils partent d'eux-mêmes à sa visite suivante.

Réglages dans `config.php` : origines autorisées, numéro de liste, destinataire et expéditeur
de l'alerte, limite de débit.

### Événements et notifications

Les attributs `CC_*` gardent le dernier état ; les événements datent chaque geste et servent
de déclencheurs aux workflows Brevo (par exemple : « a essayé Fan Data Factory » → séquence
Fan Data Factory, ou notification interne).

| Événement | Propriétés | Envoyé quand |
|---|---|---|
| `club_catalist_service_tried` | `service` (« Fan Equity Audit », « Fan Data Factory »…) | un service C'mon Sports est essayé dans le jeu |
| `club_catalist_season_completed` | `seasons`, `cert` | une saison se termine |
| `club_catalist_questionnaire` | `maturity`, `priority`, `timing`, `hot` | le questionnaire est validé |

Les événements attendent dans la file locale du compte et partent avec la synchronisation
suivante (regroupée sur 15 secondes) ; le mode démo n'en produit aucun.

**Alerte « appel chaud »** : quand un joueur répond « Budget prévu » ou « À l'étude » au
questionnaire, le relais envoie un e-mail à `NOTIFY_TO` (réponse directe au joueur), une seule
fois par contact (attribut `CC_HOT_AT`).

---

© C'mon Corporations SàRL — La Chaux-de-Fonds, Suisse
