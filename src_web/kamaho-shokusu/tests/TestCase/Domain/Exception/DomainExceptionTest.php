<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Exception;

use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\UnauthorizedException;
use Cake\TestSuite\TestCase;

/**
 * Domain 例外クラスのテスト。
 *
 * 各例外が正しい HTTP ステータスコードとメッセージを返すことを検証する。
 */
class DomainExceptionTest extends TestCase
{
    // ----------------------------------------------------------------
    // 継承関係
    // ----------------------------------------------------------------

    public function testAllExceptionsExtendDomainException(): void
    {
        $this->assertInstanceOf(DomainException::class, new InvalidInputException('test'));
        $this->assertInstanceOf(DomainException::class, new NotFoundException());
        $this->assertInstanceOf(DomainException::class, new ConflictException());
        $this->assertInstanceOf(DomainException::class, new UnauthorizedException());
    }

    // ----------------------------------------------------------------
    // InvalidInputException
    // ----------------------------------------------------------------

    public function testInvalidInputExceptionDefaultStatusIs422(): void
    {
        $e = new InvalidInputException('入力エラー');

        $this->assertSame(422, $e->getStatusCode());
        $this->assertSame('入力エラー', $e->getMessage());
    }

    public function testInvalidInputExceptionAcceptsCustomStatus(): void
    {
        $e = new InvalidInputException('不正なリクエスト', 400);

        $this->assertSame(400, $e->getStatusCode());
    }

    // ----------------------------------------------------------------
    // NotFoundException
    // ----------------------------------------------------------------

    public function testNotFoundExceptionStatusIs404(): void
    {
        $e = new NotFoundException();

        $this->assertSame(404, $e->getStatusCode());
    }

    public function testNotFoundExceptionHasDefaultMessage(): void
    {
        $e = new NotFoundException();

        $this->assertSame('リソースが見つかりません。', $e->getMessage());
    }

    public function testNotFoundExceptionAcceptsCustomMessage(): void
    {
        $e = new NotFoundException('ユーザーが見つかりません。');

        $this->assertSame('ユーザーが見つかりません。', $e->getMessage());
    }

    // ----------------------------------------------------------------
    // ConflictException
    // ----------------------------------------------------------------

    public function testConflictExceptionStatusIs409(): void
    {
        $e = new ConflictException();

        $this->assertSame(409, $e->getStatusCode());
    }

    public function testConflictExceptionHasDefaultMessage(): void
    {
        $e = new ConflictException();

        $this->assertSame('既に登録されています。', $e->getMessage());
    }

    public function testConflictExceptionAcceptsCustomMessage(): void
    {
        $e = new ConflictException('この日付は既に予約済みです。');

        $this->assertSame('この日付は既に予約済みです。', $e->getMessage());
    }

    // ----------------------------------------------------------------
    // UnauthorizedException
    // ----------------------------------------------------------------

    public function testUnauthorizedExceptionStatusIs403(): void
    {
        $e = new UnauthorizedException();

        $this->assertSame(403, $e->getStatusCode());
    }

    public function testUnauthorizedExceptionHasDefaultMessage(): void
    {
        $e = new UnauthorizedException();

        $this->assertSame('操作権限がありません。', $e->getMessage());
    }

    public function testUnauthorizedExceptionAcceptsCustomMessage(): void
    {
        $e = new UnauthorizedException('この部屋へのアクセス権がありません。');

        $this->assertSame('この部屋へのアクセス権がありません。', $e->getMessage());
    }

    // ----------------------------------------------------------------
    // 例外として throw できることの確認
    // ----------------------------------------------------------------

    public function testExceptionsAreThrowable(): void
    {
        $this->expectException(DomainException::class);
        throw new NotFoundException();
    }
}
