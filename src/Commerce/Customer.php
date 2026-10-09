<?php

declare(strict_types=1);

namespace OneTrace\Commerce;

/**
 * A shop customer as e-commerce plugins send it (docs: integrations/ecommerce). Empty values are left out, so they
 * never erase known traits; the phone is kept only in international format.
 */
final class Customer
{
    /** @var string|null id of the customer in the shop; null for guests */
    public $userId;

    /** @var string|null value of the tracker cookie cdp_aid, links browser events to the customer */
    public $anonymousId;

    /** @var array<string, string|int|float|bool> */
    private $traits = [];

    public function __construct(?string $userId = null, ?string $anonymousId = null)
    {
        $this->userId = self::text($userId);
        $this->anonymousId = self::text($anonymousId);
    }

    public function email(?string $email): self
    {
        $email = self::text($email);

        return $this->with('email', $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($email) : null);
    }

    /**
     * Keeps the phone only in E.164: "+49 151 1234-5678" and "0049…" become "+4915112345678"; a national number
     * without the country code is left out (the platform could not tell the country).
     */
    public function phone(?string $phone): self
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $phone) ?? '';
        $digits = strpos($digits, '00') === 0 ? '+' . substr($digits, 2) : $digits;

        return $this->with('phone', preg_match('/^\+[1-9]\d{6,14}$/', $digits) === 1 ? $digits : null);
    }

    public function name(?string $firstName, ?string $lastName = null): self
    {
        return $this->with('first_name', self::text($firstName))->with('last_name', self::text($lastName));
    }

    public function city(?string $city): self
    {
        return $this->with('city', self::text($city));
    }

    /** ISO 3166-1 alpha-2, e.g. "DE". */
    public function country(?string $country): self
    {
        $country = strtoupper((string) self::text($country));

        return $this->with('country', preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null);
    }

    /** Language of the customer's storefront: "de", "pt_BR" and "pt-br" become BCP 47 ("pt-BR"). */
    public function language(?string $language): self
    {
        return $this->with('language', self::languageTag($language));
    }

    /**
     * BCP 47 tag of a platform locale ("pt_BR" → "pt-BR"), null if it does not look like a language.
     */
    public static function languageTag(?string $language): ?string
    {
        $parts = preg_split('/[-_]/', (string) self::text($language)) ?: [];
        $tag = null;

        if (isset($parts[0]) && preg_match('/^[a-zA-Z]{2,3}$/', $parts[0]) === 1) {
            $tag = strtolower($parts[0]);

            foreach (array_slice($parts, 1, 2) as $part) {
                if (preg_match('/^[a-zA-Z]{4}$/', $part) === 1) {
                    $tag .= '-' . ucfirst(strtolower($part));
                } elseif (preg_match('/^([a-zA-Z]{2}|\d{3})$/', $part) === 1) {
                    $tag .= '-' . strtoupper($part);
                }
            }
        }

        return $tag;
    }

    public function group(?string $group): self
    {
        return $this->with('customer_group', self::text($group));
    }

    /**
     * Any other profile trait, e.g. trait('loyalty_level', 'gold'); null and '' are ignored.
     *
     * @param string|int|float|bool|null $value
     */
    public function trait(string $key, $value): self
    {
        if ($value === null || $value === '') {
            return $this;
        }

        $copy = clone $this;
        $copy->traits[$key] = $value;

        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    public function traits(): array
    {
        return $this->traits;
    }

    /** The customer can be identified: a user id, an email or a phone. */
    public function identifiable(): bool
    {
        return $this->userId !== null || isset($this->traits['email']) || isset($this->traits['phone']);
    }

    /**
     * userId and anonymousId of a message. A guest without the tracker cookie gets a stable anonymousId derived from
     * the email (or the phone): identify carries it together with the email, so the guest's order events land in
     * the same profile.
     *
     * @return array<string, string>
     */
    public function ids(): array
    {
        $anonymousId = $this->anonymousId;

        if ($this->userId === null && $anonymousId === null) {
            $key = $this->traits['email'] ?? $this->traits['phone'] ?? null;
            $anonymousId = $key !== null ? 'guest:' . substr(hash('sha256', (string) $key), 0, 32) : null;
        }

        return array_filter(['userId' => $this->userId, 'anonymousId' => $anonymousId], static function ($value): bool {
            return $value !== null;
        });
    }

    private function with(string $key, ?string $value): self
    {
        $copy = clone $this;

        if ($value === null) {
            unset($copy->traits[$key]);
        } else {
            $copy->traits[$key] = $value;
        }

        return $copy;
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
