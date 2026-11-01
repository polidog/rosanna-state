<?php

declare(strict_types=1);

use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;

require_once __DIR__ . '/../layout.php';

$manifest = __DIR__ . '/var/cache/psx/' . PsxRenderer::MANIFEST_FILENAME;
if (!is_file($manifest)) {
    http_response_code(500);
    echo example_layout('PSX', '<p>Compile the components first:</p>'
        . '<pre>bin/rosanna compile examples/10-psx/components --cache=examples/10-psx/var/cache/psx</pre>');

    return;
}

$app = new Rosanna();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));
$app->loadComponentManifest($manifest);

$request = Request::fromGlobals();
$page = fn(): Element => RenderContext::scope()->psx->render('Examples\\Psx\\TaskBoard', ['title' => 'Release checklist']);

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $page());
if ($response !== null) {
    $response->send();

    return;
}

$body = RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));

echo example_layout('PSX', $body);
