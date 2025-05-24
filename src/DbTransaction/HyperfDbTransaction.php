<?php

declare(strict_types=1);
/**
 * This file is part of DTM-PHP.
 *
 * @license  https://github.com/dtm-php/dtm-client/blob/master/LICENSE
 */

namespace DtmClient\DbTransaction;

use DtmClient\Config\DatabaseConfigInterface;
use DtmClient\Exception\RuntimeException;
use DtmClient\TransContext;
use Hyperf\Collection\Arr;
use Hyperf\Context\Context;
use Hyperf\DbConnection\Db;
use Hyperf\Pool\Exception\ConnectionException;
use PDO;

class HyperfDbTransaction extends AbstractTransaction
{
    public function __construct(DatabaseConfigInterface $config)
    {
        $this->databaseConfig = $config;
    }

    public function beginTransaction()
    {
        Db::beginTransaction();
    }

    public function commit()
    {
        Db::commit();
    }

    public function rollback()
    {
        Db::rollback();
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return Db::affectingStatement($sql, $bindings);
    }

    public function query(string $sql, array $bindings = []): bool|array
    {
        return Db::select($sql, $bindings);
    }

    public function getPools()
    {
        $customData = TransContext::getCustomData();

        $customData = $customData ? json_decode($customData, true) : [];

        $pools = [];
        if (isset($customData['customData']['databases'])) {
            $pools = $customData['customData']['databases'];
//            var_dump(__METHOD__,$pools);
        } else {
            $pools = ['default'];
        }

        return $pools;
    }

    public function xaExecute(string $sql, array $bindings = []): int
    {
        $offset = 0;

        $pools = $this->getPools();
        if ($pools) {
//            var_dump(__METHOD__,$pools,$sql);
            foreach ($pools as $pool) {
                $offset = Db::connection($pool)->affectingStatement($sql, $bindings);

//                $statement = $this->connectDb($pool)->prepare($sql);
//
//                $this->bindValues($statement, $bindings);
//
//                $statement->execute();
//
//                $offset = $statement->rowCount();
            }
        } else {
            $statement = $this->connect()->prepare($sql);

            $this->bindValues($statement, $bindings);

            $statement->execute();

            $offset = $statement->rowCount();
        }

//        var_dump($offset);

        return $offset;
    }

    public function xaExec(string $sql): int|false
    {
        $rs = false;

        $pools = $this->getPools();
        if ($pools) {
//            var_dump(__METHOD__,$pools,$sql);
            foreach ($pools as $pool) {
                $rs = Db::connection($pool)->affectingStatement($sql, []);
                $rs = $rs === true ? 1 : $rs;
//                $rs = $this->connectDb($pool)->exec($sql);
            }

        } else {
            $rs = $this->connect()->exec($sql);
        }

//        var_dump($rs);
        return $rs;
    }

    public function reconnect(): PDO
    {
        $pools = $this->getPools();
        if ($pools) {
            $pdo = [];
//            var_dump(__METHOD__,$pools);
            foreach ($pools as $pool) {
                Context::set('dtm.connect.' . $pool, null);
                $pdo[$pool] = $this->connectDb($pool);
            }
            return Arr::first($pdo);
        } else {
            Context::set('dtm.connect', null);
            return $this->connect();
        }
    }

    protected function connectDb($pool = ''): PDO
    {
        if (!isset($this->databaseConfig->getOptions()[PDO::ATTR_AUTOCOMMIT])) {
            throw new RuntimeException('please set autocommit is false');
        }

        $pdo = Context::get('dtm.connect.' . $pool);
        if (!empty($pdo)) {
            return $pdo;
        }

        $pdo = Db::connection($pool)->getPdo();

        Context::set('dtm.connect.' . $pool, $pdo);

        return $pdo;
    }
}
