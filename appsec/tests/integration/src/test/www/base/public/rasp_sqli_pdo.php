<?php
/**
 * PDO SQL Injection Test
 *
 * This file tests SQL injection via PDO to trigger the Datadog AppSec RASP protection.
 *
 * Usage:
 * /pdo_sqli_test.php?dsn=mysql:host=localhost;dbname=test&username=root&password=root&function=query&user_input=1 OR 1=1
 */

header('Content-Type: text/plain');

if (!isset($_GET['dsn'])) {
    die('Error: Missing DSN parameter\n');
}
$dsn = $_GET['dsn'];
$username = $_GET['username'] ?? 'root';
$password = $_GET['password'] ?? '';
$function = $_GET['function'] ?? 'query';
$user_input = $_GET['user_input'] ?? '1';

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "PDO SQL Injection Test\n";
    echo "Testing function: {$function}\n";
    echo "Using user input: {$user_input}\n\n";

    switch ($function) {
        case 'query':
            $query = "SELECT * FROM users WHERE id = " . $user_input;
            executePdoFunction($pdo, 'query', $query);
            break;
        case 'prepare':
            $query = "SELECT * FROM users WHERE username LIKE '%" . $user_input . "%'";
            executePdoFunction($pdo, 'prepare', $query);
            break;
        case 'exec':
            $query = "UPDATE users SET last_login = NOW() WHERE id = " . $user_input;
            executePdoFunction($pdo, 'exec', $query);
            break;
        default:
            echo "Unknown function: {$function}\n";
    }
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "General Error: " . $e->getMessage() . "\n";
}

echo "\nOK";

/**
 * Execute a PDO function with the given query
 *
 * @param PDO $pdo The PDO instance
 * @param string $function The function to execute
 * @param string $query The SQL query
 */
function executePdoFunction($pdo, $function, $query)
{
    echo "Testing PDO::{$function}\n";
    echo "Query: {$query}\n\n";

    try {
        switch ($function) {
            case 'query':
                $result = $pdo->query($query);
                displayResults($result);
                break;

            case 'prepare':
                $stmt = $pdo->prepare($query);
                $stmt->execute();
                displayResults($stmt);
                break;

            case 'exec':
                $affected_rows = $pdo->exec($query);
                echo "Affected rows: {$affected_rows}\n";
                break;
        }
    } catch (PDOException $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

/**
 * Display results from a PDO query or statement
 *
 * @param PDOStatement $result The PDO result
 */
function displayResults(PDOStatement $result)
{
    echo "Results:\n";

    $rowCount = 0;
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $rowCount++;
        foreach ($row as $key => $value) {
            echo "{$key}: {$value}\n";
        }
        echo "---\n";
    }

    if ($rowCount == 0) {
        echo "No results found\n";
    }
}
