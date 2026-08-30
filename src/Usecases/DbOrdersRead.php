<?php

namespace Asyncopath\Usecases;


use Asyncopath\Data\SqlJoin;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Luzrain\DbalDriver\AmphpPostgres\AsyncConection;
use Luzrain\DbalDriver\AmphpPostgres\AsyncDriver;

class DbOrdersRead
{
    // private PostgresConnection $conn;
    private Connection $conn;

    public function __construct()
    {
        // $config = PostgresConfig::fromString("host=localhost password=dev user=dev db=dev port=5002");

        // $this->conn = $pool = new PostgresConnectionPool($config);
        $connectionParams = [
            'dbname' => 'dev',
            'user' => 'dev',
            'password' => 'dev',
            'host' => 'localhost',
            'port' => 5002,
            'driverClass' => AsyncDriver::class,
            'wrapperClass' => AsyncConection::class,
            'driverOptions' => [
                'max_connections' => 100,
                'idle_timeout' => 60,
            ],
        ];
        $this->conn = DriverManager::getConnection($connectionParams);
    }

    public function fetchById()
    {
        $data = $this->queryBuilder();
        $sqlJoin = new SqlJoin();
        $leftFields = [
            'id',
            'magalu_id',
            'external_id',
            'source',
            'bag_id',
            'seller_id',
            'customer_id',
            'status_id',
            'total_amount',
            'created_at',
            'updated_at',
            'uuid',
            'items',
        ];
        $rightSide = ["item_id", "order_id", "product_id", "quantity", "price", "option_groups", "name",];
        return $sqlJoin->parseLeftJoin($data, 'id', $leftFields, $rightSide, 'items');
        // foreach ($result as $row) {
        //     // $row is an associative-array of column values, e.g.: $row['column_name']
        //     yield $row;
        // }
    }

    private function queryBuilder(): array
    {
        $queryBuilder = $this->conn->createQueryBuilder();
        $fields = [
            'o.id',
            'o.magalu_id',
            'o.external_id',
            'o."source"',
            'o.bag_id',
            'o.seller_id',
            'o.customer_id',
            'o.status_id',
            'o.total_amount',
            'o.created_at',
            'o.updated_at',
            'o."uuid"',
            'oi.id as item_id',
            'oi.order_id',
            'oi.product_id',
            'oi.quantity',
            'oi.price',
            'oi.created_at',
            'oi.option_groups',
            'oi."name"',
            'oi.sku',
            'oi.brand',
            'oi.category',
            'oi.sub_category',
            'oi.commission_fee',
            'oi.product_external_id',
            'oi.discount as item_discount',
        ];
        $qb = $queryBuilder
            ->select(...$fields)
            ->from('orders', 'o')
            ->leftJoin('o', 'order_items', 'oi', 'o.id = oi.order_id')
            ->where('o.id = ?')
            ->setParameter(0, 125);

        $query = $qb->executeQuery();

        return $query->fetchAllAssociative();
    }

}