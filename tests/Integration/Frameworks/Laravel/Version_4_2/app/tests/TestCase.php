<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;


class TestCase extends BaseTestCase {

	/**
	 * Creates the application.
	 *
	 * @return \Symfony\Component\HttpKernel\HttpKernelInterface
	 */
	public function createApplication()
	{
		$testEnvironment = 'dd_testing';

		return require __DIR__.'/../../bootstrap/start.php';
	}
}
