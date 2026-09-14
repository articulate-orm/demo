<?php

namespace App\Features\OptimisticLocking\Command;

use App\Features\OptimisticLocking\Entity\DocumentContent;
use App\Features\OptimisticLocking\Entity\DocumentContentMirror;
use App\Features\OptimisticLocking\Entity\DocumentMetadata;
use App\Features\OptimisticLocking\Entity\DocumentViewCount;
use Articulate\Connection;
use Articulate\Exceptions\OptimisticLockException;
use Articulate\Modules\EntityManager\EntityManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:documents:optimistic-locking', description: 'Optimistic-locking scenarios: separate slices, an unguarded slice, and rival counters')]
final class DocumentsOptimisticLockingCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'articulate.command.validate')]
        private readonly Command $validateCommand,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $documentId = $this->seedDocument();

        $io->section('articulate:validate — one shared "documents" table, four entity classes');
        $io->text('DocumentContent + DocumentMetadata: no errors below (separate, non-overlapping slices).');
        $io->text('DocumentViewCount: no errors below (unguarded, but never writes a guarded column).');
        $io->text('DocumentContentMirror: "Rival counters" + missing-acknowledgement errors below.');
        $this->validateCommand->run(new ArrayInput([]), $output);

        $this->demonstrateSeparateSlices($io, $documentId);
        $this->demonstrateUnguardedSlice($io, $documentId);
        $this->demonstrateRivalCounters($io, $documentId);

        return Command::SUCCESS;
    }

    /**
     * Separate slices: DocumentContent and DocumentMetadata guard disjoint
     * column sets, so editing both concurrently never conflicts. Editing the
     * SAME slice concurrently still correctly throws OptimisticLockException.
     */
    private function demonstrateSeparateSlices(SymfonyStyle $io, int $documentId): void
    {
        $io->section('Scenario 1 — separate slices (DocumentContent / DocumentMetadata)');

        $contentEditor = $this->createEntityManager();
        $metadataEditor = $this->createEntityManager();

        $content = $contentEditor->find(DocumentContent::class, $documentId);
        $metadata = $metadataEditor->find(DocumentMetadata::class, $documentId);
        if (!$content instanceof DocumentContent || !$metadata instanceof DocumentMetadata) {
            throw new \RuntimeException('Seed document was not loaded.');
        }

        $content->content = 'Edited by the content editor';
        $metadata->title = 'Edited by the metadata editor';

        $contentEditor->flush();
        $metadataEditor->flush();

        $io->definitionList(
            ['content flush' => 'ok, version_content ' . ($content->version_content - 1) . ' -> ' . $content->version_content],
            ['metadata flush' => 'ok, version_metadata ' . ($metadata->version_metadata - 1) . ' -> ' . $metadata->version_metadata],
        );

        $firstEditor = $this->createEntityManager();
        $secondEditor = $this->createEntityManager();
        $firstCopy = $firstEditor->find(DocumentContent::class, $documentId);
        $secondCopy = $secondEditor->find(DocumentContent::class, $documentId);
        if (!$firstCopy instanceof DocumentContent || !$secondCopy instanceof DocumentContent) {
            throw new \RuntimeException('Seed document was not loaded.');
        }

        $firstCopy->content = 'First editor wins';
        $firstEditor->flush();

        $secondCopy->content = 'Second editor is now stale';
        try {
            $secondEditor->flush();
            throw new \RuntimeException('Expected the stale flush to be rejected.');
        } catch (OptimisticLockException $e) {
            $io->definitionList(
                ['same-slice conflict' => 'second flush on DocumentContent rejected as expected'],
                ['message' => $e->getMessage()],
            );
        }
    }

    /**
     * DocumentViewCount never declares #[Version] and never writes a column
     * any other slice guards — articulate:validate stays clean for it, and
     * two concurrent writers can both flush without any lock.
     */
    private function demonstrateUnguardedSlice(SymfonyStyle $io, int $documentId): void
    {
        $io->section('Scenario 2 — unguarded slice (DocumentViewCount)');

        $firstReader = $this->createEntityManager();
        $secondReader = $this->createEntityManager();
        $first = $firstReader->find(DocumentViewCount::class, $documentId);
        $second = $secondReader->find(DocumentViewCount::class, $documentId);
        if (!$first instanceof DocumentViewCount || !$second instanceof DocumentViewCount) {
            throw new \RuntimeException('Seed document was not loaded.');
        }

        $first->view_count += 1;
        $firstReader->flush();

        $second->view_count += 1;
        $secondReader->flush();

        $io->definitionList(
            ['both flushes' => 'accepted, no #[Version] to check'],
            ['final view_count' => (string) $second->view_count],
        );
    }

    /**
     * DocumentContentMirror guards 'content' with its own version_content_alt
     * column, unrelated to DocumentContent's version_content. Neither side
     * ever sees the other's version change, so no exception is thrown and
     * the row silently ends up with only one editor's content — exactly the
     * lost update #[Version] exists to prevent.
     */
    private function demonstrateRivalCounters(SymfonyStyle $io, int $documentId): void
    {
        $io->section('Scenario 3 — overlapping slices (DocumentContent / DocumentContentMirror)');

        $viaContent = $this->createEntityManager();
        $viaMirror = $this->createEntityManager();
        $content = $viaContent->find(DocumentContent::class, $documentId);
        $mirror = $viaMirror->find(DocumentContentMirror::class, $documentId);
        if (!$content instanceof DocumentContent || !$mirror instanceof DocumentContentMirror) {
            throw new \RuntimeException('Seed document was not loaded.');
        }

        $mirror->content = 'Written through the mirror slice';
        $viaMirror->flush();

        $content->content = 'Written through the original slice, unaware of the mirror write';
        $viaContent->flush();

        $reloadedEm = $this->createEntityManager();
        $reloaded = $reloadedEm->find(DocumentContent::class, $documentId);
        if (!$reloaded instanceof DocumentContent) {
            throw new \RuntimeException('Seed document was not loaded.');
        }

        $io->definitionList(
            ['mirror flush' => 'accepted, checked only version_content_alt'],
            ['original flush' => 'also accepted, checked only version_content — never saw the mirror write'],
            ['row content now' => $reloaded->content],
            ['mirror\'s write' => 'silently lost — this is what validate\'s "Rival counters" error warns about'],
        );
    }

    private function seedDocument(): int
    {
        $entityManager = $this->createEntityManager();

        $content = new DocumentContent();
        $content->content = 'Initial content';
        $entityManager->persist($content);
        $entityManager->flush();

        if ($content->id === null) {
            throw new \RuntimeException('Seed document id was not assigned.');
        }

        $metadata = $entityManager->find(DocumentMetadata::class, $content->id);
        $viewCount = $entityManager->find(DocumentViewCount::class, $content->id);
        if (!$metadata instanceof DocumentMetadata || !$viewCount instanceof DocumentViewCount) {
            throw new \RuntimeException('Seed document was not loaded through its other slices.');
        }

        $metadata->title = 'Untitled document';
        $metadata->tags = 'demo';
        $viewCount->view_count = 0;
        $entityManager->flush();

        return $content->id;
    }

    private function createEntityManager(): EntityManager
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
