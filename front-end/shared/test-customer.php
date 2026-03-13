<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__, 2) . '/back-end/config/database.php';
require_once dirname(__DIR__, 2) . '/back-end/models/BaseModel.php';
require_once dirname(__DIR__, 2) . '/back-end/models/Customer.php';

try {
    $customer = new Customer();

    $id = $customer->create([
        'fullName'    => 'Test User',
        'email'       => 'testuser123@example.com',
        'password'    => 'password123',
        'phoneNumber' => ''
    ]);

    echo "Inserted successfully. ID: " . $id;
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage();
}