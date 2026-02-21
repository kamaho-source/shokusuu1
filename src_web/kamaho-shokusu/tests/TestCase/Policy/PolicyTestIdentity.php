<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use Authorization\IdentityInterface;
use Authorization\Policy\ResultInterface;

class PolicyTestIdentity implements IdentityInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    public function can(string $action, mixed $resource): bool
    {
        return false;
    }

    public function canResult(string $action, mixed $resource): ResultInterface
    {
        throw new \BadMethodCallException('Not used in policy tests.');
    }

    public function applyScope(string $action, mixed $resource, mixed ...$optionalArgs): mixed
    {
        return $resource;
    }

    public function getOriginalData(): \ArrayAccess|array
    {
        return $this->data;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->data[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }
}
