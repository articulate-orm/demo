<?php

namespace App\Features\Billing\Command;

use App\Features\Billing\Entity\Invoice;
use App\Features\Billing\Entity\InvoiceTitleEdit;
use Articulate\Connection;
use Articulate\Exceptions\OptimisticLockException;
use Articulate\Modules\EntityManager\EntityManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Demonstrates Articulate's optimistic locking end to end:
 *   1. insert + happy-path update bumps and checks the #[Version] column,
 *   2. a concurrent writer causes a stale update to throw OptimisticLockException,
 *   3. the failed flush does not poison in-memory state — re-find() and retry succeeds,
 *   4. the #[VersionAware] sibling bumps the shared version without checking it,
 *      keeping a full-model writer's lost-update detection honest.
 */
#[AsCommand(name: 'app:billing:optimistic-lock', description: 'Optimistic locking (#[Version] / #[VersionAware]) demo')]
final class BillingOptimisticLockCommand extends Command
{
    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $suffix = bin2hex(random_bytes(4));

        $invoiceId = $this->demonstrateHappyPath($io, $suffix);
        $this->demonstrateConflictAndRecovery($io, $invoiceId);
        $this->demonstrateVersionAwareSibling($io, $invoiceId);

        $io->success('Optimistic locking demo completed');

        return Command::SUCCESS;
    }

    /**
     * Step 1 + 2: insert (version starts at 0) then a happy-path update that
     * bumps the row to version 1 and guards the write with WHERE version = 0.
     */
    private function demonstrateHappyPath(SymfonyStyle $io, string $suffix): int
    {
        $invoice = new Invoice();
        $invoice->number = "INV-{$suffix}";
        $invoice->title = 'Initial invoice';
        $invoice->amount = 100.0;
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        if ($invoice->id === null) {
            throw new \RuntimeException('Invoice id was not assigned by flush().');
        }

        $versionAfterInsert = $invoice->version;

        $invoice->amount = 150.0;
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        $io->section('1. Insert and happy-path update');
        $io->definitionList(
            ['version after INSERT' => (string) $versionAfterInsert],
            ['version after happy-path UPDATE' => (string) $invoice->version],
            ['row version in database' => (string) $this->readVersion($invoice->id)],
            ['note' => 'the UPDATE carried WHERE version = 0 and bumped the row to 1'],
        );

        return $invoice->id;
    }

    /**
     * Step 3 + 4: a concurrent writer (second EntityManager) moves the row on,
     * so the first context's stale flush throws OptimisticLockException. The
     * failed flush leaves in-memory state intact — re-find() and retry succeeds.
     */
    private function demonstrateConflictAndRecovery(SymfonyStyle $io, int $invoiceId): void
    {
        $invoice = $this->entityManager->find(Invoice::class, $invoiceId);
        if (!$invoice instanceof Invoice) {
            throw new \RuntimeException('Invoice was not found in the primary context.');
        }

        $versionBeforeConflict = $invoice->version;

        // A concurrent writer in a separate EntityManager loads the same row,
        // mutates it, and commits first — moving the version forward.
        $concurrentEm = $this->createConcurrentEntityManager();
        $concurrent = $concurrentEm->find(Invoice::class, $invoiceId);
        if (!$concurrent instanceof Invoice) {
            throw new \RuntimeException('Invoice was not found in the concurrent context.');
        }
        $concurrent->status = 'sent';
        $concurrentEm->persist($concurrent);
        $concurrentEm->flush();

        // Back in the first context the object still holds the stale version, so
        // its UPDATE guard (WHERE version = <stale>) matches zero rows.
        $invoice->amount = 175.0;
        $this->entityManager->persist($invoice);

        $conflict = null;
        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException $e) {
            $conflict = $e;
        }

        if ($conflict === null) {
            throw new \RuntimeException('Expected an OptimisticLockException from the stale flush.');
        }

        $io->section('2. Concurrent writer causes a stale-version conflict');
        $io->definitionList(
            ['first context version (stale)' => (string) $versionBeforeConflict],
            ['concurrent writer moved row to' => (string) $this->readVersion($invoiceId)],
            ['stale flush result' => OptimisticLockException::class],
            ['in-memory version after failed flush' => (string) $invoice->version],
            ['note' => 'failed flush did not poison state — version was not bumped past its pre-flush value'],
        );

        // Recovery: no "EM is closed" state to reset. Re-find the current row and
        // flush again against the up-to-date version.
        $this->entityManager->clear();
        $recovered = $this->entityManager->find(Invoice::class, $invoiceId);
        if (!$recovered instanceof Invoice) {
            throw new \RuntimeException('Invoice was not found while recovering.');
        }
        $versionBeforeRetry = $recovered->version;
        $recovered->amount = 200.0;
        $this->entityManager->persist($recovered);
        $this->entityManager->flush();

        $io->section('3. Recovery after the conflict');
        $io->definitionList(
            ['re-find() version' => (string) $versionBeforeRetry],
            ['version after successful retry' => (string) $recovered->version],
            ['row version in database' => (string) $this->readVersion($invoiceId)],
        );
    }

    /**
     * Step 5: edit through the #[VersionAware] sibling. It bumps the shared
     * `version` column (so a full-model writer's check still fires) even though
     * the title path never checks the version itself.
     */
    private function demonstrateVersionAwareSibling(SymfonyStyle $io, int $invoiceId): void
    {
        $this->entityManager->clear();

        $versionBefore = $this->readVersion($invoiceId);

        $titleEdit = $this->entityManager->find(InvoiceTitleEdit::class, $invoiceId);
        if (!$titleEdit instanceof InvoiceTitleEdit) {
            throw new \RuntimeException('InvoiceTitleEdit projection was not found.');
        }
        $titleEdit->title = 'Retitled by the narrow edit path';
        $this->entityManager->persist($titleEdit);
        $this->entityManager->flush();

        $io->section('4. #[VersionAware] sibling bumps but does not check');
        $io->definitionList(
            ['shared version before title edit' => (string) $versionBefore],
            ['shared version after title edit' => (string) $this->readVersion($invoiceId)],
            ['note' => 'the bump keeps a checking sibling\'s lost-update detection honest'],
        );
    }

    private function readVersion(int $invoiceId): int
    {
        $row = $this->entityManager
            ->getConnection()
            ->executeQuery('SELECT version FROM invoices WHERE id = ?', [$invoiceId])
            ->fetch();

        return (int) ($row['version'] ?? -1);
    }

    private function createConcurrentEntityManager(): EntityManager
    {
        $connection = new Connection(
            $this->env('DATABASE_DSN'),
            $this->env('DATABASE_USER'),
            $this->env('DATABASE_PASSWORD'),
        );

        return new EntityManager($connection);
    }

    private function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("Missing required environment variable {$name}.");
        }

        return $value;
    }
}
