<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use Authorization\IdentityInterface;
use Authorization\Policy\ResultInterface;

/**
 * 元データが Entity や ArrayObject など「配列以外」の Identity ダブル（ポリシーの分岐網羅用）。
 */
final class ObjectPolicyIdentity implements IdentityInterface
{
    public function __construct(private \ArrayAccess|array|null $original, private ?int $admin = null)
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
        return $this->original;
    }

    public function get(string $field): mixed
    {
        return $field === 'i_admin' ? $this->admin : null;
    }

    public function offsetExists(mixed $offset): bool
    {
        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}
