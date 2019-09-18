<?php

namespace DDTrace\Tests\Unit\Util;

use DDTrace\Util\ContainerInfo;
use PHPUnit\Framework\TestCase;

final class ContainerInfoTest extends TestCase
{
    private $mockCgroupProcFilePath = '/tmp/mock-cgroup-proc-file';

    /**
     * @var ContainerInfo
     */
    private $containerInfo;

    protected function setUp()
    {
        parent::setUp();
        $this->containerInfo = new ContainerInfo($this->mockCgroupProcFilePath);
    }

    public function testNoContainer()
    {
        $this->ensureNoMockCGroupProcFile();
        $this->assertNull($this->containerInfo->getContainerId());
    }

    public function testEmptyFile()
    {
        $this->ensureNoMockCGroupProcFile();
        $this->createCGroupProcFileWithLines([
            // phpcs:disable
            '         ',
            '',
            // phpcs:enable
        ]);
        $this->assertNull($this->containerInfo->getContainerId());
    }

    public function testFileNoReadPermissions()
    {
        $path = '/root/file_no_read_access';
        $this->createCGroupProcFileNoReadAccess([
            // phpcs:disable
            '13:name=systemd:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '12:pids:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            // phpcs:enable
        ], $path);
        $containerInfo = new ContainerInfo($path);
        $this->assertNull($containerInfo->getContainerId());
    }

    public function testLeadingTrailingWhitespaces()
    {
        $this->ensureNoMockCGroupProcFile();
        $this->createCGroupProcFileWithLines([
            // phpcs:disable
            '     13:name=systemd:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860    ',
            '     12:pids:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860    ',
            // phpcs:enable
        ]);
        $this->assertSame(
            '3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            $this->containerInfo->getContainerId()
        );
    }

    public function testRelevantLineCanBeNotTheFirst()
    {
        $this->ensureNoMockCGroupProcFile();
        $this->createCGroupProcFileWithLines([
            // phpcs:disable
            'totally random line',
            '13:name=systemd:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '12:pids:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            // phpcs:enable
        ]);
        $this->assertSame(
            '3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            $this->containerInfo->getContainerId()
        );
    }

    public function testDocker()
    {
        $this->ensureNoMockCGroupProcFile();
        $this->createCGroupProcFileWithLines([
            // phpcs:disable
            '13:name=systemd:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '12:pids:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '11:hugetlb:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '10:net_prio:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '9:perf_event:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '8:net_cls:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '7:freezer:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '6:devices:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '5:memory:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '4:blkio:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '3:cpuacct:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '2:cpu:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            '1:cpuset:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            // phpcs:enable
        ]);
        $this->assertSame(
            '3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860',
            $this->containerInfo->getContainerId()
        );
    }

    public function testK8S()
    {
        $this->ensureNoMockCGroupProcFile();
        $this->createCGroupProcFileWithLines([
            // phpcs:disable
            '11:perf_event:/kubepods/test/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1',
            '10:pids:/kubepods/test/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1',
            '9:memory:/kubepods/test/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1',
            '8:cpu,cpuacct:/kubepods/test/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1',
            '7:blkio:/kubepods/test/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1',
            '6:cpuset:/kubepods/test/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1',
            '5:devices:/kubepods/test/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1',
            '4:freezer:/kubepods/test/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1',
            '3:net_cls,net_prio:/kubepods/test/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1',
            '2:hugetlb:/kubepods/test/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1',
            '1:name=systemd:/kubepods/test/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1',
            // phpcs:enable
        ]);
        $this->assertSame(
            '3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1',
            $this->containerInfo->getContainerId()
        );
    }

    public function testECS()
    {
        $this->ensureNoMockCGroupProcFile();
        $this->createCGroupProcFileWithLines([
            // phpcs:disable
            '9:perf_event:/ecs/test-ecs-classic/5a0d5ceddf6c44c1928d367a815d890f/38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce',
            '8:memory:/ecs/test-ecs-classic/5a0d5ceddf6c44c1928d367a815d890f/38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce',
            '7:hugetlb:/ecs/test-ecs-classic/5a0d5ceddf6c44c1928d367a815d890f/38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce',
            '6:freezer:/ecs/test-ecs-classic/5a0d5ceddf6c44c1928d367a815d890f/38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce',
            '5:devices:/ecs/test-ecs-classic/5a0d5ceddf6c44c1928d367a815d890f/38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce',
            '4:cpuset:/ecs/test-ecs-classic/5a0d5ceddf6c44c1928d367a815d890f/38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce',
            '3:cpuacct:/ecs/test-ecs-classic/5a0d5ceddf6c44c1928d367a815d890f/38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce',
            '2:cpu:/ecs/test-ecs-classic/5a0d5ceddf6c44c1928d367a815d890f/38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce',
            '1:blkio:/ecs/test-ecs-classic/5a0d5ceddf6c44c1928d367a815d890f/38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce',
            // phpcs:enable
        ]);
        $this->assertSame(
            '38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce',
            $this->containerInfo->getContainerId()
        );
    }

    public function testFargate()
    {
        $this->ensureNoMockCGroupProcFile();
        $this->createCGroupProcFileWithLines([
            // phpcs:disable
            '11:hugetlb:/ecs/55091c13-b8cf-4801-b527-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da',
            '10:pids:/ecs/55091c13-b8cf-4801-b527-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da',
            '9:cpuset:/ecs/55091c13-b8cf-4801-b527-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da',
            '8:net_cls,net_prio:/ecs/55091c13-b8cf-4801-b527-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da',
            '7:cpu,cpuacct:/ecs/55091c13-b8cf-4801-b527-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da',
            '6:perf_event:/ecs/55091c13-b8cf-4801-b527-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da',
            '5:freezer:/ecs/55091c13-b8cf-4801-b527-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da',
            '4:devices:/ecs/55091c13-b8cf-4801-b527-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da',
            '3:blkio:/ecs/55091c13-b8cf-4801-b527-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da',
            '2:memory:/ecs/55091c13-b8cf-4801-b527-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da',
            '1:name=systemd:/ecs/55091c13-b8cf-4801-b527-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da',
            // phpcs:enable
        ]);
        $this->assertSame(
            '432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da',
            $this->containerInfo->getContainerId()
        );
    }

    public function testLinuxNonContainerizedFileNotWronglyParsedAsContainer()
    {
        $this->ensureNoMockCGroupProcFile();
        $this->createCGroupProcFileWithLines([
            '11:blkio:/user.slice/user-0.slice/session-14.scope',
            '10:memory:/user.slice/user-0.slice/session-14.scope',
            '9:hugetlb:/',
            '8:cpuset:/',
            '7:pids:/user.slice/user-0.slice/session-14.scope',
            '6:freezer:/',
            '5:net_cls,net_prio:/',
            '4:perf_event:/',
            '3:cpu,cpuacct:/user.slice/user-0.slice/session-14.scope',
            '2:devices:/user.slice/user-0.slice/session-14.scope',
            '1:name=systemd:/user.slice/user-0.slice/session-14.scope',
        ]);
        $this->assertNull($this->containerInfo->getContainerId());
    }

    private function ensureNoMockCGroupProcFile()
    {
        if (file_exists($this->mockCgroupProcFilePath)) {
            unlink($this->mockCgroupProcFilePath);
        }
        $this->assertFalse(file_exists($this->mockCgroupProcFilePath));
    }

    private function createCGroupProcFileWithLines(array $lines)
    {
        file_put_contents($this->mockCgroupProcFilePath, implode(PHP_EOL, $lines));
    }

    private function createCGroupProcFileNoReadAccess(array $lines, $path)
    {
        $asString = implode(PHP_EOL, $lines);
        exec("echo '$asString' | sudo tee -a $path");
    }
}
