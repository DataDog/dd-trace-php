<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\DB\Adapter;

use Magento\Framework\App\ResourceConnection;

/**
 * Class GetDbVersion provides sql engine version requesting version variable
 *
 * Rather then depending on this class, please implement this logic in your extension
 */
class SqlVersionProvider
{
    /**#@+
     * Database version specific templates
     */
    public const MYSQL_8_0_VERSION = '8.0.';

    public const MYSQL_5_7_VERSION = '5.7.';

    public const MARIA_DB_10_VERSION = '10.';

    public const MARIA_DB_10_4_VERSION = '10.4.';

    public const MARIA_DB_10_6_VERSION = '10.6.';

    public const MYSQL_8_0_29_VERSION = '8.0.29';

    public const MARIA_DB_10_6_11_VERSION = '10.6.11';

    public const MARIA_DB_10_4_27_VERSION = '10.4.27';

    /**#@-*/

    /**
     * Database version variable name
     */
    private const VERSION_VAR_NAME = 'version';

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var string
     */
    private $version;

    /**
     * @var array
     */
    private $supportedVersionPatterns;

    /**
     * @param ResourceConnection $resourceConnection
     * @param array $supportedVersionPatterns
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        array $supportedVersionPatterns = []
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->supportedVersionPatterns = $supportedVersionPatterns;
    }

    /**
     * Provides SQL engine version (MariaDB, MySQL-8, MySQL-5.7)
     *
     * @param string $resource
     *
     * @return string
     * @throws ConnectionException
     */
    public function getSqlVersion(string $resource = ResourceConnection::DEFAULT_CONNECTION): string
    {
        if (!$this->version) {
            $this->version = $this->getVersionString($resource);
        }

        return $this->version;
    }

    /**
     * Provides Sql Engine Version string
     *
     * @param string $resource
     *
     * @return string
     * @throws ConnectionException
     */
    private function getVersionString(string $resource): string
    {
        $pattern = sprintf('/(%s)/', implode('|', $this->supportedVersionPatterns));
        $sqlVersionOutput = $this->fetchSqlVersion($resource);
        preg_match($pattern, $sqlVersionOutput, $match);
        if (empty($match)) {
            throw new ConnectionException(
                sprintf(
                    "Current version of RDBMS is not supported. Used Version: %s. Supported versions: %s",
                    $sqlVersionOutput,
                    implode(', ', array_keys($this->supportedVersionPatterns))
                )
            );
        }

        return reset($match);
    }

    /**
     * Fetch version from sql engine
     *
     * @param string $resource
     *
     * @return string
     */
    private function fetchSqlVersion(string $resource): string
    {
        $versionOutput = $this->resourceConnection->getConnection($resource)
            ->fetchPairs(sprintf('SHOW variables LIKE "%s"', self::VERSION_VAR_NAME));

        return $versionOutput[self::VERSION_VAR_NAME];
    }

    /**
     * Check if MySQL version is greater than equal to 8.0.29
     *
     * @return bool
     * @throws ConnectionException
     */
    public function isMysqlGte8029(): bool
    {
        $sqlVersion = $this->getSqlVersion();
        $isMariaDB = str_contains($sqlVersion, SqlVersionProvider::MARIA_DB_10_VERSION);
        $sqlExactVersion = $this->fetchSqlVersion(ResourceConnection::DEFAULT_CONNECTION);
        if (!$isMariaDB && version_compare($sqlExactVersion, '8.0.29', '>=')) {
            return true;
        }
        return false;
    }

    /**
     * Check if MariaDB version is greater than equal to 10.6.11
     *
     * @return bool
     * @throws ConnectionException
     */
    public function isMariaDBGte10611(): bool
    {
        $sqlVersion = $this->getSqlVersion();
        $isMariaDB106 = str_contains($sqlVersion, SqlVersionProvider::MARIA_DB_10_6_VERSION);
        $sqlExactVersion = $this->fetchSqlVersion(ResourceConnection::DEFAULT_CONNECTION);
        if ($isMariaDB106 && version_compare($sqlExactVersion, '10.6.11', '>=')) {
            return true;
        }
        return false;
    }

    /**
     * Check if MariaDB version is greater than equal to 10.4.27
     *
     * @return bool
     * @throws ConnectionException
     */
    public function isMariaDBGte10427(): bool
    {
        $sqlVersion = $this->getSqlVersion();
        $isMariaDB104 = str_contains($sqlVersion, SqlVersionProvider::MARIA_DB_10_4_VERSION);
        $sqlExactVersion = $this->fetchSqlVersion(ResourceConnection::DEFAULT_CONNECTION);
        if ($isMariaDB104 && version_compare($sqlExactVersion, '10.4.27', '>=')) {
            return true;
        }
        return false;
    }
}
