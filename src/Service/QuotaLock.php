<?php
declare(strict_types=1);

namespace DiskQuota\Service;

class QuotaLock
{
    private $connection;
    private $held = false;
    private $file;
    private $name;

    public function __construct($connection)
    {
        $this->connection = $connection;
    }

    public function acquire(): void
    {
        if ($this->held) {
            return;
        }
        if ($this->connection->isTransactionActive()) {
            throw new \RuntimeException('DiskQuota writes must start outside an existing database transaction.');
        }
        $platform = $this->connection->getDatabasePlatform()->getName();
        $params = $this->connection->getParams();
        if ($platform === 'mysql') {
            $this->name = 'omeka-diskquota-' . substr(hash('sha256', $params['dbname']), 0, 40);
            if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, 30)', [$this->name]) !== 1) {
                throw new \RuntimeException('Could not acquire the DiskQuota database lock.');
            }
        } elseif ($platform === 'sqlite') {
            $this->acquireFile($params['path'] ?? '');
        } else {
            throw new \RuntimeException('DiskQuota locking requires SQLite or MySQL/MariaDB.');
        }
        $this->held = true;
    }

    private function acquireFile(string $path): void
    {
        $path = realpath($path);
        if ($path === false) {
            throw new \RuntimeException('DiskQuota requires a persistent SQLite database file.');
        }
        $file = fopen($path . '.diskquota.lock', 'c');
        if ($file === false) {
            throw new \RuntimeException('Could not open the DiskQuota SQLite lock file.');
        }
        $deadline = microtime(true) + 30;
        do {
            if (flock($file, LOCK_EX | LOCK_NB)) {
                $this->file = $file;
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        fclose($file);
        throw new \RuntimeException('Timed out waiting for the DiskQuota SQLite lock.');
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }
        if ($this->file) {
            fclose($this->file);
            $this->file = null;
        } elseif ((int) $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$this->name]) !== 1) {
            throw new \RuntimeException('Could not release the DiskQuota database lock.');
        }
        $this->held = false;
    }
}
