# Plan — jeu multi-devise, choix de la devise à l'inscription

*Plan sans code. Les décisions D1–D8 sont à trancher avant le lot 0.*

## 1. Principe retenu

**Le jeu raisonne en unités internes ; la devise est une étiquette d'affichage, choisie à l'inscription.**
Il n'y a pas de taux de change : 60 000 unités s'affichent « 60 000 CHF », « 60 000 € », « £60,000 » ou « $60,000 ».

Pourquoi cette approche plutôt qu'une conversion :
- un budget de club de 60 000, des frais fixes de 1 200 par semaine, une amende de 9 000 sont des ordres de grandeur plausibles dans les quatre devises — convertir n'apporterait rien au joueur ;
- une conversion changerait **tous** les montants écrits dans les textes (événements, décisions, aide, briefing) dans les trois langues, et rendrait les **codes de défi** incomparables entre deux joueurs qui n'ont pas la même devise ;
- l'équilibrage validé par simulation (14 400 parties) reste intact au caractère près.

Exemple chiffré — Lakeside Kings, budget de départ 50 000, audit = 7,5 % :

| Devise | Budget affiché | Audit affiché |
|---|---|---|
| CHF | 50 000 CHF | 3 800 CHF |
| EUR | 50 000 € | 3 800 € |
| GBP | £50,000 | £3,800 |
| USD | $50,000 | $3,800 |

## 2. État des lieux (inventaire du 12.11.2026)

| Zone | Occurrences de « CHF » | Nature |
|---|---|---|
| Jeu `jouer/index.html`, source FR | 57 dont 35 via `fmtCHF()` | 16 montants écrits en dur dans les événements, décisions, légendes, coupe d'Europe, fiche qualité |
| Jeu, TX allemand | 15 | mêmes montants en dur, traduits |
| Jeu, TX anglais | 15 | idem |
| Landing `index.html` | 21 | aperçu des KPI, section fan equity (« 0 CHF »), frais fixes du mini-simulateur |
| Relais, outils, README | 0 | — |

Formatage actuel : `fmt = n => n.toLocaleString("fr-CH")` pour **toutes** les langues (espace comme séparateur de milliers, y compris en anglais) ; `fmtCHF = n => fmt(n) + " CHF"`.

## 3. Conception

### 3.1 Table des devises
```js
const CURRENCIES = {
  CHF: { sym: "CHF" },   // 60 000 CHF  — en anglais : CHF 60,000
  EUR: { sym: "€"   },   // 60 000 €    — en anglais : €60,000
  GBP: { sym: "£"   },   // 60 000 £    — en anglais : £60,000
  USD: { sym: "$"   },   // 60 000 $    — en anglais : $60,000
};
```
Règle de position : en français et en allemand, symbole **après** le nombre avec espace insécable ; en anglais, symbole **avant** (CHF compris : « CHF 60,000 »).

### 3.2 Formatage
`fmtMoney(n)` remplace `fmtCHF(n)` partout (35 appels). Le séparateur de milliers suit la langue : `fr-CH` en FR et DE (inchangé), `en-GB` en EN si D8 = oui.

### 3.3 Où vit la devise
- **Sur le profil** (`u.currency`), pas dans la sauvegarde de partie : une partie est indépendante de la devise, un même défi se lit en CHF chez l'un et en € chez l'autre.
- Pré-sélection à l'inscription par la **région du navigateur** (`navigator.language`) : `*-CH` → CHF · `*-FR`, `-DE`, `-AT`, `-BE`, `-IT`, `-ES`, `-NL`, `-LU` → EUR · `-GB`, `-IE` → GBP · `-US`, `-CA` → USD · sinon la valeur par défaut (D3).
- Profils existants : migration douce — `currency` absent ⇒ CHF (comportement actuel), sans écran supplémentaire.

### 3.4 Onboarding
Dans le formulaire de création de compte, après le rôle : une ligne de quatre pastilles **CHF · € · £ · $**, pré-cochée selon la région, libellée « Devise d'affichage des montants ». Le briefing d'embauche (volet « Votre poste ») mentionne le budget dans la devise choisie, ce qui confirme le choix sans étape de plus.

### 3.5 Changement ultérieur (D4)
Pastilles dans le pied de page, à côté de FR · DE · EN. Comme la devise n'est qu'une étiquette, le changement **ne recharge pas** la page : `renderAll()` suffit.

### 3.6 Textes à montants en dur
Les 16 + 14 + 12 montants écrits dans les textes (« −2 500 CHF », « 6 000 CHF », « 0 CHF », « −3 000 CHF »…) passent par des substitutions `{vN}` alimentées par `fmtMoney`. Légendes « Fan Equity Value (CHF) » → « Fan Equity Value ({devise}) ». Fiche qualité : « 0,15 + qualité × 0,06 = 0,45 CHF » → `fmtMoney` avec deux décimales.

### 3.7 Carte de partage, bilan, export
La carte de partage et le bilan de fin de partie utilisent déjà `fmtCHF` : ils suivent. L'export RGPD gagne un champ `devise_affichage`.

### 3.8 Landing (D5)
Même détection par région, même table, un sélecteur discret à côté de FR · DE · EN ; les 21 occurrences passent par une fonction commune. Le choix fait sur la landing est transmis au jeu (clé `localStorage` partagée, même origine) et pré-remplit l'inscription.

### 3.9 CRM (D6)
Attribut **`CC_CURRENCY`** (texte : CHF / EUR / GBP / USD) ajouté à `Relay::ATTRIBUTES` et à la liste blanche des valeurs ; envoyé à l'inscription et à chaque changement. Usage : segment « marché euro » / « marché britannique » — c'est un signal de **marché**, pas un signal d'usage. Une ligne dans la politique de confidentialité (« devise d'affichage choisie »).

### 3.10 Mode démo (D7)
Le formulaire de démo commerciale gagne les mêmes pastilles : on montre au club prospect ses montants dans sa devise. Aucune écriture CRM, comme aujourd'hui.

## 4. Lots

| Lot | Contenu | Estimation |
|---|---|---|
| 0 — Fondations | `CURRENCIES`, détection par région, `fmtMoney`, `u.currency`, pastilles à l'inscription, pied de page (D4) | 0,5 j |
| 1 — Textes | 42 montants en dur dans les trois langues → `{vN}` ; légendes ; fiche qualité ; export | 0,5 j |
| 2 — Landing | détection, sélecteur, 21 occurrences, transmission au jeu | 0,5 j |
| 3 — CRM et démo | `CC_CURRENCY` au relais, attribut Brevo, politique de confidentialité, formulaire de démo | 0,25 j |
| 4 — Recette | harnais : sorties identiques au caractère près hors étiquette ; passe visuelle 3 langues × 4 devises ; profils existants sans devise ; carte de partage | 0,25 j |

**Total ≈ 2 jours.** Chaque lot est une PR ; le lot 4 conditionne la mise en ligne.

## 5. Décisions à trancher (oui / non)

- **D1 — Étiquette sans taux de change ?** Oui → aucun montant ne change, codes de défi comparables, 2 j. Non → taux fixes codés (par ex. 1 CHF = 1,05 €, 0,88 £, 1,12 $), tous les montants des textes recalculés, équilibrage à revérifier par devise, +1 j.
- **D2 — Quatre devises : CHF, EUR, GBP, USD ?** Oui. Non → lesquelles ; chaque devise supplémentaire ≈ 1 h.
- **D3 — Pré-sélection par la région du navigateur, CHF par défaut si la région est inconnue ?** Oui. Non → EUR par défaut, ou pas de pré-sélection (choix obligatoire, un clic de plus à l'inscription).
- **D4 — Devise modifiable après l'inscription, dans le pied de page ?** Oui → changement immédiat, sans recharger. Non → figée à l'inscription, modifiable seulement en recréant le profil.
- **D5 — La landing suit la même détection, avec le même sélecteur ?** Oui → cohérence landing → jeu, +0,5 j. Non → la landing reste en CHF.
- **D6 — `CC_CURRENCY` transmis au CRM ?** Oui → segment par marché, une ligne de plus dans la politique de confidentialité. Non → reste local.
- **D7 — En mode démo, l'opérateur choisit la devise du club prospect ?** Oui. Non → devise du compte opérateur.
- **D8 — Corriger au passage le format des nombres en anglais (60,000 au lieu de 60 000) ?** Oui → l'anglais lit ses nombres à l'anglaise. Non → inchangé.
