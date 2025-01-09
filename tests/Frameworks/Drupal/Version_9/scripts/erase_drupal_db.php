<?php

$pdo = new \PDO('mysql:host=mysql_integration', 'test', 'test');

$pdo->query("CREATE DATABASE IF NOT EXISTS drupal9");

$pdo->query('DROP TABLE IF EXISTS drupal9.cache_bootstrap');
$pdo->query('DROP TABLE IF EXISTS drupal9.cache_config');
$pdo->query('DROP TABLE IF EXISTS drupal9.cache_container');  // Drupal doesn't like us dropping this table (PDOException)
$pdo->query('DROP TABLE IF EXISTS drupal9.cache_data');
$pdo->query('DROP TABLE IF EXISTS drupal9.cache_default');
$pdo->query('DROP TABLE IF EXISTS drupal9.cache_discovery');
$pdo->query('DROP TABLE IF EXISTS drupal9.cache_entity');
$pdo->query('DROP TABLE IF EXISTS drupal9.config');
$pdo->query('DROP TABLE IF EXISTS drupal9.file_managed');
$pdo->query('DROP TABLE IF EXISTS drupal9.file_usage');
$pdo->query('DROP TABLE IF EXISTS drupal9.key_value');
$pdo->query('DROP TABLE IF EXISTS drupal9.key_value_expire');
$pdo->query('DROP TABLE IF EXISTS drupal9.menu_tree');
$pdo->query('DROP TABLE IF EXISTS drupal9.node');
$pdo->query('DROP TABLE IF EXISTS drupal9.node__body');
$pdo->query('DROP TABLE IF EXISTS drupal9.node_access');
$pdo->query('DROP TABLE IF EXISTS drupal9.node_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal9.node_field_revision');
$pdo->query('DROP TABLE IF EXISTS drupal9.node_revision');
$pdo->query('DROP TABLE IF EXISTS drupal9.node_revision__body');
$pdo->query('DROP TABLE IF EXISTS drupal9.path_alias');
$pdo->query('DROP TABLE IF EXISTS drupal9.path_alias_revision');
$pdo->query('DROP TABLE IF EXISTS drupal9.queue');
$pdo->query('DROP TABLE IF EXISTS drupal9.router');
$pdo->query('DROP TABLE IF EXISTS drupal9.semaphore');
$pdo->query('DROP TABLE IF EXISTS drupal9.sequences');
$pdo->query('DROP TABLE IF EXISTS drupal9.sessions');
$pdo->query('DROP TABLE IF EXISTS drupal9.user__roles');
$pdo->query('DROP TABLE IF EXISTS drupal9.users');
$pdo->query('DROP TABLE IF EXISTS drupal9.users_data');
$pdo->query('DROP TABLE IF EXISTS drupal9.users_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal9.watchdog');
