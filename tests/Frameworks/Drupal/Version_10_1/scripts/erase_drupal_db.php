<?php

$pdo = new \PDO('mysql:host=mysql_integration;dbname=drupal101', 'test', 'test');

$pdo->query('DROP TABLE IF EXISTS cache_bootstrap');
$pdo->query('DROP TABLE IF EXISTS cache_config');
$pdo->query('DROP TABLE IF EXISTS cache_container');  // Drupal doesn't like us dropping this table (PDOException)
$pdo->query('DROP TABLE IF EXISTS cache_data');
$pdo->query('DROP TABLE IF EXISTS cache_default');
$pdo->query('DROP TABLE IF EXISTS cache_discovery');
$pdo->query('DROP TABLE IF EXISTS cache_entity');
$pdo->query('DROP TABLE IF EXISTS config');
$pdo->query('DROP TABLE IF EXISTS file_managed');
$pdo->query('DROP TABLE IF EXISTS file_usage');
$pdo->query('DROP TABLE IF EXISTS key_value');
$pdo->query('DROP TABLE IF EXISTS key_value_expire');
$pdo->query('DROP TABLE IF EXISTS menu_tree');
$pdo->query('DROP TABLE IF EXISTS node');
$pdo->query('DROP TABLE IF EXISTS node__body');
$pdo->query('DROP TABLE IF EXISTS node_access');
$pdo->query('DROP TABLE IF EXISTS node_field_data');
$pdo->query('DROP TABLE IF EXISTS node_field_revision');
$pdo->query('DROP TABLE IF EXISTS node_revision');
$pdo->query('DROP TABLE IF EXISTS node_revision__body');
$pdo->query('DROP TABLE IF EXISTS path_alias');
$pdo->query('DROP TABLE IF EXISTS path_alias_revision');
$pdo->query('DROP TABLE IF EXISTS queue');
$pdo->query('DROP TABLE IF EXISTS router');
$pdo->query('DROP TABLE IF EXISTS semaphore');
$pdo->query('DROP TABLE IF EXISTS sequences');
$pdo->query('DROP TABLE IF EXISTS sessions');
$pdo->query('DROP TABLE IF EXISTS user__roles');
$pdo->query('DROP TABLE IF EXISTS users');
$pdo->query('DROP TABLE IF EXISTS users_data');
$pdo->query('DROP TABLE IF EXISTS users_field_data');
$pdo->query('DROP TABLE IF EXISTS watchdog');
