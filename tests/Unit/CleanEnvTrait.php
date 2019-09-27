<?php

namespace DDTrace\Tests\Unit;

/**
 * This trait provides to work in a clean environment for specific env variables. Env variables provided in the method
 * `getCleanEnvs()` are cleaned during setup and restored to their original value during tearDown.
 */
trait CleanEnvTrait
{
    /**
     * @var array
     */
    private $envsValuesBeforeTest;

    /**
     * @return string[] The array of envs that will be cleared before and restore to the original value after test.
     */
    protected function getCleanEnvs()
    {
        return [];
    }

    /** @inheritdoc */
    protected function setUp()
    {
        parent::setUp();

        // Cleaning up envs that MUST be null
        foreach ($this->getCleanEnvs() as $env) {
            $this->envsValuesBeforeTest[$env] = getenv($env);
            putenv($env);
        }
    }

    /** @inheritdoc */
    protected function tearDown()
    {
        // Restoring envs to their previous value
        foreach ($this->getCleanEnvs() as $env) {
            $originalValue = $this->envsValuesBeforeTest[$env];
            if ($originalValue === false) {
                putenv($env);
            } else {
                putenv($env . '=' . $originalValue);
            }
        }

        parent::tearDown();
    }
}
