<?php
declare(strict_types=1);

namespace DiskQuotaTest\Service;

use DiskQuota\Service\QuotaLock;
use PHPUnit\Framework\TestCase;

class QuotaLockTest extends TestCase
{
    public function testSqliteLockIsSharedAndReentrantUntilReleased(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'diskquota-lock-');
        $connection = $this->connection('sqlite', ['path' => $path]);
        $lock = new QuotaLock($connection);
        try {
            $lock->acquire();
            $lock->acquire();
            $probe = fopen($path . '.diskquota.lock', 'c');
            $this->assertFalse(flock($probe, LOCK_EX | LOCK_NB));
            $lock->release();
            $this->assertTrue(flock($probe, LOCK_EX | LOCK_NB));
            fclose($probe);
            $lock->release();
        } finally {
            $lock->release();
            unlink($path);
            unlink($path . '.diskquota.lock');
        }
    }

    /** @dataProvider failedMysqlLocks */
    public function testMysqlLockFailureRejectsTheWrite($result): void
    {
        $connection = $this->connection('mysql', ['dbname' => 'quota']);
        $connection->method('fetchOne')->willReturn($result);
        $lock = new QuotaLock($connection);
        $this->expectException(\RuntimeException::class);
        $lock->acquire();
    }

    public function failedMysqlLocks(): array
    {
        return [[0], [null]];
    }

    public function testMysqlAcquiresOnlyOnceAndReleasesTheSameDatabaseLock(): void
    {
        $connection = $this->connection('mysql', ['dbname' => 'quota']);
        $calls = [];
        $connection->method('fetchOne')->willReturnCallback(function ($sql, $params) use (&$calls) {
            $calls[] = [$sql, $params];
            return 1;
        });
        $lock = new QuotaLock($connection);
        $lock->acquire();
        $lock->acquire();
        $lock->release();
        $lock->release();
        $this->assertCount(2, $calls);
        $this->assertSame('SELECT GET_LOCK(?, 30)', $calls[0][0]);
        $this->assertSame('SELECT RELEASE_LOCK(?)', $calls[1][0]);
        $this->assertSame($calls[0][1], $calls[1][1]);
    }

    public function testExistingDatabaseTransactionCannotUseAStaleQuotaSnapshot(): void
    {
        $connection = $this->connection('mysql', ['dbname' => 'quota'], true);
        $lock = new QuotaLock($connection);
        $this->expectException(\RuntimeException::class);
        $lock->acquire();
    }

    public function testUnsupportedDatabaseFailsClosed(): void
    {
        $lock = new QuotaLock($this->connection('postgresql', []));
        $this->expectException(\RuntimeException::class);
        $lock->acquire();
    }

    public function testMissingSqliteDatabaseCannotApproveAWritingOperation(): void
    {
        $lock = new QuotaLock($this->connection('sqlite', ['path' => ':memory:']));
        $this->expectException(\RuntimeException::class);
        $lock->acquire();
    }

    public function testMysqlReleaseFailureIsReported(): void
    {
        $connection = $this->connection('mysql', ['dbname' => 'quota']);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(1, 0);
        $lock = new QuotaLock($connection);
        $lock->acquire();
        $this->expectException(\RuntimeException::class);
        $lock->release();
    }

    private function connection(string $name, array $params, bool $transaction = false)
    {
        $platform = $this->getMockBuilder(\stdClass::class)->addMethods(['getName'])->getMock();
        $platform->method('getName')->willReturn($name);
        $connection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getDatabasePlatform', 'getParams', 'fetchOne', 'isTransactionActive'])->getMock();
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getParams')->willReturn($params);
        $connection->method('isTransactionActive')->willReturn($transaction);
        return $connection;
    }
}
