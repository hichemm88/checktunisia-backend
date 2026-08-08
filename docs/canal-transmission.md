# Canal de transmission des fiches

## L'échéance

Le canal de transmission légal actuel repose sur **WhatsApp Web non officiel** (`whatsapp-web.js`), maintenu fonctionnel par un pin de version dont le contournement **expire le 10 septembre 2026**. Après cette date, les envois média casseront probablement.

Ce n'est pas une ligne de backlog : c'est une échéance au calendrier sur le canal qui porte l'obligation légale du produit.

## Ce qui a été fait

Le relais WhatsApp était infiltré dans des chemins pérennes : le déposer aurait été une opération chirurgicale sur une quinzaine de fichiers. Il est désormais derrière une interface.

```
App\Contracts\DeliveryChannel          ← le contrat
├── WhatsAppWebChannel   (« web »)     ← existant, actif, INCHANGÉ
└── WhatsAppCloudChannel (« cloud »)   ← nouveau, construit à côté, inactif
```

L'extraction est **à comportement identique** : les 24 tests existants du relais et du routage direct passent sans la moindre retouche. C'est la preuve, pas une intention.

### Pull contre push

L'interface n'affirme pas que les deux canaux se ressemblent, parce qu'ils ne se ressemblent pas :

| | WhatsApp Web | Cloud API |
|---|---|---|
| Modèle | **pull** — le worker Node réclame les jobs | **push** — PHP appelle l'API |
| Format destinataire | `21620123456@c.us` | `21620123456` |
| Session | QR à appairer, volume à maintenir | jeton, sans état |
| Point de défaillance | instance et session uniques | aucun worker |

`supportsPush()` expose cette différence. `WhatsAppWebChannel::send()` lève volontairement une exception : sa transmission passe par le worker, un appel direct serait un bug d'appelant.

## Procédure de bascule

### 1. Observer en mode ombre

Avant d'envoyer le moindre message réel par la Cloud API, on exerce ce canal **à blanc** sur le trafic de production :

```
WHATSAPP_SHADOW_CHANNEL=cloud
```

À chaque enfilage, le canal cible résout et formate les destinataires, et tout écart est journalisé sous `[delivery-shadow]`. **Rien n'est transmis, aucun appel réseau n'est fait.**

Le signal qui compte est l'écart de nombre de destinataires : il signifie qu'après bascule, une fiche partirait à plus — ou pire, à moins — de monde qu'aujourd'hui.

Laisser tourner plusieurs jours et vérifier l'absence de `[delivery-shadow] écart`.

### 2. Configurer la Cloud API

```
WHATSAPP_CLOUD_TOKEN=...
WHATSAPP_CLOUD_PHONE_NUMBER_ID=...
```

Prérequis côté Meta : compte Business vérifié, numéro enregistré, et — point souvent sous-estimé — **modèles de message approuvés** si l'envoi sort de la fenêtre de 24 h de conversation. Ce dernier point est le principal risque de calendrier : l'approbation ne dépend pas de nous.

### 3. Basculer

```
WHATSAPP_CHANNEL=cloud
```

### 4. Ne rien supprimer

Le canal `web`, le worker Node et le module « provisoire » **restent en place** jusqu'à ce que le nouveau chemin ait fait ses preuves sur plusieurs semaines. Le retour arrière doit rester à une variable d'environnement.

## Retour arrière

```
WHATSAPP_CHANNEL=web
```

Effet immédiat, sans redéploiement si la configuration n'est pas mise en cache. Le worker Node n'a jamais été arrêté : il reprend son sondage.

## Ce que l'extraction ne fait pas encore

Honnêtement, ce qui reste :

- **La transmission par push n'est pas branchée sur l'outbox.** `WhatsAppCloudChannel::send()` est implémenté et testé, mais aucun processus ne l'appelle encore en boucle : il faudra un job ou une commande qui consomme la file et invoque `send()` quand `supportsPush()` est vrai. C'est le travail restant de la bascule.
- **Les photos ne sont pas gérées par le canal Cloud.** L'envoi actuel joint l'image du document ; l'adaptateur ne fait pour l'instant que le texte. La Cloud API demande un téléversement média préalable (endpoint `/media`), qui reste à écrire.
- **Le mode ombre compare les destinataires, pas les contenus.** C'est là que se logent les différences structurelles entre les deux canaux ; comparer les corps de message viendrait ensuite.

Ces manques sont délibérés : l'objectif de cette étape était de rendre la bascule *possible* sans changer le comportement, pas de la réaliser.
