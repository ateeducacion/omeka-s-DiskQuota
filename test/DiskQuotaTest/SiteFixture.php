<?php
declare(strict_types=1);

namespace DiskQuotaTest;

trait SiteFixture
{
    private function createSiteDatabase(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE item (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE media (id INTEGER PRIMARY KEY, item_id INTEGER, size INTEGER, has_original INTEGER)');
        $db->exec('CREATE TABLE item_site (item_id INTEGER, site_id INTEGER, PRIMARY KEY (item_id, site_id))');
        $db->exec('CREATE TABLE item_item_set (item_id INTEGER, item_set_id INTEGER)');
        $db->exec('CREATE TABLE site_item_set (site_id INTEGER, item_set_id INTEGER)');
        $db->exec('INSERT INTO item VALUES (1), (2), (3), (4), (5)');
        // Equal sizes must still count separately; non-original media must not count.
        $db->exec('INSERT INTO media VALUES (11,1,100,1), (12,1,100,1), (13,1,900,0), (21,2,300,1), (31,3,400,1)');
        $db->exec('INSERT INTO media VALUES (41,4,500,1), (51,5,600,1)');
        $db->exec('INSERT INTO item_site VALUES (1,10), (2,10), (2,20), (3,20), (4,30)');
        // Item 1 belongs to one attached set, item 2 to two. Item 3 belongs only to another site.
        $db->exec('INSERT INTO item_item_set VALUES (1,100), (2,100), (2,200), (3,100), (5,100)');
        $db->exec('INSERT INTO site_item_set VALUES (10,100), (10,200), (40,100)');
        return $db;
    }
}
