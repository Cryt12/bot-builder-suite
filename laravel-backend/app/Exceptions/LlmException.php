<?php

namespace App\Exceptions;

/**
 * A generation attempt that failed against one provider.
 *
 * Carries the HTTP status so the caller can tell a provider-side blip apart from
 * a fault of our own: the first is worth retrying on the backup provider, the
 * second would just be hidden by doing so.
 */
class LlmException extends \RuntimeException
{
    public function __construct(
        public readonly string $provider,
        /** Null when the request never got a response at all (refused, timed out). */
        public readonly ?int $status,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether the backup provider should be tried.
     *
     * Transport failures and provider-side errors are transient or theirs to fix.
     * A 401/403 means our key is wrong and a 400 means we sent something invalid --
     * failing over on those would mask a broken configuration indefinitely.
     */
    public function retryable(): bool
    {
        if ($this->status === null) {
            return true;
        }

        return $this->status >= 500 || $this->status === 429 || $this->status === 408;
    }
}
