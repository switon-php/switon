<?php

declare(strict_types=1);

namespace App\Controller;

use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('')]
class IndexController extends Controller
{
    #[GetMapping('/')]
    public function indexAction(): array
    {
        return [
            'app' => 'app-docfs',
            'name' => 'docfs',
            'mcp' => '/mcp',
            'tools' => ['list_books', 'list_files', 'read', 'write', 'patch', 'mv_file', 'mkbook'],
            'assets' => 'POST|GET|DELETE /books/{book}/assets[/{name}]',
        ];
    }
}
