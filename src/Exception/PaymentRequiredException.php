<?php

declare(strict_types=1);

namespace OneTrace\Exception;

/**
 * HTTP 402: the feature is not included in the plan ("feature_unavailable", see getFeature()) or the project is
 * read-only because the trial or the payment grace period has ended ("subscription_expired").
 */
class PaymentRequiredException extends ApiException
{
    /**
     * feature_unavailable, subscription_expired or null.
     */
    public function getReason(): ?string
    {
        $code = $this->getPayload()['code'] ?? null;

        return \is_string($code) ? $code : null;
    }

    /**
     * The missing feature for feature_unavailable, e.g. "recommendations".
     */
    public function getFeature(): ?string
    {
        $feature = $this->getPayload()['feature'] ?? null;

        return \is_string($feature) ? $feature : null;
    }
}
