# Club Catalist

**Simulateur de gestion marketing d'un club de sport — by C'mon Sports.**

▶ **Jouer : [clubcatalist.cmon-sports.com](https://clubcatalist.cmon-sports.com/)**

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
donne accès aux vraies solutions — Audit DMOS, Fan Lab Insight & Pro, Data Factory,
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

Application **single-file** en JavaScript vanilla : aucun build, aucune dépendance,
aucun framework. Le jeu tient dans `index.html` ; les polices Raleway sont
auto-hébergées dans `fonts/`.

```bash
# lancer en local
python3 -m http.server 4174
# puis ouvrir http://localhost:4174
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
ouvre le CRM entier. Le dossier `relay/` contient donc un Cloudflare Worker qui garde la clé
côté serveur et n'expose que deux routes :

- `POST /sync` — inscrit ou met à jour un joueur dans la liste 63, transmet ses événements
  d'usage et, le cas échéant, envoie l'alerte « appel chaud » ;
- `GET /health` — diagnostic utilisé par le panneau **⚙️ Admin**.

Garde-fous : le relais ne touche jamais à la liste noire d'un contact existant ; `COMPANY`,
`JOB_TITLE` et `SPORT` ne sont remplis que s'ils sont vides ; `OPT_IN` ne peut que passer à
vrai ; seules les valeurs canoniques du jeu sont acceptées ; CORS limité au domaine du jeu ;
30 requêtes par minute et par IP ; la réponse ne dit pas si l'adresse existait déjà.

### Déployer

1. Dans Brevo, créer une clé API dédiée (« club-catalist-relay »), puis **révoquer l'ancienne
   clé** qui était saisie dans le navigateur de l'administrateur.
2. Déployer le relais :

   ```bash
   cd relay
   npx wrangler@4.147.0 login
   npx wrangler@4.147.0 secret put BREVO_API_KEY   # coller la nouvelle clé
   npx wrangler@4.147.0 deploy
   ```

3. Reporter l'adresse affichée (`https://clubcatalist-relay.<compte>.workers.dev`) dans
   `RELAY_URL`, en tête de la section « Synchronisation CRM » d'`index.html`, puis publier.
4. Dans le jeu, **⚙️ Admin → Tester le relais**, puis **Synchroniser maintenant**.

Les comptes créés avant la mise en service sont restés en file sur l'appareil de chaque
joueur : ils partent d'eux-mêmes à sa visite suivante.

Réglages dans `relay/wrangler.toml` : origines autorisées, numéro de liste, destinataire et
expéditeur de l'alerte. Tests : `cd relay && node --test` (sans dépendance).

### Événements et notifications

Les attributs `CC_*` gardent le dernier état ; les événements datent chaque geste et servent
de déclencheurs aux workflows Brevo (par exemple : « a essayé Data Factory » → séquence
Data Factory, ou notification interne).

| Événement | Propriétés | Envoyé quand |
|---|---|---|
| `club_catalist_service_tried` | `service` (« Audit DMOS », « Data Factory »…) | un service C'mon Sports est essayé dans le jeu |
| `club_catalist_season_completed` | `seasons`, `cert` | une saison se termine |
| `club_catalist_questionnaire` | `maturity`, `priority`, `timing`, `hot` | le questionnaire est validé |

Les événements attendent dans la file locale du compte et partent avec la synchronisation
suivante (regroupée sur 15 secondes) ; le mode démo n'en produit aucun.

**Alerte « appel chaud »** : quand un joueur répond « Budget prévu » ou « À l'étude » au
questionnaire, le relais envoie un e-mail à `NOTIFY_TO` (réponse directe au joueur), une seule
fois par contact (attribut `CC_HOT_AT`).

---

© C'mon Corporations SàRL — La Chaux-de-Fonds, Suisse
