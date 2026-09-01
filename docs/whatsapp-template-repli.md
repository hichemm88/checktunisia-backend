# Plan de repli si Meta rejette le modèle à 9 variables

**Non appliqué.** À sortir du tiroir seulement si `whatsapp:templates --create`
revient en `REJECTED`.

## Le risque

Meta refuse les modèles dont le corps est jugé trop « variabilisé » : trop de
paramètres par rapport au texte fixe, ou un corps qui n'est presque qu'une
suite de variables. Le motif remonte sous `rejected_reason`, généralement
`INVALID_FORMAT` ou `SCAM`.

Notre corps actuel a **9 variables pour 6 lignes**, avec des libellés fixes
courts. C'est le profil qui peut déclencher le refus.

```
Établissement : {{1}}
Adresse : {{2}}
Voyageur : {{3}} — {{4}}
Document : {{5}}
Séjour : du {{6}} au {{7}} — Chambre : {{8}}
Accompagnants : {{9}}

La fiche complète, pièce d'identité comprise, est en pièce jointe.
```

## D'abord : lire le motif, ne pas deviner

```bash
php artisan whatsapp:templates
```

La commande affiche le `rejected_reason` renvoyé par Meta. **Trois motifs
appellent trois réponses différentes**, et appliquer le mauvais repli fait
perdre un second cycle d'approbation :

| Motif renvoyé | Cause réelle | Réponse |
|---|---|---|
| `INVALID_FORMAT` | Trop de variables / ratio texte-variables | Repli A puis B ci-dessous |
| `SCAM` / `ABUSIVE_CONTENT` | Contenu jugé trompeur | Repli C |
| `INCORRECT_CATEGORY` | Classé UTILITY à tort | Rien à changer au corps — répondre à Meta que c'est bien transactionnel |

Un modèle rejeté n'est pas modifiable : il faut en **créer un nouveau sous un
autre nom**. Poser `WHATSAPP_TEMPLATE_NAME=fiche_police_nouvelle_v2` et
relancer `--create`. L'ancien reste chez Meta, inerte.

## Repli A — fusionner, sans rien perdre (9 → 5 variables)

Le plus sûr : on ne retire aucune information, on regroupe ce qui va ensemble.
Le PDF joint porte de toute façon la fiche complète ; le corps n'est qu'un
résumé de tri.

```
Nouvelle fiche de police reçue pour l'établissement {{1}}.

Voyageur : {{2}}
Document : {{3}}
Séjour : {{4}}
Accompagnants : {{5}}

La fiche complète, pièce d'identité comprise, est en pièce jointe.
Le bouton ci-dessous ouvre le dossier dans Qayed.
```

Regroupements :

- `{{1}}` établissement **et adresse** — « RESIDENCE EXEMPLE, 12 rue de l'Exemple, Tunis »
- `{{2}}` nom **et nationalité** — « EXEMPLE Voyageur (Tunisie) »
- `{{3}}` document, inchangé
- `{{4}}` arrivée, départ **et chambre** — « du 01/01/2026 au 03/01/2026, chambre 000 »
- `{{5}}` accompagnants, inchangé

Le ratio texte-variables devient nettement plus favorable, et deux phrases
fixes s'ajoutent.

**Où intervenir** : `FicheTemplate::params()` compose les 5 chaînes au lieu de
9 ; `BODY_VARIABLES` passe à 5 ; la définition dans `WhatsappTemplates` suit.
`clean()` s'applique déjà à chaque valeur, y compris concaténée — la
concaténation doit se faire **avant** `clean()`, sinon un séparateur pourrait
réintroduire des espaces multiples.

## Repli B — si A est encore refusé (5 → 2 variables)

Le corps redevient une notification, et la fiche vit entièrement dans le PDF.

```
Une nouvelle fiche de police a été enregistrée à {{1}} pour {{2}}.

Le document complet, pièce d'identité comprise, est joint à ce message.
Le bouton ci-dessous ouvre la fiche dans Qayed, avec l'historique du séjour.
```

C'est la forme que main avait retenue — deux variables, établissement et
voyageur. Elle a le mérite d'être la plus proche de ce que Meta approuve
couramment.

**Coût réel** : le destinataire ne peut plus trier sur les dates ni la chambre
sans ouvrir la pièce jointe. Acceptable, mais à ne pas concéder d'emblée : le
tri dans un fil unique est la raison d'être du résumé.

## Repli C — motif `SCAM` ou `ABUSIVE_CONTENT`

Rien à voir avec le nombre de variables. Meta lit un message non sollicité
vers des destinataires qui n'ont jamais écrit. Deux ajustements :

1. Nommer l'émetteur dans le corps : « Transmis par Qayed pour le compte de
   {{1}} » plutôt qu'un énoncé impersonnel.
2. Retirer toute formulation impérative du bouton — « Consulter la fiche »
   passe, « Cliquez ici » non.

## Ce qu'il ne faut pas faire

- **Ne pas retirer le bouton** pour simplifier : c'est le levier d'adoption du
  portail, et il n'entre pas dans le calcul du ratio texte-variables.
- **Ne pas passer en MARKETING** pour contourner un refus UTILITY : la
  catégorie serait fausse, le message coûterait plus cher, et une fiche de
  police n'est pas une sollicitation commerciale.
- **Ne pas relancer `--create` à l'identique** : Meta renverra le même refus,
  et chaque tentative pèse sur la réputation du compte.

## Après un repli

Le corps change, donc `BODY_VARIABLES` change : les tests
`test_template_components_carry_header_body_and_button` et
`test_template_variables_never_contain_line_breaks` s'appuient dessus et
échoueront tant que `FicheTemplate` n'aura pas suivi. C'est voulu — ils sont
le garde-fou qui empêche d'envoyer un nombre de variables différent de celui
qui a été approuvé (erreur 132000).
