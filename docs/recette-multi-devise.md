# Recette — multi-devise (lots 0 à 3)

*12 novembre 2026 · branche `claude/multi-devise-lot3` (PR #19 → #22 empilées) · plan : `docs/plan-multi-devise.md`*

Rappel du principe : la devise est une **étiquette d'affichage** portée par le profil — aucun taux de change, aucun nombre ne bouge. La recette vérifie donc deux choses : que **rien n'a changé** dans la simulation, et que **tout ce qui s'affiche** suit la devise, dans les trois langues.

## 1. Non-régression de la simulation

Saison de référence rejouée (Glacier Royal HC, comité exigeant, graine figée, 26 semaines, audit + Insight + Data Factory), `main` contre la branche :

| Langue | Colonnes numériques (budget, fans, contacts, engagement, qualité, DMOS, confiance, FEV) | Journal |
|---|---|---|
| FR | identiques | identique, à l'espace fine près dans 2 lignes (`toLocaleString`) |
| DE | identiques | idem |
| EN | identiques | formatage à l'anglaise (D8) : `CHF 19,000`, `−CHF 3,430` |

Parité des traductions (identifiants DOM, substitutions `{vN}`) : **0 écart** sur 323 clés.

## 2. Détection par région (sans préférence enregistrée)

| `navigator.language` | Devise | | `navigator.language` | Devise |
|---|---|---|---|---|
| fr-CH, de-CH | CHF | | en-GB | GBP |
| fr-FR, de-DE, de-AT, it-IT | EUR | | en-US, fr-CA | USD |
| en-IE | EUR (Irlande = euro, correction du plan) | | pt-BR, ja-JP, vide | CHF (défaut, D3) |

Préférence enregistrée prioritaire : `fr-CH` + clé `USD` → USD ; clé invalide → ignorée (CHF).

## 3. Formatage — 3 langues × 4 devises

| | 60 000 | −3 000 | 0 | 1 234 567 | 0,45 (2 déc.) |
|---|---|---|---|---|---|
| FR / DE · CHF | 60 000 CHF | −3 000 CHF | 0 CHF | 1 234 567 CHF | 0,45 CHF |
| FR / DE · EUR | 60 000 € | −3 000 € | 0 € | 1 234 567 € | 0,45 € |
| FR / DE · GBP | 60 000 £ | −3 000 £ | 0 £ | 1 234 567 £ | 0,45 £ |
| FR / DE · USD | 60 000 $ | −3 000 $ | 0 $ | 1 234 567 $ | 0,45 $ |
| EN · CHF | CHF 60,000 | −CHF 3,000 | CHF 0 | CHF 1,234,567 | CHF 0.45 |
| EN · EUR | €60,000 | −€3,000 | €0 | €1,234,567 | €0.45 |
| EN · GBP | £60,000 | −£3,000 | £0 | £1,234,567 | £0.45 |
| EN · USD | $60,000 | −$3,000 | $0 | $1,234,567 | $0.45 |

Jetons dans les textes (`{{9000}}`, `{{-3000}}`, `{{cur}}`, `{{0}}`) : résolus dans les mêmes formes ; **aucun jeton résiduel** sur aucune page, dans aucune combinaison.

## 4. Jeu — matrice dans le navigateur

Pour chaque langue, les quatre devises enchaînées **sans rechargement** depuis le pied de page ; lecture du KPI budget, du premier bouton de service, de la légende Fan Equity Value :

| | CHF | EUR | GBP | USD |
|---|---|---|---|---|
| FR | 60 000 CHF · Commander · 4 500 CHF · (CHF) | 60 000 € · 4 500 € · (€) | 60 000 £ · 4 500 £ · (£) | 60 000 $ · 4 500 $ · ($) |
| DE | 60 000 CHF · Bestellen · 4 500 CHF · (CHF) | 60 000 € · (€) | 60 000 £ · (£) | 60 000 $ · ($) |
| EN | CHF 60,000 · Order · CHF 4,500 · (CHF) | €60,000 · €4,500 · (€) | £60,000 · £4,500 · (£) | $60,000 · $4,500 · ($) |

Contrôlés en plus, au fil des lots : inscription avec pastilles pré-cochées, briefing (« Les montants s'affichent en € »), journal relu dans la nouvelle devise, fenêtres de décision, fiche qualité, export RGPD (`devise_affichage`).

## 5. Landing — matrice dans le navigateur

Unité du KPI budget · brouillard · palier « Anonymes » · coût d'une action :

| | CHF | EUR | GBP | USD |
|---|---|---|---|---|
| FR | CHF · ? CHF · Anonymes · 0 CHF · 500 CHF | € · ? € · 0 € · 500 € | £ · ? £ · 0 £ · 500 £ | $ · ? $ · 0 $ · 500 $ |
| DE | CHF · ? CHF · Anonyme · 0 CHF · 500 CHF | € · … | £ · … | $ · … |
| EN | CHF · ? CHF · Anonymous · CHF 0 · CHF 500 | € · €0 · €500 | £ · £0 · £500 | $ · $0 · $500 |

Transmission landing ↔ jeu par la clé partagée `mktclubgm_currency` : vérifiée dans les deux sens.

## 6. Cas particuliers

| Cas | Attendu | Constaté |
|---|---|---|
| Profil antérieur sans devise | CHF, profil complété, sans écran de plus | `currency = CHF`, `u.currency = CHF`, clé posée, budget « 60 000 CHF » |
| Carte de partage, sans Fan Lab Pro | « non mesurée », pas de montant | conforme |
| Carte de partage, avec Fan Lab Pro, en £ | montant dans la devise, image et texte | « 404 189 £ » sur la carte et dans le texte prêt à coller |
| Mode démo en £ | partie en £, profil et préférence de l'opérateur intacts | budget £40,800, profil EUR, clé EUR |
| Relais | `CC_CURRENCY` posé ; absente = non posé ; inconnue = 400 | tests `run.php` : 27/28 (l'échec « point d'entrée web » est antérieur et environnemental) |

## 7. Verdict

**Recette passée.** Prêt à fusionner dans l'ordre #19 → #20 → #21 → #22 → #23, puis à redéployer `relay/src/Relay.php` sur relay.cmon-sports.com.

Deux conventions assumées, à faire évoluer si besoin : le Canada est rattaché au dollar américain (D3), et l'anglais écrit « CHF 60,000 » (code avant le nombre, comme £ et $).
