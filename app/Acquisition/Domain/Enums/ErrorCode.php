<?php

namespace App\Acquisition\Domain\Enums;

/**
 * The shared error taxonomy every Tool speaks (ADR-0004). The Python worker
 * forwards these strings to the Agent verbatim and never interprets them;
 * retry decisions are made here, on the PHP side.
 */
enum ErrorCode: string
{
    case Timeout = 'TIMEOUT';
    case RateLimited = 'RATE_LIMITED';
    case ServerError = 'SERVER_ERROR';
    case ConnectionFailed = 'CONNECTION_FAILED';
    case TlsError = 'TLS_ERROR';
    case ClientError = 'CLIENT_ERROR';
    case RedirectLoop = 'REDIRECT_LOOP';
    case HostNotAllowed = 'HOST_NOT_ALLOWED';
    case RobotsDisallowed = 'ROBOTS_DISALLOWED';
    case BodyTooLarge = 'BODY_TOO_LARGE';
    case ContentTypeMismatch = 'CONTENT_TYPE_MISMATCH';
    case InvalidInput = 'INVALID_INPUT';
    case ParseFailed = 'PARSE_FAILED';
    case XmlExternalEntityRejected = 'XML_EXTERNAL_ENTITY_REJECTED';
    case UnsupportedScannedPdf = 'UNSUPPORTED_SCANNED_PDF';
    case EncryptedPdf = 'ENCRYPTED_PDF';
    case QualityFailed = 'QUALITY_FAILED';
    case BudgetExhausted = 'BUDGET_EXHAUSTED';
    case Internal = 'INTERNAL';

    /**
     * Only transient transport failures are worth retrying; everything else
     * would fail the same way again and just burn the crawl budget.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::Timeout,
            self::RateLimited,
            self::ServerError,
            self::ConnectionFailed => true,
            default => false,
        };
    }
}
