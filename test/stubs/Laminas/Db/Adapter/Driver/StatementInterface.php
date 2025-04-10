<?php
namespace Laminas\Db\Adapter\Driver;

interface StatementInterface
{
    public function execute();
    public function fetchColumn($column = null);
}
