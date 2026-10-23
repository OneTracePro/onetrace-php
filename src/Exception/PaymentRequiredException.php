<?php

declare(strict_types=1);

namespace OneTrace\Exception;

/**
 * HTTP 402: the feature is not included in the plan ("feature_unavailable", see getFeature()), the project is
 * read-only because the trial or the payment grace period has ended ("subscription_expired"), or a monthly limit
 * of the plan is spent ("quota_exceeded", e.g. product search requests).
 */
class PaymentRequiredException extends ApiException
{
    /**
     * feature_unavailable, subscription_expired, quota_exceeded or null.
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
