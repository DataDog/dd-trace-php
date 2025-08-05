<?php

$pdo = new \PDO('mysql:host=mysql-integration', 'test', 'test');

$pdo->query("CREATE DATABASE IF NOT EXISTS drupal95");

$pdo->query('DROP TABLE IF EXISTS drupal95.cache_bootstrap');
$pdo->query('DROP TABLE IF EXISTS drupal95.cache_config');
$pdo->query('DROP TABLE IF EXISTS drupal95.cache_container');  // Drupal doesn't like us dropping this table (PDOException)
$pdo->query('DROP TABLE IF EXISTS drupal95.cache_data');
$pdo->query('DROP TABLE IF EXISTS drupal95.cache_default');
$pdo->query('DROP TABLE IF EXISTS drupal95.cache_discovery');
$pdo->query('DROP TABLE IF EXISTS drupal95.cache_entity');
$pdo->query('DROP TABLE IF EXISTS drupal95.config');
$pdo->query('DROP TABLE IF EXISTS drupal95.file_managed');
$pdo->query('DROP TABLE IF EXISTS drupal95.file_usage');
$pdo->query('DROP TABLE IF EXISTS drupal95.key_value');
$pdo->query('DROP TABLE IF EXISTS drupal95.key_value_expire');
$pdo->query('DROP TABLE IF EXISTS drupal95.menu_tree');
$pdo->query('DROP TABLE IF EXISTS drupal95.node');
$pdo->query('DROP TABLE IF EXISTS drupal95.node__body');
$pdo->query('DROP TABLE IF EXISTS drupal95.node_access');
$pdo->query('DROP TABLE IF EXISTS drupal95.node_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal95.node_field_revision');
$pdo->query('DROP TABLE IF EXISTS drupal95.node_revision');
$pdo->query('DROP TABLE IF EXISTS drupal95.node_revision__body');
$pdo->query('DROP TABLE IF EXISTS drupal95.path_alias');
$pdo->query('DROP TABLE IF EXISTS drupal95.path_alias_revision');
$pdo->query('DROP TABLE IF EXISTS drupal95.queue');
$pdo->query('DROP TABLE IF EXISTS drupal95.router');
$pdo->query('DROP TABLE IF EXISTS drupal95.semaphore');
$pdo->query('DROP TABLE IF EXISTS drupal95.sequences');
$pdo->query('DROP TABLE IF EXISTS drupal95.sessions');
$pdo->query('DROP TABLE IF EXISTS drupal95.user__roles');
$pdo->query('DROP TABLE IF EXISTS drupal95.users');
$pdo->query('DROP TABLE IF EXISTS drupal95.users_data');
$pdo->query('DROP TABLE IF EXISTS drupal95.users_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal95.watchdog');
