<?php return array (
  'debug' => true,
  'database' => 
  array (
    'driver' => 'sqlite',
    'database' => 'flarum_test.sqlite',
    'prefix' => '',
    'prefix_indexes' => true,
    'foreign_key_constraints' => true,
  ),
  'url' => 'http://localhost',
  'paths' => 
  array (
    'api' => 'api',
    'admin' => 'admin',
  ),
  'headers' => 
  array (
    'poweredByHeader' => true,
    'referrerPolicy' => 'same-origin',
  ),
  'queue' => 
  array (
    'driver' => 'sync',
  ),
);