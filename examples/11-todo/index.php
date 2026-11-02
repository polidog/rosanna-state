<?php

declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;

use function Polidog\Rosanna\State\fc;

use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

use function Polidog\Rosanna\State\useEffect;
use function Polidog\Rosanna\State\useFormAction;
use function Polidog\Rosanna\State\useState;

require_once __DIR__ . '/../layout.php';

const EXAMPLE_TODO_MAX_LENGTH = 40;

/**
 * 欄の値はクライアント入力なので、保存の前にサーバーで確かめる。required はブラウザの親切で、検証ではない。
 */
function example_todo_error(string $title): ?string
{
    if (trim($title) === '') {
        return 'Enter a title.';
    }
    if (mb_strlen($title) > EXAMPLE_TODO_MAX_LENGTH) {
        return 'Keep the title to ' . EXAMPLE_TODO_MAX_LENGTH . ' characters.';
    }

    return null;
}

$TodoList = fc(function (): Element {
    [$title, $setTitle] = useState('');
    // 同じタイトルでも deps が変わるよう、描画ごとに違う値を hidden の欄で送る
    [$nonce, $setNonce] = useState('');

    $error = $nonce !== '' ? example_todo_error($title) : null;

    // 描画の途中で動く。一覧を読むより前に書くので、足した行は同じ応答に出る
    useEffect(function () use ($nonce, $title, $error): void {
        if ($nonce !== '' && $error === null) {
            // 実際のアプリではデータベースへの INSERT。この例では $_SESSION が代わりになる
            $_SESSION['example-todos'][] = trim($title);
        }
    }, [$nonce]);

    /** @var list<string> $todos */
    $todos = $_SESSION['example-todos'] ?? [];

    return H::div(children: [
        H::form(
            onSubmit: useFormAction(['title' => $setTitle, 'nonce' => $setNonce]),
            children: [
                H::input(type: 'hidden', name: 'nonce', value: bin2hex(random_bytes(8))),
                H::label(children: [
                    'Title ',
                    // 保存できたら空に戻す。不正なら打ったものを残す
                    H::input(name: 'title', required: true, value: $error !== null ? $title : ''),
                    $error !== null ? H::span(className: 'error', children: ' ' . $error) : null,
                ]),
                H::button(children: 'Add'),
            ],
        ),
        H::ul(id: 'todos', children: array_map(
            fn(int $index, string $todo): Element => H::li(id: "todo-{$index}", children: $todo),
            array_keys($todos),
            $todos,
        )),
    ]);
}, key: 'example-todo');

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $TodoList());
if ($response !== null) {
    $response->send();

    return;
}

$body = RenderContext::run($app->scope($request), fn(): string => $app->renderElement($TodoList()));

echo example_layout('To-do list', $body);
