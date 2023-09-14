<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework;

/**
 * Shell command line wrapper encapsulates command execution and arguments escaping
 *
 * @api
 * @since 100.0.2
 */
interface ShellInterface
{
    /**
     * Execute a command through the command line, passing properly escaped arguments
     *
     * @param string $command Command with optional argument markers '%s'
     * @param string[] $arguments Argument values to substitute markers with
     * @throws \Magento\Framework\Exception\LocalizedException If a command returns non-zero exit code
     * @return string
     */
    public function execute($command, array $arguments = []);
}
