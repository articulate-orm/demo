<?php

namespace App\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;

class FeatureCommandsTest extends TestCase
{
    private static bool $envLoaded = false;

    protected function setUp(): void
    {
        if (!self::$envLoaded) {
            (new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runCommand(string $commandName, array $arguments = []): array
    {
        $kernel = new \App\Kernel($_ENV['APP_ENV'] ?? 'test', (bool) ($_ENV['APP_DEBUG'] ?? false));
        $kernel->boot();
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput(['command' => $commandName] + $arguments);
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_VERBOSE);
        $exitCode = $application->run($input, $output);

        return [$exitCode, $output->fetch()];
    }

    public function testCatalogCrudCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:catalog:crud');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testCatalogQueryCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:catalog:query');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testCustomersLifecycleCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:customers:lifecycle');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testCustomersBrowseCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:customers:browse');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testCustomersSoftDeleteCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:customers:soft-delete');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testCustomersCrossEntityCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:customers:cross-entity');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testCustomersOneUpdateCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:customers:one-update');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testOrdersPlaceCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:orders:place');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testOrdersQueryCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:orders:query');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testOrdersDeadlockCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:orders:deadlock');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testTaggingDemoCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:tagging:demo');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testAnalyticsReportCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:analytics:report');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testAnalyticsBatchCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:analytics:batch');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testBillingOptimisticLockCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:billing:optimistic-lock');
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }

    public function testBulkImportRunCommandRunsSuccessfully(): void
    {
        [$exitCode, $content] = $this->runCommand('app:import:run', [
            '--count' => 40,
            '--batch-size' => 10,
        ]);
        $this->assertSame(0, $exitCode, $content ?: 'Command produced no output');
    }
}
