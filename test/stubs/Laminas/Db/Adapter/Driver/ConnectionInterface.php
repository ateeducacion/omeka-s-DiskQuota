<?php
namespace Laminas\Db\Adapter\Driver;

interface ConnectionInterface
{
    public function prepare($sql);
}
