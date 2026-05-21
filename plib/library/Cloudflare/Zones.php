<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * Cloudflare zone (domain) operations.
 */
final class Zones
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Look up a zone by its exact domain name.
     *
     * @return array<string,mixed>|null the zone object, or null if not found
     */
    public function findByName(string $name): ?array
    {
        $result = $this->client->request('GET', 'zones', null, ['name' => DnsName::normalise($name)]);

        if (is_array($result) && isset($result[0]) && is_array($result[0])) {
            return $result[0];
        }

        return null;
    }

    /**
     * Return a zone's id, or null when the zone does not exist.
     */
    public function findId(string $name): ?string
    {
        $zone = $this->findByName($name);
        if ($zone === null) {
            return null;
        }

        $id = (string) ($zone['id'] ?? '');

        return $id !== '' ? $id : null;
    }

    /**
     * Create a zone. Requires an account-scoped token with Zone:Zone:Edit.
     *
     * @return array<string,mixed> the created zone object
     */
    public function create(string $name, string $accountId): array
    {
        $result = $this->client->request('POST', 'zones', [
            'name' => DnsName::normalise($name),
            'account' => ['id' => $accountId],
            'type' => 'full',
        ]);

        if (!is_array($result)) {
            throw new ApiException('Unexpected response creating zone "' . $name . '".');
        }

        return $result;
    }

    /**
     * Return the zone id, creating the zone first if it does not exist.
     */
    public function ensure(string $name, string $accountId): string
    {
        $existing = $this->findId($name);
        if ($existing !== null) {
            return $existing;
        }

        $created = $this->create($name, $accountId);
        $id = (string) ($created['id'] ?? '');

        if ($id === '') {
            throw new ApiException('Cloudflare did not return an id for new zone "' . $name . '".');
        }

        return $id;
    }
}
