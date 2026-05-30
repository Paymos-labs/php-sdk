<?php

declare(strict_types=1);

namespace Paymos\Webhook;

final class VerifiedWebhookEvent
{
    /** @var string */
    private $environment;

    /** @var WebhookEvent */
    private $event;

    public function __construct($environment, WebhookEvent $event)
    {
        $environment = trim((string) $environment);
        if ($environment === '') {
            throw new \InvalidArgumentException('Verified webhook environment must be non-empty.');
        }

        $this->environment = $environment;
        $this->event = $event;
    }

    public function environment()
    {
        return $this->environment;
    }

    public function event()
    {
        return $this->event;
    }
}
