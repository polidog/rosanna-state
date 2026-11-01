<?php

declare(strict_types=1);

// 全部のサンプルを 1 つのサーバーで動かすための `php -S` のルータースクリプト。
// ROSANNA_SNAPSHOT_SECRET=$(php -r 'echo bin2hex(random_bytes(32));') php -S localhost:8000 examples/router.php

require_once __DIR__ . '/layout.php';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/rosanna.js') {
    header('Content-Type: text/javascript; charset=UTF-8');
    readfile(__DIR__ . '/../public/rosanna.js');

    return;
}

if (strlen((string) getenv('ROSANNA_SNAPSHOT_SECRET')) < 16) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "ROSANNA_SNAPSHOT_SECRET is not set. Start the server like this:\n\n"
        . "ROSANNA_SNAPSHOT_SECRET=\$(php -r 'echo bin2hex(random_bytes(32));') php -S localhost:8000 examples/router.php\n";

    return;
}

// defer の断片は `/_defer/{名前}` に来る。使うのは 09-defer だけ
if (str_starts_with($path, '/_defer/')) {
    require __DIR__ . '/09-defer/index.php';

    return;
}

$examples = array_map(basename(...), glob(__DIR__ . '/[0-9][0-9]-*', GLOB_ONLYDIR) ?: []);
$first = explode('/', trim($path, '/'))[0];

if ($first === '') {
    $links = array_map(
        static fn(string $name): string => '<li><a href="/' . $name . '">' . $name . '</a></li>',
        $examples,
    );
    echo example_layout('Rosanna examples', '<ul>' . implode('', $links) . '</ul>');

    return;
}

if (!in_array($first, $examples, true)) {
    http_response_code(404);
    echo example_layout('Not found', '<p>No such example.</p>');

    return;
}

require __DIR__ . '/' . $first . '/index.php';
