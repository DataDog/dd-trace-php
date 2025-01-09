<?php

$pdo = new \PDO('mysql:host=mysql_integration', 'test', 'test');

$pdo->query("CREATE DATABASE IF NOT EXISTS drupal11");

$pdo->query('DROP TABLE IF EXISTS drupal11.cache_bootstrap');
$pdo->query('DROP TABLE IF EXISTS drupal11.cache_config');
$pdo->query('DROP TABLE IF EXISTS drupal11.cache_container');  // Drupal doesn't like us dropping this table (PDOException)
$pdo->query('DROP TABLE IF EXISTS drupal11.cache_data');
$pdo->query('DROP TABLE IF EXISTS drupal11.cache_default');
$pdo->query('DROP TABLE IF EXISTS drupal11.cache_discovery');
$pdo->query('DROP TABLE IF EXISTS drupal11.cache_entity');
$pdo->query('DROP TABLE IF EXISTS drupal11.config');
$pdo->query('DROP TABLE IF EXISTS drupal11.file_managed');
$pdo->query('DROP TABLE IF EXISTS drupal11.file_usage');
$pdo->query('DROP TABLE IF EXISTS drupal11.key_value');
$pdo->query('DROP TABLE IF EXISTS drupal11.key_value_expire');
$pdo->query('DROP TABLE IF EXISTS drupal11.menu_tree');
$pdo->query('DROP TABLE IF EXISTS drupal11.node');
$pdo->query('DROP TABLE IF EXISTS drupal11.node__body');
$pdo->query('DROP TABLE IF EXISTS drupal11.node_access');
$pdo->query('DROP TABLE IF EXISTS drupal11.node_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal11.node_field_revision');
$pdo->query('DROP TABLE IF EXISTS drupal11.node_revision');
$pdo->query('DROP TABLE IF EXISTS drupal11.node_revision__body');
$pdo->query('DROP TABLE IF EXISTS drupal11.path_alias');
$pdo->query('DROP TABLE IF EXISTS drupal11.path_alias_revision');
$pdo->query('DROP TABLE IF EXISTS drupal11.queue');
$pdo->query('DROP TABLE IF EXISTS drupal11.router');
$pdo->query('DROP TABLE IF EXISTS drupal11.semaphore');
$pdo->query('DROP TABLE IF EXISTS drupal11.sequences');
$pdo->query('DROP TABLE IF EXISTS drupal11.sessions');
$pdo->query('DROP TABLE IF EXISTS drupal11.user__roles');
$pdo->query('DROP TABLE IF EXISTS drupal11.users');
$pdo->query('DROP TABLE IF EXISTS drupal11.users_data');
$pdo->query('DROP TABLE IF EXISTS drupal11.users_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal11.watchdog');
