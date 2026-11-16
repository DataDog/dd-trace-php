<?php

$pdo = new \PDO('mysql:host=mysql-integration', 'test', 'test');

$pdo->query("CREATE DATABASE IF NOT EXISTS drupal101");

$pdo->query('DROP TABLE IF EXISTS drupal101.cache_bootstrap');
$pdo->query('DROP TABLE IF EXISTS drupal101.cache_config');
$pdo->query('DROP TABLE IF EXISTS drupal101.cache_container');  // Drupal doesn't like us dropping this table (PDOException)
$pdo->query('DROP TABLE IF EXISTS drupal101.cache_data');
$pdo->query('DROP TABLE IF EXISTS drupal101.cache_default');
$pdo->query('DROP TABLE IF EXISTS drupal101.cache_discovery');
$pdo->query('DROP TABLE IF EXISTS drupal101.cache_entity');
$pdo->query('DROP TABLE IF EXISTS drupal101.config');
$pdo->query('DROP TABLE IF EXISTS drupal101.file_managed');
$pdo->query('DROP TABLE IF EXISTS drupal101.file_usage');
$pdo->query('DROP TABLE IF EXISTS drupal101.key_value');
$pdo->query('DROP TABLE IF EXISTS drupal101.key_value_expire');
$pdo->query('DROP TABLE IF EXISTS drupal101.menu_tree');
$pdo->query('DROP TABLE IF EXISTS drupal101.node');
$pdo->query('DROP TABLE IF EXISTS drupal101.node__body');
$pdo->query('DROP TABLE IF EXISTS drupal101.node_access');
$pdo->query('DROP TABLE IF EXISTS drupal101.node_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal101.node_field_revision');
$pdo->query('DROP TABLE IF EXISTS drupal101.node_revision');
$pdo->query('DROP TABLE IF EXISTS drupal101.node_revision__body');
$pdo->query('DROP TABLE IF EXISTS drupal101.path_alias');
$pdo->query('DROP TABLE IF EXISTS drupal101.path_alias_revision');
$pdo->query('DROP TABLE IF EXISTS drupal101.queue');
$pdo->query('DROP TABLE IF EXISTS drupal101.router');
$pdo->query('DROP TABLE IF EXISTS drupal101.semaphore');
$pdo->query('DROP TABLE IF EXISTS drupal101.sequences');
$pdo->query('DROP TABLE IF EXISTS drupal101.sessions');
$pdo->query('DROP TABLE IF EXISTS drupal101.user__roles');
$pdo->query('DROP TABLE IF EXISTS drupal101.users');
$pdo->query('DROP TABLE IF EXISTS drupal101.users_data');
$pdo->query('DROP TABLE IF EXISTS drupal101.users_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal101.watchdog');
