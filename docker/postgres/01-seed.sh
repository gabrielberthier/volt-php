#!/usr/bin/env bash
# Builds and seeds the analytics schema with set-based SQL (seconds, not minutes).
# Same shape as examples/seed.php; sizes come from SEED_* env vars.
set -euo pipefail

psql -v ON_ERROR_STOP=1 \
    --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -v products="${SEED_PRODUCTS:-5000}" \
    -v orders="${SEED_ORDERS:-300000}" <<'SQL'
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

\echo Seeding :products products
INSERT INTO products (name, category, price)
SELECT
    'Product ' || g,
    (ARRAY['Electronics','Home & Kitchen','Apparel','Books','Sports','Toys'])[1 + floor(random() * 6)::int],
    round((5 + random() * 995)::numeric, 2)
FROM generate_series(1, :products) AS g;

\echo Seeding :orders orders
INSERT INTO orders (customer_id, region, order_date)
SELECT
    1 + floor(random() * 3000)::int,
    (ARRAY['North America','Europe','Asia'])[1 + floor(random() * 3)::int],
    NOW() - random() * INTERVAL '2 years'
FROM generate_series(1, :orders);

\echo Seeding order items (1 to 4 per order)
INSERT INTO order_items (order_id, product_id, quantity, unit_price)
SELECT x.order_id, p.id, x.quantity, round(p.price * (0.8 + random() * 0.2)::numeric, 2)
FROM (
    SELECT
        o.id AS order_id,
        1 + floor(random() * :products)::int AS product_id,
        1 + floor(random() * 5)::int AS quantity
    FROM orders o
    CROSS JOIN LATERAL generate_series(1, 1 + (o.id % 4)) AS n
) x
JOIN products p ON p.id = x.product_id;

CREATE INDEX orders_customer_idx ON orders (customer_id);
CREATE INDEX orders_region_date_idx ON orders (region, order_date);
CREATE INDEX order_items_order_idx ON order_items (order_id);
CREATE INDEX order_items_product_idx ON order_items (product_id);

ANALYZE;

SELECT
    (SELECT count(*) FROM products) AS products,
    (SELECT count(*) FROM orders) AS orders,
    (SELECT count(*) FROM order_items) AS order_items;
SQL
