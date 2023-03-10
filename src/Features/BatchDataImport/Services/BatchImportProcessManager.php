<?php

namespace App\Features\BatchDataImport\Services;

use App\Features\BatchDataImport\Commands\BatchImportCommand;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Process\Process;

class BatchImportProcessManager
{
    public function startImportProcess(File $file): int
    {
        $command = BatchImportCommand::getDefaultName();
        $pathname = escapeshellarg($file->getPathname());
        $process = Process::fromShellCommandline("php bin/console $command $pathname -r &");
        $process->setWorkingDirectory(__DIR__ . '/../../../../');
        $process->setOptions(['create_new_console' => true]);
        $process->disableOutput();
        $process->setTimeout(0);
        $process->start();
        return $process->getPid();
    }
}
