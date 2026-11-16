<?php
/**
 * MySQLi SQL Injection Test
 *
 * This file tests SQL injection via MySQLi to trigger the Datadog AppSec RASP protection.
 *
 * Usage:
 * /mysqli_sqli_test.php?host=localhost&dbname=test&username=root&password=root&function=query&user_input=1 OR 1=1
 */

// Set content type to plain text
header('Content-Type: text/plain');

// Get connection parameters from GET request
$host = $_GET['host'] ?? 'localhost';
$dbname = $_GET['dbname'] ?? 'test';
$username = $_GET['username'] ?? 'root';
$password = $_GET['password'] ?? '';
$function = $_GET['function'] ?? 'query';
$user_input = $_GET['user_input'] ?? '1';

try {
    $mysqli = new mysqli($host, $username, $password, $dbname);

    if ($mysqli->connect_errno) {
        throw new Exception("Failed to connect to MySQL: " . $mysqli->connect_error);
    }

    echo "MySQLi SQL Injection Test\n";
    echo "Testing function: {$function}\n";
    echo "Using user input: {$user_input}\n\n";

    $query = "SELECT * FROM users WHERE id = " . $user_input;

    if ($function == 'prepare') {
        $query = "SELECT * FROM users WHERE username LIKE '%" . $user_input . "%'";
    } elseif ($function == 'multi_query') {
        $query = "SELECT * FROM users WHERE id = " . $user_input . "; SELECT NOW();";
    }

    executeFunction($mysqli, $function, $query);

    $mysqli->close();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\nOK";

/**
 * Execute a MySQLi function with the given query
 *
 * @param mysqli $mysqli The MySQLi instance
 * @param string $function The function to execute
 * @param string $query The SQL query
 */
function executeFunction($mysqli, $function, $query) {
    echo "Testing mysqli::{$function}\n";
    echo "Query: {$query}\n\n";

    try {
        switch ($function) {
            case 'query':
                $result = $mysqli->query($query);
                if ($result === false) {
                    throw new Exception("Error executing query: " . $mysqli->error);
                }
                displayResults($result);
                $result->free();
                break;

            case 'real_query':
                $success = $mysqli->real_query($query);
                if ($success === false) {
                    throw new Exception("Error executing real_query: " . $mysqli->error);
                }
                $result = $mysqli->store_result();
                displayResults($result);
                if ($result) {
                    $result->free();
                }
                break;

            case 'prepare':
                $stmt = $mysqli->prepare($query);
                if ($stmt === false) {
                    throw new Exception("Error preparing statement: " . $mysqli->error);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                displayResults($result);
                $stmt->close();
                break;

            case 'multi_query':
                $success = $mysqli->multi_query($query);
                if ($success === false) {
                    throw new Exception("Error executing multi_query: " . $mysqli->error);
                }

                do {
                    $result = $mysqli->store_result();
                    if ($result) {
                        echo "Result set:\n";
                        displayResults($result);
                        $result->free();
                    }

                    if ($mysqli->more_results()) {
                        echo "Next result set:\n";
                    }
                } while ($mysqli->next_result());
                break;

            case 'procedural':
                $result = mysqli_query($mysqli, $query);
                if ($result === false) {
                    throw new Exception("Error executing mysqli_query: " . mysqli_error($mysqli));
                }
                displayResults($result);
                mysqli_free_result($result);
                break;

            case 'execute_query':
                $result = $mysqli->execute_query($query);
                if ($result === false) {
                    throw new Exception("Error executing execute_query: " . $mysqli->error);
                }
                displayResults($result);
                $result->free();
                break;
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

/**
 * Display results from a MySQLi result
 *
 * @param mysqli_result $result The MySQLi result
 */
function displayResults($result) {
    if (!$result) {
        echo "No results available\n";
        return;
    }

    echo "Results:\n";

    $rowCount = 0;
    while ($row = $result->fetch_assoc()) {
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
