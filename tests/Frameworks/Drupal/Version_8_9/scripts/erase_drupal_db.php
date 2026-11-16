<?php

$pdo = new \PDO('mysql:host=mysql-integration', 'test', 'test');

$pdo->query("CREATE DATABASE IF NOT EXISTS drupal89");

$pdo->query('DROP TABLE IF EXISTS drupal89.cache_bootstrap');
$pdo->query('DROP TABLE IF EXISTS drupal89.cache_config');
$pdo->query('DROP TABLE IF EXISTS drupal89.cache_container');
$pdo->query('DROP TABLE IF EXISTS drupal89.cache_data');
$pdo->query('DROP TABLE IF EXISTS drupal89.cache_default');
$pdo->query('DROP TABLE IF EXISTS drupal89.cache_discovery');
$pdo->query('DROP TABLE IF EXISTS drupal89.cache_entity');
$pdo->query('DROP TABLE IF EXISTS drupal89.config');
$pdo->query('DROP TABLE IF EXISTS drupal89.file_managed');
$pdo->query('DROP TABLE IF EXISTS drupal89.file_usage');
$pdo->query('DROP TABLE IF EXISTS drupal89.key_value');
$pdo->query('DROP TABLE IF EXISTS drupal89.key_value_expire');
$pdo->query('DROP TABLE IF EXISTS drupal89.menu_tree');
$pdo->query('DROP TABLE IF EXISTS drupal89.node');
$pdo->query('DROP TABLE IF EXISTS drupal89.node__body');
$pdo->query('DROP TABLE IF EXISTS drupal89.node_access');
$pdo->query('DROP TABLE IF EXISTS drupal89.node_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal89.node_field_revision');
$pdo->query('DROP TABLE IF EXISTS drupal89.node_revision');
$pdo->query('DROP TABLE IF EXISTS drupal89.node_revision__body');
$pdo->query('DROP TABLE IF EXISTS drupal89.path_alias');
$pdo->query('DROP TABLE IF EXISTS drupal89.path_alias_revision');
$pdo->query('DROP TABLE IF EXISTS drupal89.queue');
$pdo->query('DROP TABLE IF EXISTS drupal89.router');
$pdo->query('DROP TABLE IF EXISTS drupal89.semaphore');
$pdo->query('DROP TABLE IF EXISTS drupal89.sequences');
$pdo->query('DROP TABLE IF EXISTS drupal89.sessions');
$pdo->query('DROP TABLE IF EXISTS drupal89.user__roles');
$pdo->query('DROP TABLE IF EXISTS drupal89.users');
$pdo->query('DROP TABLE IF EXISTS drupal89.users_data');
$pdo->query('DROP TABLE IF EXISTS drupal89.users_field_data');
$pdo->query('DROP TABLE IF EXISTS drupal89.watchdog');
