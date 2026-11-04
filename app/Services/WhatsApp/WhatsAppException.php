<?php

namespace App\Services\WhatsApp;

/** The provider refused the message or could not be reached. The message never contains credentials. */
class WhatsAppException extends \RuntimeException
{
    /** The provider could not be reached at all (timeout, DNS, connection refused). */
    public const UNREACHABLE = -1;

    /**
     * Meta's "try again later" answers: throughput reached (130429), the number's rate limit (131056), the
     * pair rate limit (131048) and the app's own limit (80007). The message is sent again after a pause.
     */
    public const RETRY_LATER = [130429, 131056, 131048, 80007];

    /** Whether sending the same message again a little later may well succeed. */
    public function isTemporary(): bool
    {
        return $this->getCode() === self::UNREACHABLE || in_array($this->getCode(), self::RETRY_LATER, true);
    }
}
