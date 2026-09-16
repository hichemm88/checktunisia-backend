<?php

namespace App\Exceptions\PartnerApi;

use App\Services\PartnerApi\ErrorCodes;
use Exception;

/**
 * Exception portant un code d'erreur stable de l'API partenaire (§2).
 * Rendue par bootstrap/app.php en {"error":{code,message,doc_url}} — le
 * format Stripe-style propre à /v1/* et /widget/v1/*, distinct de l'enveloppe
 * {data,errors:[]} du reste de l'application (voir API-V1-DECISIONS.md, D3).
 */
class PartnerApiException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        ?string $message = null,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message ?? ErrorCodes::MESSAGES[$errorCode] ?? $errorCode);
    }

    public function status(): int
    {
        return ErrorCodes::HTTP_STATUS[$this->errorCode] ?? 400;
    }

    public function docUrl(): string
    {
        return 'https://qayed.tn/docs/api#'.$this->errorCode;
    }

    public function toResponseArray(): array
    {
        return [
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'doc_url' => $this->docUrl(),
            ],
        ];
    }
}
