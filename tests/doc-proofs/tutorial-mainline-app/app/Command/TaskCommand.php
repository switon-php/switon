<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TaskServiceInterface;
use PDO;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Throwable;

class TaskCommand
{
    #[Autowired] protected TaskServiceInterface $taskService;

    #[Autowired] protected ConsoleInterface $console;

    public function completeAction(int $id): int
    {
        $task = $this->taskService->complete($id);
        if (($task['found'] ?? true) === false) {
            return $this->console->error('Task {id} was not found.', ['id' => $id], ConsoleInterface::FAILURE);
        }

        $this->console->success('Completed task {id}.', [
            'id' => $task['id'] ?? $id,
        ]);

        return ConsoleInterface::SUCCESS;
    }

    public function setupAction(bool $reset = false): int
    {
        try {
            $databaseDir = dirname(__DIR__, 2) . '/database';
            $databaseFile = $databaseDir . '/tutorial.sqlite';
            $schemaFile = $databaseDir . '/schema.sql';

            if ($reset && is_file($databaseFile)) {
                unlink($databaseFile);
            }

            $schema = file_get_contents($schemaFile);
            if (!is_string($schema) || $schema === '') {
                return $this->console->error('Schema file is empty: {file}', ['file' => $schemaFile], ConsoleInterface::FAILURE);
            }

            $pdo = new PDO('sqlite:' . $databaseFile);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec($schema);

            $count = (int) $pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
            if ($count === 0) {
                $statement = $pdo->prepare('INSERT INTO tasks (title, note, completed) VALUES (:title, :note, :completed)');
                $statement->execute([
                    'title' => 'Read the tutorial',
                    'note' => 'Seeded by task:setup',
                    'completed' => 0,
                ]);
            }

            $this->console->success('Learning mainline proof app database is ready at {file}.', ['file' => $databaseFile]);

            return ConsoleInterface::SUCCESS;
        } catch (Throwable $e) {
            return $this->console->error('Failed to setup learning mainline proof app: {message}', [
                'message' => $e->getMessage(),
            ], ConsoleInterface::FAILURE);
        }
    }
}
