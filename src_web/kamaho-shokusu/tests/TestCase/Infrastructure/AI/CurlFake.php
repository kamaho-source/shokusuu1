<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\AI {

    /**
     * curl_* をテスト中だけ差し替える偽ハンドル兼シナリオ保持クラス。
     *
     * 本番コードは App\Infrastructure\AI / App\Controller 名前空間から curl_* を呼ぶため、
     * 同名空間に関数を定義すると（有効化中のみ）こちらが優先される。無効時は本物へ委譲する。
     */
    final class CurlFake
    {
        public static bool $active = false;
        /** @var list<string> WRITEFUNCTION へ順に渡すチャンク（ストリーミング時） */
        public static array $chunks = [];
        /** curl_exec が RETURNTRANSFER 時に返す本文 */
        public static string $body = '';
        public static int $status = 200;
        public static string $error = '';
        public static bool $execFails = false;
        /** @var array<int, mixed> 直近の curl_setopt_array の内容 */
        public static array $lastOptions = [];

        public int $httpStatus = 0;

        public static function enable(): void
        {
            self::$active = true;
            self::$chunks = [];
            self::$body = '';
            self::$status = 200;
            self::$error = '';
            self::$execFails = false;
            self::$lastOptions = [];
        }

        public static function disable(): void
        {
            self::$active = false;
        }

        public function exec(): string|bool
        {
            $this->httpStatus = self::$status;
            if (self::$execFails) {
                return false;
            }
            $write = self::$lastOptions[CURLOPT_WRITEFUNCTION] ?? null;
            if (is_callable($write)) {
                foreach (self::$chunks as $chunk) {
                    $write($this, $chunk);
                }

                return true;
            }

            return self::$body;
        }
    }
}

namespace App\Infrastructure\AI {

    use App\Test\TestCase\Infrastructure\AI\CurlFake;

    function curl_init(): \CurlHandle|CurlFake
    {
        return CurlFake::$active ? new CurlFake() : \curl_init();
    }

    function curl_setopt_array(\CurlHandle|CurlFake $ch, array $options): bool
    {
        if ($ch instanceof CurlFake) {
            CurlFake::$lastOptions = $options;

            return true;
        }

        return \curl_setopt_array($ch, $options);
    }

    function curl_exec(\CurlHandle|CurlFake $ch): string|bool
    {
        return $ch instanceof CurlFake ? $ch->exec() : \curl_exec($ch);
    }

    function curl_getinfo(\CurlHandle|CurlFake $ch, ?int $option = null): mixed
    {
        return $ch instanceof CurlFake ? $ch->httpStatus : \curl_getinfo($ch, $option);
    }

    function curl_error(\CurlHandle|CurlFake $ch): string
    {
        return $ch instanceof CurlFake ? CurlFake::$error : \curl_error($ch);
    }

    function curl_close(\CurlHandle|CurlFake $ch): void
    {
        if ($ch instanceof CurlFake) {
            return;
        }
        \curl_close($ch);
    }
}

namespace App\Controller {

    use App\Test\TestCase\Infrastructure\AI\CurlFake;

    function curl_init(): \CurlHandle|CurlFake
    {
        return CurlFake::$active ? new CurlFake() : \curl_init();
    }

    function curl_setopt_array(\CurlHandle|CurlFake $ch, array $options): bool
    {
        if ($ch instanceof CurlFake) {
            CurlFake::$lastOptions = $options;

            return true;
        }

        return \curl_setopt_array($ch, $options);
    }

    function curl_exec(\CurlHandle|CurlFake $ch): string|bool
    {
        return $ch instanceof CurlFake ? $ch->exec() : \curl_exec($ch);
    }

    function curl_getinfo(\CurlHandle|CurlFake $ch, ?int $option = null): mixed
    {
        return $ch instanceof CurlFake ? $ch->httpStatus : \curl_getinfo($ch, $option);
    }

    function curl_error(\CurlHandle|CurlFake $ch): string
    {
        return $ch instanceof CurlFake ? CurlFake::$error : \curl_error($ch);
    }

    function curl_close(\CurlHandle|CurlFake $ch): void
    {
        if ($ch instanceof CurlFake) {
            return;
        }
        \curl_close($ch);
    }
}
