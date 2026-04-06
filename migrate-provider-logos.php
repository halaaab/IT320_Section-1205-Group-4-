<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/back-end/config/database.php';

use Cloudinary\Cloudinary;

$cloudinary = new Cloudinary([
    'cloud' => [
        'cloud_name' => 'dwsafdzwr',
       'api_key'    => '553757457562639',
        'api_secret' => 'yejCp7rI1mCq-4cpw-lBmbyD-iA'
    ],
    'url' => ['secure' => true]
]);

$db = Database::getInstance();
$providers = $db->getCollection('providers');

$allProviders = $providers->find()->toArray();

foreach ($allProviders as $provider) {
    $id = (string)$provider['_id'];
    $logo = $provider['businessLogo'] ?? '';

    if (!$logo) {
        echo "Skipping provider {$id} - no logo\n";
        continue;
    }

    if (str_starts_with($logo, 'https://res.cloudinary.com/')) {
        echo "Skipping provider {$id} - already hosted\n";
        continue;
    }

    $localPath = __DIR__ . '/front-end/shared/' . $logo;

    if (!file_exists($localPath)) {
        $localPath = __DIR__ . '/' . ltrim(str_replace('../', '', $logo), '/');
    }

    if (!file_exists($localPath)) {
        echo "Provider {$id} - file not found: {$logo}\n";
        continue;
    }

    try {
        $result = $cloudinary->uploadApi()->upload($localPath, [
            'folder' => 'replate/logos'
        ]);

        $secureUrl = $result['secure_url'];

        $providers->updateOne(
            ['_id' => $provider['_id']],
            ['$set' => ['businessLogo' => $secureUrl]]
        );

        echo "Updated provider {$id} -> {$secureUrl}\n";
    } catch (Throwable $e) {
        echo "Error for provider {$id}: " . $e->getMessage() . "\n";
    }
}