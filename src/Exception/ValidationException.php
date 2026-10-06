<?php

declare(strict_types=1);

namespace OneTrace\Exception;

/**
 * HTTP 422: the request is invalid. getErrors() returns messages by field path, e.g. "batch.3.event".
 */
class ValidationException extends ApiException
{
    /**
     * @return array<string, list<string>>
     */
    public function getErrors(): array
    {
        $errors = $this->getPayload()['errors'] ?? [];
        $result = [];

        if (\is_array($errors)) {
            foreach ($errors as $field => $messages) {
                $result[(string) $field] = array_values(array_map(static function ($message): string {
                    return \is_scalar($message) ? (string) $message : '';
                }, (array) $messages));
            }
        }

        return $result;
    }
}
