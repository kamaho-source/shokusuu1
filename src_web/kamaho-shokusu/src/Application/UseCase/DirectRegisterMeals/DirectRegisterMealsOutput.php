<?php
declare(strict_types=1);

namespace App\Application\UseCase\DirectRegisterMeals;

/**
 * カレンダークリック直接登録のOutput DTO。
 */
final class DirectRegisterMealsOutput
{
    /**
     * @param list<int> $registered 今回新規登録された食事区分
     * @param list<int> $skipped    すでに登録済みだったためスキップした食事区分
     */
    public function __construct(
        public readonly array $registered,
        public readonly array $skipped,
    ) {}
}
