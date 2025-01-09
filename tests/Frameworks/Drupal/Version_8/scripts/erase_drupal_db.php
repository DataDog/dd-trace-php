<?php

$pdo = new \PDO('mysql:host=mysql_integration', 'test', 'test');

$pdo->query("CREATE DATABASE IF NOT EXISTS drupal8");

$pdo->query('DROP TABLE IF EXISTS drupal8.cache_bootstrap');
$pdo->query('DROP TABLE IF EXISTS drupal8.cache_config');
$pdo->query('DROP TABLE IF EXISTS drupal8.cache_container');  // Drupal doesn't like us dropping this table (PDOException)
$pdo->query('DROP TABLE IF EXISTS drupal8.cache_data');
$pdo->query('DROP TABLE IF EXISTS drupal8.cache_default');
$pdo->query('DROP TABLE IF EXISTS drupal8.cache_discovery');
$pdo->query('DROP TABLE IF EXISTS drupal8.cache_entity');
$pdo->query('DROP TABLE IF EXISTS drupal8.config');
$pdo->query('DROP TABLE IF EXISTS drupal8.file_managed');
$pdo->query('DROP TABLE IF EXISTS drupal8.file_usage');
$pdo->query('DROP TABLE IF EXISTS drupal8.key_value');
$pdo->query('DROP TABLE IF EXISTS drupal8.key_value_expire');
$pdo->query('DROP TABLE IF EXISTS drupal8.menu_tree');
$pdo->query('DROP TABLE IF EXISTS drupal8.node');
$pdo->query('DROP TABLE IF EXISTS drupal8.node__body');
$pdo->query('DROP TABLE IF EXISTS drupal8.node_access');
$pdo->query('DROP TABLE IF EXISTS drupal8.node_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal8.node_field_revision');
$pdo->query('DROP TABLE IF EXISTS drupal8.node_revision');
$pdo->query('DROP TABLE IF EXISTS drupal8.node_revision__body');
$pdo->query('DROP TABLE IF EXISTS drupal8.path_alias');
$pdo->query('DROP TABLE IF EXISTS drupal8.path_alias_revision');
$pdo->query('DROP TABLE IF EXISTS drupal8.queue');
$pdo->query('DROP TABLE IF EXISTS drupal8.router');
$pdo->query('DROP TABLE IF EXISTS drupal8.semaphore');
$pdo->query('DROP TABLE IF EXISTS drupal8.sequences');
$pdo->query('DROP TABLE IF EXISTS drupal8.sessions');
$pdo->query('DROP TABLE IF EXISTS drupal8.user__roles');
$pdo->query('DROP TABLE IF EXISTS drupal8.users');
$pdo->query('DROP TABLE IF EXISTS drupal8.users_data');
$pdo->query('DROP TABLE IF EXISTS drupal8.users_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal8.watchdog');
