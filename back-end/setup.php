<?php
// setup.php
// ─────────────────────────────────────────────────────
// RePlate — One-time MongoDB setup script
// Run once after connecting to Atlas:  php setup.php
// ─────────────────────────────────────────────────────

require_once __DIR__ . '/models/Customer.php';
require_once __DIR__ . '/models/Provider.php';
require_once __DIR__ . '/models/Category.php';
require_once __DIR__ . '/models/PickupLocation.php';
require_once __DIR__ . '/models/Item.php';
require_once __DIR__ . '/models/Favourite.php';
require_once __DIR__ . '/models/Cart.php';
require_once __DIR__ . '/models/Order.php';
require_once __DIR__ . '/models/OrderItem.php';
require_once __DIR__ . '/models/Notification.php';
require_once __DIR__ . '/models/SupportTicket.php';

echo "🍱 RePlate — MongoDB Setup\n";
echo "──────────────────────────\n\n";

// 1. Create indexes on all collections
$models = [
    'Customer'       => new Customer(),
    'Provider'       => new Provider(),
    'Category'       => new Category(),
    'PickupLocation' => new PickupLocation(),
    'Item'           => new Item(),
    'Favourite'      => new Favourite(),
    'Cart'           => new Cart(),
    'Order'          => new Order(),
    'OrderItem'      => new OrderItem(),
    'Notification'   => new Notification(),
    'SupportTicket'  => new SupportTicket(),
];

foreach ($models as $name => $model) {
    $model->createIndexes();
    echo "✅ Indexes created: $name\n";
}

// 2. Seed default categories
(new Category())->seed();
echo "\n✅ Categories seeded: Bakery, Groceries, Meals, Dairy, Sweets\n";

echo "\n──────────────────────────\n";
echo "✅ Setup complete!\n";
echo "Collections created: " . count($models) . "\n";
