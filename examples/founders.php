<?php

require '../vendor/autoload.php';

use HereLeads\Client;

$client = new Client('YOUR_API_KEY');

$results = $client->search([
    'title' => 'Founder',
    'country' => 'United States',
    'limit' => 20
]);

print_r($results);
