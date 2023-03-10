<?php

namespace App\Features\BatchDataImport\Commands;

use App\Features\BatchDataImport\Exceptions\BatchInterruptException;
use App\Features\ImportLogs\DTO\ImportStats;
use App\Features\BatchDataImport\Services\SimpleXmlElementBatching;
use App\Features\ImportLogs\Entities\ImportLog;
use App\Features\ImportLogs\Services\ImportLogRepository;
use App\Features\ProductsImport\Services\ProductBatchPersist;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import:batch:simpleXmlProducts')]
class BatchImportCommand extends Command
{
    private ProductBatchPersist $importer;
    private SimpleXmlElementBatching $batcher;
    private ImportLogRepository $logRepository;
    private ImportLog $import;
    private int $shouldStopBySignal = 0;

    public function __construct(SimpleXmlElementBatching $batcher, ImportLogRepository $logRepository, ProductBatchPersist $importer)
    {
        parent::__construct();
        $this->importer = $importer;
        $this->batcher = $batcher;
        $this->logRepository = $logRepository;
    }

    protected function configure(): void
    {
        $this
            ->setName('import:batch:simpleXmlProducts')
            ->setDescription('Run batch XML products import.')
            ->addArgument('sourceFile', InputArgument::REQUIRED)
            ->addOption('removeSources', 'r', InputOption::VALUE_NONE)
            ->addOption('batchSize', 'b', InputOption::VALUE_REQUIRED, '', 1000)
        ;
    }

    public function stopCommand(int $signal)
    {
        $this->shouldStopBySignal = $signal;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = new \SplFileInfo($input->getArgument('sourceFile'));
        pcntl_signal(SIGTERM, [$this, 'stopCommand']);
        pcntl_signal(SIGINT, [$this, 'stopCommand']);
        pcntl_signal(SIGHUP, [$this, 'stopCommand']);
        $generator = $this->batcher->getBatchGenerator(
            $file->getPathname(),
            'product',
            (int)($input->getOption('batchSize')),
            __DIR__ . '/../../../../schemas/products_flexible.xsd',
        );
        $this->import = ImportLog::fromFileInfo($file);
        $this->logRepository->add($this->import);
        $reportStats = function (ImportStats $stats) use ($output) {
            pcntl_signal_dispatch();
            if ($this->shouldStopBySignal) {
                throw new BatchInterruptException("Stopped by signal [$this->shouldStopBySignal]");
            }
            $this->import->updateStats($stats);
            $this->logRepository->update($this->import);
            foreach ((array)$this->import->getStats() as $stat => $value) {
                $output->writeln("$stat: $value");
            }
        };
        $hasErrors = false;
        try {
            $this->importer->importBatchStreamFromGenerator($generator, $reportStats);
            $this->import->complete();
        } catch (BatchInterruptException $exception) {
            $output->writeln($exception->getMessage());
            $this->import->cancel($exception->getMessage());
        } catch (\Throwable $exception) {
            error_log($exception);
            $output->writeln($exception->getMessage());
            $this->import->fail($exception->getMessage());
            $hasErrors = true;
        }
        $this->logRepository->update($this->import);
        if ($input->getOption('removeSources')) {
            unlink($file->getPathname());
        }
        return $hasErrors ? 1 : 0;
    }
}
