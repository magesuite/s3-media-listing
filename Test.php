<?php

require '../../../vendor/autoload.php';

$profile = 'default';
$path = '/home/magento2/.aws/credentials';

$provider = \Aws\Credentials\CredentialProvider::ini($profile, $path);
$provider = \Aws\Credentials\CredentialProvider::memoize($provider);

$s3Client = new \Aws\S3\S3Client([
    'version' => 'latest',
    'region' => 'eu-central-1',
    'credentials' => $provider
]);

$results = $s3Client->getPaginator('ListObjects', [
    'Bucket' => 'cs-magesuite-dev-media',
    'Prefix' => 'wysiwyg/'
]);

foreach ($results as $result) {
    foreach ($result['Contents'] as $object) {
        echo $object['Key'] . PHP_EOL;
    }

    if(!isset($result['CommonPrefixes']) or empty($result['CommonPrefixes'])) {
        continue;
    }

    foreach($result['CommonPrefixes'] as $prefix) {
        echo $prefix['Prefix'].PHP_EOL;
    }
}


