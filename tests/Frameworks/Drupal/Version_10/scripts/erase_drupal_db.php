<?php

$pdo = new \PDO('mysql:host=mysql_integration', 'test', 'test');

$pdo->query("CREATE DATABASE IF NOT EXISTS drupal10");

$pdo->query('DROP TABLE IF EXISTS drupal10.cache_bootstrap');
$pdo->query('DROP TABLE IF EXISTS drupal10.cache_config');
$pdo->query('DROP TABLE IF EXISTS drupal10.cache_container');  // Drupal doesn't like us dropping this table (PDOException)
$pdo->query('DROP TABLE IF EXISTS drupal10.cache_data');
$pdo->query('DROP TABLE IF EXISTS drupal10.cache_default');
$pdo->query('DROP TABLE IF EXISTS drupal10.cache_discovery');
$pdo->query('DROP TABLE IF EXISTS drupal10.cache_entity');
$pdo->query('DROP TABLE IF EXISTS drupal10.config');
$pdo->query('DROP TABLE IF EXISTS drupal10.file_managed');
$pdo->query('DROP TABLE IF EXISTS drupal10.file_usage');
$pdo->query('DROP TABLE IF EXISTS drupal10.key_value');
$pdo->query('DROP TABLE IF EXISTS drupal10.key_value_expire');
$pdo->query('DROP TABLE IF EXISTS drupal10.menu_tree');
$pdo->query('DROP TABLE IF EXISTS drupal10.node');
$pdo->query('DROP TABLE IF EXISTS drupal10.node__body');
$pdo->query('DROP TABLE IF EXISTS drupal10.node_access');
$pdo->query('DROP TABLE IF EXISTS drupal10.node_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal10.node_field_revision');
$pdo->query('DROP TABLE IF EXISTS drupal10.node_revision');
$pdo->query('DROP TABLE IF EXISTS drupal10.node_revision__body');
$pdo->query('DROP TABLE IF EXISTS drupal10.path_alias');
$pdo->query('DROP TABLE IF EXISTS drupal10.path_alias_revision');
$pdo->query('DROP TABLE IF EXISTS drupal10.queue');
$pdo->query('DROP TABLE IF EXISTS drupal10.router');
$pdo->query('DROP TABLE IF EXISTS drupal10.semaphore');
$pdo->query('DROP TABLE IF EXISTS drupal10.sequences');
$pdo->query('DROP TABLE IF EXISTS drupal10.sessions');
$pdo->query('DROP TABLE IF EXISTS drupal10.user__roles');
$pdo->query('DROP TABLE IF EXISTS drupal10.users');
$pdo->query('DROP TABLE IF EXISTS drupal10.users_data');
$pdo->query('DROP TABLE IF EXISTS drupal10.users_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal10.watchdog');
