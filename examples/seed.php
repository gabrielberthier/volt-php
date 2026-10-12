<?php

require_once 'vendor/autoload.php';

$start = microtime(true);

// 1. Establish PDO PostgreSQL Connection
$dsn = "pgsql:host=host.docker.internal;port=5432;dbname=analytics_db;";
$user = "user";
$password = "pass";

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

echo "Connected to database. Rebuilding schema...\n";

// 2. Drop existing tables and recreate them
$pdo->exec("
    DROP TABLE IF EXISTS order_items;
    DROP TABLE IF EXISTS orders;
    DROP TABLE IF EXISTS products;
    
    CREATE TABLE products (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        category VARCHAR(100) NOT NULL,
        price DECIMAL(10,2) NOT NULL
    );

    CREATE TABLE orders (
        id SERIAL PRIMARY KEY,
        customer_id INT NOT NULL,
        region VARCHAR(50) NOT NULL,
        order_date TIMESTAMP NOT NULL
    );

    CREATE TABLE order_items (
        id SERIAL PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL
    );
");

$faker = Faker\Factory::create();

// Configuration for database size
$num_products = 5000;
$num_orders = 100000;
$regions = ['North America', 'Europe', 'Asia'];
$categories = ['Electronics', 'Home & Kitchen', 'Apparel', 'Books', 'Sports', 'Toys'];

// 3. Seed Products
echo "Seeding $num_products Products...\n";
$pdo->beginTransaction();
$stmt = $pdo->prepare("INSERT INTO products (name, category, price) VALUES (?, ?, ?)");

$product_prices = []; // Store prices to use later in order_items

for ($i = 1; $i <= $num_products; $i++) {
    $price = $faker->randomFloat(2, 5, 1000);
    $product_prices[$i] = $price; 
    
    $stmt->execute([
        $faker->words(3, true),
        $faker->randomElement($categories),
        $price
    ]);
}
$pdo->commit();


// 4. Seed Orders
echo "Seeding $num_orders Orders...\n";
$pdo->beginTransaction();
$stmt = $pdo->prepare("INSERT INTO orders (customer_id, region, order_date) VALUES (?, ?, ?)");

for ($i = 1; $i <= $num_orders; $i++) {
    $stmt->execute([
        $faker->numberBetween(1, 3000), // Simulate 3,000 unique customers
        $faker->randomElement($regions),
        $faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d H:i:s')
    ]);
}
$pdo->commit();


// 5. Seed Order Items (1 to 4 items per order)
echo "Seeding Order Items...\n";
$pdo->beginTransaction();
$stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");

$total_items = 0;
for ($order_id = 1; $order_id <= $num_orders; $order_id++) {
    $items_in_order = $faker->numberBetween(1, 4);
    $total_items += $items_in_order;
    
    for ($j = 0; $j < $items_in_order; $j++) {
        $product_id = $faker->numberBetween(1, $num_products);
        
        $stmt->execute([
            $order_id,
            $product_id,
            $faker->numberBetween(1, 5),
            // Use the actual base price + a slight random variation to simulate discounts/inflation
            $product_prices[$product_id] * $faker->randomFloat(2, 0.8, 1.0) 
        ]);
    }
}
$pdo->commit();

$end = microtime(true);
$time = round($end - $start, 2);

echo "Database seeded successfully in {$time} seconds!\n";
echo "Total Records Generated:\n";
echo "- Products: $num_products\n";
echo "- Orders: $num_orders\n";
echo "- Order Items: $total_items\n";