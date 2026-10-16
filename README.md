# Club Catalist

**Simulateur de gestion marketing d'un club de sport — by C'mon Sports.**

▶ **Découvrir : [clubcatalist.cmon-sports.com](https://clubcatalist.cmon-sports.com/)** · **Jouer : [clubcatalist.cmon-sports.com/jouer/](https://clubcatalist.cmon-sports.com/jouer/)**

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

Site statique en JavaScript vanilla : aucun build, aucune dépendance, aucun framework.

- `index.html` — **landing page** interactive : aperçu animé d'une saison, comparateur
  des 4 clubs (radar DMOS), mini-simulateur de 4 semaines, bascule « brouillard des
  données », réglage des 6 piliers DMOS et présentation des solutions C'mon Sports.
  Trilingue, elle partage la clé de langue du jeu (`mktclubgm_lang`) et propose
  « Reprendre ma partie » quand une sauvegarde existe sur l'appareil.
- `jouer/index.html` — **le jeu**, single-file. Même origine que la landing : sauvegardes
  et langue sont conservées. `jouer/?club=hc|fc|bb|vb` met en avant le club choisi
  sur la landing à l'écran de sélection.
- `fonts/` — polices Raleway auto-hébergées.

```bash
# lancer en local
python3 -m http.server 4174
# puis ouvrir http://localhost:4174 (landing) ou http://localhost:4174/jouer/ (jeu)
```

Le fichier fonctionne aussi en ouverture directe (`file://`).

## Confidentialité (RGPD / nLPD)

Privacy by design : **aucune donnée ne quitte le navigateur** par défaut. Pas de serveur,
pas de cookie, pas de traceur, aucune requête externe. Comptes (e-mail), codes PIN
(hachés SHA-256 salés) et sauvegardes vivent dans le `localStorage` de l'appareil.

Seule exception, sur consentement explicite (case décochée par défaut) : l'inscription
aux actualités C'mon Sports transmet l'adresse e-mail à Brevo avec le marqueur
`CMON_SOLUTION_CLUBCATALIST`.

La clé API Brevo se configure via le lien **⚙️ Admin** du pied de page et reste stockée
localement dans le navigateur de l'opérateur — **elle n'est jamais présente dans ce dépôt**.
Un déploiement public ne permet donc aucune synchronisation Brevo tant qu'aucune clé n'a
été saisie sur le poste concerné.

Export et effacement des données en un clic depuis le pied de page.

---

© C'mon Corporations SàRL — La Chaux-de-Fonds, Suisse
