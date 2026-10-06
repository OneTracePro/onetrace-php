<?php

declare(strict_types=1);

namespace OneTrace\Exception;

use OneTrace\Http\Response;

/**
 * The API answered with an error status. Specific statuses have their own subclasses.
 */
class ApiException extends \RuntimeException implements OneTraceException
{
    private Response $response;

    /** @var array<string, mixed> */
    private array $payload;

    /**
     * @param array<string, mixed> $payload decoded JSON body
     */
    final public function __construct(string $message, Response $response, array $payload = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $response->getStatus(), $previous);
        $this->response = $response;
        $this->payload = $payload;
    }

    /**
     * Builds the exception class that matches the response status.
     */
    public static function fromResponse(Response $response): self
    {
        $payload = json_decode($response->getBody(), true);
        $payload = \is_array($payload) ? $payload : [];
        $message = isset($payload['message']) && \is_string($payload['message']) && $payload['message'] !== ''
            ? $payload['message']
            : sprintf('HTTP %d', $response->getStatus());

        $status = $response->getStatus();
        $class = self::class;

        if ($status === 401) {
            $class = AuthenticationException::class;
        } elseif ($status === 402) {
            $class = PaymentRequiredException::class;
        } elseif ($status === 403) {
            $class = PermissionException::class;
        } elseif ($status === 404) {
            $class = NotFoundException::class;
        } elseif ($status === 409) {
            $class = ConflictException::class;
        } elseif ($status === 413) {
            $class = PayloadTooLargeException::class;
        } elseif ($status === 422) {
            $class = ValidationException::class;
        } elseif ($status === 429) {
            $class = RateLimitException::class;
        } elseif ($status >= 500) {
            $class = ServerException::class;
        }

        return new $class($message, $response, $payload);
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatus();
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
