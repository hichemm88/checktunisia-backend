<?php

namespace App\Contracts;

use App\Models\Hotel;
use App\Models\WhatsappSendLog;

/**
 * Canal de transmission d'une fiche à l'autorité (STRAT-07).
 *
 * Le canal de transmission est, par nature, ce qui varie le plus d'un pays à
 * l'autre et dans le temps : WhatsApp Web aujourd'hui en Tunisie, API Cloud
 * officielle demain, dépôt SFTP ou portail national ailleurs. Ce qui varie le
 * plus doit être ce qui est le plus isolé — c'était l'inverse jusqu'ici, le
 * relais WhatsApp étant infiltré dans des chemins pérennes.
 *
 * Cette interface ne prétend PAS que tous les canaux se ressemblent. Deux
 * modèles coexistent réellement :
 *
 *  - PULL  : un worker externe réclame les jobs et rend compte (WhatsApp Web).
 *            PHP ne transmet rien lui-même.
 *  - PUSH  : PHP appelle directement l'API du canal (Cloud API, SFTP…).
 *
 * `supportsPush()` expose cette différence au lieu de la masquer derrière une
 * abstraction qui fuirait à la première utilisation.
 */
interface DeliveryChannel
{
    /** Identifiant stable, journalisé avec chaque envoi (ex. « whatsapp_web »). */
    public function name(): string;

    /**
     * Le canal est-il configuré et utilisable ?
     * Faux = les fiches ne doivent pas lui être confiées.
     */
    public function isConfigured(): bool;

    /**
     * PHP transmet-il lui-même (true), ou un worker externe réclame-t-il les
     * jobs (false) ?
     */
    public function supportsPush(): bool;

    /**
     * Numéro international brut → adresse native du canal.
     * Renvoie null si le numéro est inexploitable.
     *
     * Exemples : « +216 20 123 456 » → « 21620123456@c.us » (WhatsApp Web),
     * ou « 21620123456 » (Cloud API, qui attend un numéro nu).
     */
    public function formatRecipient(?string $number): ?string;

    /**
     * Destinataires d'un établissement, dans le format natif du canal.
     * Jamais vide si le canal est configuré : au pire le destinataire global.
     *
     * @return array<int,string>
     */
    public function recipientsFor(?Hotel $hotel): array;

    /**
     * Transmet un job.
     *
     * À n'appeler que si supportsPush() est vrai. Les canaux en pull lèvent
     * une exception : leur transmission passe par le worker externe.
     */
    public function send(WhatsappSendLog $job): DeliveryResult;
}
