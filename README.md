# Rosanna State

Server-side state for [Rosanna](https://github.com/polidog/rosanna), named like it after the TOTO song "Rosanna", famous for Jeff Porcaro's shuffle.

[日本語](README.ja.md)

`polidog/rosanna-state` adds components in the shape of React Hooks to Rosanna's rendering. The server owns the state, and buttons and forms work without JavaScript. Loading `rosanna.js` turns them into partial updates.

This package holds the hooks (`fc()`, `useState` and the rest), actions, signatures, CSRF, Snapshot, Storage and `rosanna.js`. Defer lives in [`polidog/rosanna-defer`](https://github.com/polidog/rosanna-defer), which this package requires. `H`, `Element`, `Renderer` and the PSX compiler live in [`polidog/rosanna`](https://github.com/polidog/rosanna), which this package requires. A site that only renders does not need this package.

It has no router, no DI and no events. Choosing a page from a URL belongs to the host (a framework, or one PHP file per page). Together with `polidog/rosanna` it is a rewrite of [usePHP](https://github.com/polidog/usePHP); see [the migration steps](#migrating-from-usephp).

- PHP 8.5+ with the `session` and `mbstring` extensions. `polidog/rosanna` requires `nikic/php-parser` for the PSX compiler. Rendering does not load it.
- The current version is v0.0.1. Composer's `^0.0.1` matches 0.0.1 only, so `polidog/rosanna` and this package are released together.

## Installation

```bash
composer require polidog/rosanna-state
vendor/bin/rosanna-state publish   # copies rosanna.js and rosanna-defer.js to public/
```

To publish on every `composer install`, add this to `composer.json`:

```json
{
    "scripts": {
        "post-install-cmd": ["Polidog\\Rosanna\\State\\Installer\\AssetInstaller::publish"],
        "post-update-cmd": ["Polidog\\Rosanna\\State\\Installer\\AssetInstaller::publish"]
    }
}
```

## Initialization

Initializing the state layer takes two things and nothing else:

1. `composer require polidog/rosanna-state`
2. `new RosannaState()`

There is no bootstrap call. Composer's `autoload.files` loads the hooks and connects the state layer to `polidog/rosanna`.

`RosannaState` also has the rendering API of `Rosanna` (`renderElement()`, `registerComponent()`, `loadComponentManifest()`, `setArgumentResolver()`), so it is the only object your app holds.

## What the host still does

Initialization does not connect the state layer to your requests. The host has to do these four things, all of which appear in the [minimal example](#minimal-example):

| What | How | Without it |
|---|---|---|
| Pass POSTs to the state layer | `$app->actions()->handle($request, replay: ...)`, then `send()` the response if it is not `null` | Buttons and forms do nothing |
| Render inside the request's scope | `RenderContext::run($app->scope($request), fn() => $app->renderElement(...))` | `renderElement()` builds a scope from the PHP globals on its own, but a `fc()` called before that has none: Snapshot throws `LogicException`, a deferred component stays a placeholder, and a `Request` you built is ignored |
| Serve the JavaScript | `RosannaState::renderClientScript()` in the page, and `vendor/bin/rosanna-state publish` to place the file | No partial updates, and defers show only their fallback. Elements that need `rosanna.js` do nothing |
| Set the secret, if you use Snapshot | `$app->setSnapshotSecret($secret)` ([The secret](#the-secret)) | Rendering a Snapshot component throws `LogicException` |

Depending on what you use, the host also:

- calls `$app->handleDeferred($request)` before page handling and routes `/_defer/*` to it ([Defer](#defer));
- compiles `.psx` files with `vendor/bin/rosanna-state compile` rather than `vendor/bin/rosanna compile` ([PSX](#psx));
- calls `RequestLifecycle::reset()` between requests in a worker ([Worker mode](#worker-mode));
- checks access to the page before `handle()` ([The host checks access](#the-host-checks-access)).

## Minimal example

A function wrapped in `fc()` is a component, and `useState` holds its state.

<!-- readme-test: quickstart -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useState;

require __DIR__ . '/../vendor/autoload.php';

$Counter = fc(function (array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return H::div(className: 'counter', children: [
        H::span(children: "Count: {$count}"),
        H::button(onClick: fn() => $setCount($count + 1), children: '+'),
    ]);
}, key: 'counter');

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();
$page = fn(): Element => H::main(children: [$Counter(['initial' => 0])]);

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $page());
if ($response !== null) {
    $response->send();

    return;
}

$body = RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));

echo '<!doctype html><html><head><meta charset="utf-8">', RosannaState::renderClientScript(), '</head><body>';
echo $body;
echo '</body></html>';
```

```bash
ROSANNA_SNAPSHOT_SECRET=$(php -r 'echo bin2hex(random_bytes(32));') php -S localhost:8000 -t public
```

Each press sends a POST that `handle()` turns into a state update.

- Without JavaScript: a 303 back to the same URL and a full page render.
- With `rosanna.js`: only the component's fragment comes back and is morphed into the DOM. Unsent input, focus and selection survive.

`handle()` returns `null` when the request is not a Rosanna action. It sends no headers and no body (the one exception is the cookie PHP sends when a session starts), so the host calls `send()` or converts the response for its framework. **Always render before any output** (see [Do not render after output](#do-not-render-after-output)).

`$setCount($count + 1)` does not mean "add". It is an action meaning "set it to this value", fixed at render time. The closure runs while rendering, not on click.

Runnable samples are in [examples/](examples/README.md).

### Instance keys

State belongs to an instance ID. The ID looks like `FC@<file name>:<start line>#<key>` and does not include the URL.

<!-- readme-test: keys -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useState;

require __DIR__ . '/../vendor/autoload.php';

$Task = fc(function (array $props): Element {
    [$likes, $setLikes] = useState(0);

    return H::div(children: [
        H::span(children: "{$props['title']}: {$likes}"),
        H::button(onClick: fn() => $setLikes($likes + 1), children: 'Like'),
    ]);
}, key: 'task');

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();
$page = fn(): Element => H::main(children: [
    $Task(['key' => 'a', 'title' => 'first']),
    $Task(['key' => 'b', 'title' => 'second']),
]);

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $page());
if ($response !== null) {
    $response->send();

    return;
}

echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));
```

- **We recommend passing a key to `fc()`.** Without one, instances are told apart by their order under the same name, so a change of order swaps their state. The ID does not include the file's path, so closures on the same line of same-named files in different directories get the same name and only a key can separate them.
- For per-row or per-record state, pass a `key` prop (string or int). Combined with the `fc()` key it becomes `task/a`.
- Rendering one ID twice shares its state. This is not detected.
- Moving `fc()` to another line changes the closure's ID, and the state starts over.
- Actions for an ID over 255 bytes or containing control characters get a 400.

## Hooks

- `useState($initial)` returns `[value, setter]`. State is null, scalars and arrays of those only. Objects, `NAN`, `INF` and nesting beyond 512 levels throw at render time. `$set(null)` stores null.
- `useFormAction(['field name' => $setter])` copies submitted fields into hooks.
- `useEffect($callback, $deps)` runs synchronously while rendering: every time for `null`, once for `[]`, and when a value changes by `!==` for an array. Returning a `\Closure` makes it a cleanup, which only ever runs within the same request.

Calling a hook outside a component throws `LogicException`. Hooks are identified by call order, so do not change their order with conditionals.

### Array deps in useEffect start a session

Array deps are stored so that the next request can compare them. In a Session component, **array deps in `useEffect` (including `[]`) start a session and a cookie, even on an anonymous visitor's page.** `null` deps are not stored and start nothing. Reading `useState` alone starts no session. On pages meant for a CDN, avoid array deps or use Memory or Snapshot.

<!-- readme-test: effect -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useEffect;

require __DIR__ . '/../vendor/autoload.php';

$Article = fc(function (array $props): Element {
    useEffect(function () use ($props): void {
        error_log("viewed {$props['id']}");
    }, [$props['id']]);

    return H::p(children: "Article {$props['id']}");
}, key: 'article');

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();
echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($Article(['id' => 1])));
```

### Form input

<!-- readme-test: form-input -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useFormAction;
use function Polidog\Rosanna\State\useState;

require __DIR__ . '/../vendor/autoload.php';

$Profile = fc(function (): Element {
    [$name, $setName] = useState('');

    return H::form(onSubmit: useFormAction(['name' => $setName]), children: [
        H::input(name: 'name', value: $name),
        H::button(children: 'Save'),
        H::p(children: "Saved: {$name}"),
    ]);
}, key: 'profile');

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();
$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $Profile());
if ($response !== null) {
    $response->send();

    return;
}

echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($Profile()));
```

Which field goes into which hook is signed, but **the values are client input.** A value that is not a string, is over 65,535 bytes or is invalid UTF-8 rejects the whole request with a 400. A field that was not sent (an unchecked checkbox) becomes an empty string.

Use `useFormAction` on a form's `onSubmit`, or on `onChange` / `onInput` of an `input`, `select` or `textarea` with a `name` (its own field only).

## H and attributes

`H` is part of `polidog/rosanna`; see [its README](https://github.com/polidog/rosanna#h-and-attributes) for `children` and attributes. `RosannaState` renders the same elements. What this package adds is the event props.

<!-- readme-test: elements -->
```php
<?php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\State\RosannaState;

require __DIR__ . '/vendor/autoload.php';

$items = ['one', 'two'];

echo new RosannaState()->renderElement(H::div(className: 'card', children: [
    H::h2(children: 'Title'),
    $items !== [] ? H::ul(children: array_map(fn(string $item) => H::li(children: $item), $items)) : null,
    H::button(...['aria-label' => 'Close', 'data-id' => '1'], children: 'x'),
    H::textarea(name: 'body', value: 'text'),
]));
```

- The events are `onClick`, `onChange`, `onInput` and `onSubmit`. `onFocus`, `onBlur`, `onLoad` and `onError` throw. Values are an `Action` or a closure without required parameters only. Function-name strings are refused without being run.

| Event | Without JavaScript |
|---|---|
| `onClick` on `button` and `input[type=submit\|image]` | Works |
| `onSubmit` on `form` | Works |
| `onClick`, `onChange`, `onInput` on other elements (`onInput` waits for 300ms without input) | Needs `rosanna.js` |

## Class components

This is the basic shape of embedding in a host. `render()` returns HTML with the wrapper `div`.

<!-- readme-test: class-component -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\State\Component\BaseComponent;
use Polidog\Rosanna\State\Component\Component;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

require __DIR__ . '/../vendor/autoload.php';

#[Component]
final class Counter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return H::div(children: [
            H::span(children: "Count: {$count}"),
            H::button(onClick: fn() => $setCount($count + 1), children: '+'),
        ]);
    }
}

$app = new RosannaState();
$app->register(Counter::class);

$request = Request::fromGlobals();
$response = $app->actions()->handle($request, replay: fn() => $app->render(Counter::class));
if ($response !== null) {
    $response->send();

    return;
}

echo $app->render(Counter::class);
```

- `render()` and `createElement()` take the registered name or the class name. Anything unregistered throws `LogicException`.
- The second argument of `$app->render(Counter::class, 'item-1')` is the key.
- Registered classes are created with `new` and no arguments. Do not register a class whose constructor needs arguments; let the host build its `Element`.
- `#[Component(name: '...', storage: 'snapshot', persist: 'sessionStorage')]` sets the name and storage.
- This example has no secret, so the signing key lives in the session and opening the page starts one. Call `setSnapshotSecret()` to avoid that.

When the host combines components with its own layout, build the `Element` inside `RenderContext::run($app->scope($request), ...)` and call `renderElement()`, as in the [minimal example](#minimal-example).

## Storage

| Type | Where | On reload | Good for |
|---|---|---|---|
| `StorageType::Session` (default) | `$_SESSION` | Kept | Forms, carts |
| `StorageType::Memory` | Within the request | Back to initial | Temporary UI |
| `StorageType::Snapshot` | Signed JSON in the page | Back to initial (`persist` keeps it) | No state on the server |

<!-- readme-test: storage -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;
use Polidog\Rosanna\State\Storage\SnapshotPersist;
use Polidog\Rosanna\State\Storage\StorageType;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useState;

require __DIR__ . '/../vendor/autoload.php';

$view = function (string $label): Element {
    [$count, $setCount] = useState(0);

    return H::div(children: [
        H::span(children: "{$label}: {$count}"),
        H::button(onClick: fn() => $setCount($count + 1), children: '+'),
    ]);
};

$InSession = fc(fn() => $view('session'), key: 'in-session');
$InMemory = fc(fn() => $view('memory'), key: 'in-memory', storageType: StorageType::Memory);
$InSnapshot = fc(fn() => $view('snapshot'), key: 'in-snapshot', storageType: StorageType::Snapshot);
$Persisted = fc(
    fn() => $view('persisted'),
    key: 'persisted',
    storageType: StorageType::Snapshot,
    persist: SnapshotPersist::SessionStorage,
);

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();
$page = fn(): Element => H::main(children: [$InSession(), $InMemory(), $InSnapshot(), $Persisted()]);

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $page());
if ($response !== null) {
    $response->send();

    return;
}

echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));
```

- **Session** starts the session on the first write, never on a read.
- **Memory** only changes through partial updates, so it needs JavaScript. A redirect goes back to the initial value. Each action starts from an empty store, so components with several `useState` lose the other values.
- **Snapshot** requires `setSnapshotSecret()`; without it, rendering throws `LogicException`. Without JavaScript the POST is answered with the full page (`Cache-Control: no-store`), so a reload makes the browser ask to resend.
- With `persist`, `rosanna.js` saves to `sessionStorage` (per tab) or `localStorage` (shared) and redraws with `restore` on load. `window.Rosanna.forgetSnapshots()` clears it.

### Keeping snapshots outside the page

Set this with `behavior` on `handle()` and with `restoreSnapshots()`, called before rendering a GET.

<!-- readme-test: snapshot-behavior -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\State\Component\BaseComponent;
use Polidog\Rosanna\State\Component\Component;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;
use Polidog\Rosanna\State\Snapshot\SnapshotBehavior;

require __DIR__ . '/../vendor/autoload.php';

#[Component(storage: 'snapshot')]
final class Cart extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return H::div(children: [
            H::span(children: "Items: {$count}"),
            H::button(onClick: fn() => $setCount($count + 1), children: 'Add'),
        ]);
    }
}

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));
$app->register(Cart::class);

$request = Request::fromGlobals();
$behavior = SnapshotBehavior::Persistent;

$response = $app->actions()->handle($request, replay: fn() => $app->render(Cart::class), behavior: $behavior);
if ($response !== null) {
    $response->send();

    return;
}

$app->restoreSnapshots($request, $behavior);
echo $app->render(Cart::class);
```

| Behavior | Read from | Written after an action to |
|---|---|---|
| `SnapshotBehavior::Isolated` (default) | The page | Nowhere |
| `SnapshotBehavior::Persistent` | `?_rosanna_snapshot=` | The redirect URL |
| `SnapshotBehavior::Session` | `$_SESSION` | The same; bound to the session ID |

- Persistent carries one instance only. An action on another instance replaces it, with a warning in the log. Use Session for pages with several instances.
- Session holds up to 64 instances; beyond that the oldest goes back to its initial state. It does not read `_rosanna_snapshot` from the URL.
- Defer endpoints do not read the session slot.

### Submissions from a stale page

An action carries the version it was rendered with, signed. A submission from a stale page is not applied over newer state; the latest state is drawn instead. The second click of a double click is the same, so **it does not add up.** This is not suitable for counting clicks.

- **Session** compares on the server. A partial update gets the latest fragment with 200 and `X-Rosanna-Stale: 1`; a plain POST gets a 303.
- **Snapshot** compares in `rosanna.js` against a copy in `sessionStorage`. A stale form is not sent, and `restore` redraws. The copy is dropped on reload and normal navigation, so a reload without `persist` goes back to the initial value. Without `sessionStorage` the comparison uses page memory and cannot work after a reload or going back.
- In both cases `rosanna.js` fires a cancelable `rosanna:stale` event and keeps the input of the submitted form.
- Memory does not compare.

**A session handler that does not lock cannot stop concurrent requests.** The Session check compares and writes while the session is active, so PHP's default file sessions serialize them with a lock. With a non-locking setup such as Redis or Memcached, or a host that calls `session_write_close` midway, concurrent requests in one session can both pass and the later write wins.

**The Snapshot check prevents conflicts; it is not a security boundary.** It only runs on the client, so it cannot stop a submission without JavaScript or a hand-made request with an old saved snapshot. **Do not keep single-use rights (coupons, payment completion) in a Snapshot.**

## PSX

A `.psx` file is PHP in which you can write tags like HTML. [docs/PSX.md](https://github.com/polidog/rosanna/blob/main/docs/PSX.md) has the details of the syntax, the manifest and errors.

<!-- readme-test: psx-counter -->
```php
<?php
// components/Counter.psx
declare(strict_types=1);

namespace App;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useState;

return fc(function (array $props) {
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return (
        <div className="counter">
            <span>Count: {$count}</span>
            <button onClick={fn() => $setCount($count + 1)}>+</button>
        </div>
    );
}, key: 'counter');
```

<!-- readme-test: psx-host -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

require __DIR__ . '/../vendor/autoload.php';

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));
$app->loadComponentManifest(__DIR__ . '/../var/cache/psx/' . PsxRenderer::MANIFEST_FILENAME);

$request = Request::fromGlobals();
$page = fn(): Element => RenderContext::scope()->psx->render('App\\Counter', ['initial' => 5]);

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $page());
if ($response !== null) {
    $response->send();

    return;
}

echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));
```

```bash
vendor/bin/rosanna-state compile components
```

- A `.psx` whose file name starts with an uppercase letter is a component, and its FQCN is `namespace\FileName`. Other `.psx` files call it as `<Counter initial={5} />`.
- Output goes to `var/cache/psx/`, never into the source tree. Add it to `.gitignore`.
- **Build and run at the same path.** The manifest holds absolute paths to the compiled files.
- **Compile with `vendor/bin/rosanna-state compile`.** `RosannaState::loadComponentManifest()` throws when the deferred manifest is missing or its build ID does not match `manifest.php`. `vendor/bin/rosanna compile` is also installed, but it does not look at defer and removes any deferred manifest it finds, so an app using `RosannaState` has to run `rosanna-state compile` again after it.
- **Component files containing the string `Defer` are executed at compile time.** Do not put code with side effects at their top level.
- Make the cache directory writable by the build role only, not by HTTP requests. Never pass a request-derived path to `loadComponentManifest()`.

## Embedding in a host

The order is:

1. **The host checks access to the page.**
2. `$app->handleDeferred($request)` (if you use defer).
3. `$app->actions()->handle($request, replay: ...)`.
4. If both return `null`, render.

`replay` redraws after an action. It returns an `Element` or finished HTML. `$partial` in `fn(bool $partial)` is `true` for a partial update. A partial has to find the component, so return `fc()` components as an `Element` or pass them through `renderElement()`. If it is not found, the response is a redirect, and an Isolated Snapshot loses its state with a warning in the log.

Exceptions from `replay` reach the host. The state may already be applied.

### The host checks access

Rosanna has no router, so protecting a page is the host's job. **Check before calling `handle()`.**

<!-- readme-test: guard -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useState;

require __DIR__ . '/../vendor/autoload.php';

$request = Request::fromGlobals();

$isAdmin = ($request->cookies['role'] ?? '') === 'admin';
if ($request->pagePath() === '/admin' && !$isAdmin) {
    http_response_code(403);
    echo 'Forbidden';

    return;
}

$Panel = fc(function (): Element {
    [$count, $setCount] = useState(0);

    return H::button(onClick: fn() => $setCount($count + 1), children: "Runs: {$count}");
}, key: 'panel');

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $Panel());
if ($response !== null) {
    $response->send();

    return;
}

echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($Panel()));
```

A signature is bound to the page it was rendered for, so posting a protected page's form to another page gets a 400. But signatures treat paths differing only in leading and trailing `/` as the same page. **Comparing the raw path, as in `$request->path === '/admin'`, is bypassed by a POST to `/admin/`.** Compare `$request->pagePath()`, which uses the same unit as the signature, as in the example, or check after a router that treats them the same way.

One route is outside this protection: `/_defer/{name}` (see [Security notes](#security-notes)).

### Worker mode

A host that serves several requests from one process, such as FrankenPHP or RoadRunner, **must call `RequestLifecycle::reset()` between requests.** Otherwise the previous visitor's Memory and Snapshot state, the render scope and the instance counters remain for the next request, and the next visitor can enter the previous visitor's session. `reset()` writes and closes the session and releases its ID; the next start uses the ID in `$_COOKIE`. If your host calls `session_start()` itself after `reset()`, pass that ID with `session_id()` first.

<!-- readme-test: worker -->
```php
<?php
// worker.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;
use Polidog\Rosanna\State\Storage\StorageType;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useState;

require __DIR__ . '/vendor/autoload.php';

$Counter = fc(function (): Element {
    [$count] = useState(0);

    return H::span(children: "Count: {$count}");
}, key: 'counter', storageType: StorageType::Memory);

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$handle = function () use ($app, $Counter): void {
    $request = Request::fromGlobals();
    echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($Counter()));
};

while (frankenphp_handle_request($handle)) {
    RequestLifecycle::reset();
}
```

A `RosannaState` instance can be reused across requests; it keeps no per-request information.

### Do not render after output

The session cookie can only be sent before output. If you print HTML and then render, the session can neither start nor resume, and:

- **Session state looks like its initial value.** Even for a visitor with a cookie the saved value cannot be read. This is not an error.
- Writing that state throws `LogicException`.
- Forms have no CSRF token, so a POST from a visitor with a cookie gets a 403.
- Without a secret the signing key cannot be kept in the session, so forms have no signature and the POST gets a 400 (call `setSnapshotSecret()`, or render before output).

Put the rendered result in a variable before printing the layout as in the [minimal example](#minimal-example), or use `ob_start()`. Calling `session_start()` yourself first also works.

### Framework CSRF and TLS

If your framework validates CSRF, opt out with `$app->disableCsrfProtection()`. For your own forms, `$app->csrf()->token()` returns the token.

Behind a TLS-terminating proxy `$_SERVER['HTTPS']` is empty and valid POSTs get a 403. Pass it with something like `fastcgi_param HTTPS on`, or call `$app->trustProxyHeaders()`. Use the latter **only when your proxy always overwrites the client's `X-Forwarded-*`.** Otherwise an attacker can get around the same-origin check.

## Defer

Fetch per-visitor parts, such as a login name or cart count, in a separate request after the page loads, so that the page itself can live on a CDN. The work is done by [`polidog/rosanna-defer`](https://github.com/polidog/rosanna-defer), which also works without `RosannaState` if you do not need state.

<!-- readme-test: defer -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Component\BaseComponent;
use Polidog\Rosanna\State\Component\Component;
use Polidog\Rosanna\Defer\Defer;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;
use Polidog\Rosanna\State\Storage\StorageType;

use function Polidog\Rosanna\State\fc;

require __DIR__ . '/../vendor/autoload.php';

#[Component(name: 'AnnouncementBar', storage: 'memory')]
#[Defer(name: 'announcement-bar', cacheControl: 'public, s-maxage=60', localCache: true, localCacheTtl: 60)]
final class AnnouncementBar extends BaseComponent
{
    public function render(): Element
    {
        return H::p(children: 'Sale today');
    }
}

$UserHeader = fc(
    fn(array $props): Element => H::header(children: 'Hello ' . (string) ($props['name'] ?? 'guest')),
    key: 'user-header',
    storageType: StorageType::Memory,
    defer: new Defer(name: 'user-header', cacheControl: 'private, no-store', reloadable: true),
);

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));
$app->register(AnnouncementBar::class);
$app->registerComponent('App\\UserHeader', $UserHeader);
$app->registerDeferred('user-header', 'App\\UserHeader', 'private, no-store');

$request = Request::fromGlobals();

$deferred = $app->handleDeferred($request);
if ($deferred !== null) {
    $deferred->send();

    return;
}

$page = fn(): Element => H::main(children: [
    H::defer('announcement-bar', fallback: H::p(children: '...'), localCache: true, localCacheTtl: 60),
    $UserHeader(['name' => 'Ann', 'fallback' => H::p(children: 'Loading')]),
]);

echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));
```

- The host routes `{prefix}/{name}` to the app and calls `handleDeferred()` before page handling. The prefix is `/_defer`; `setDeferPrefix('/api/_d')` changes it, and then `RosannaState::renderClientScript('/rosanna.js', '/api/_d')` needs the same value.
- `register()` also registers the endpoint of a `#[Defer]` class. For PSX, `loadComponentManifest()` registers from the deferred manifest.
- Props other than `fallback` and `key` become the query. Scalars only, and they arrive as strings. `key` is not accepted from the URL.
- Names match `[A-Za-z0-9_-]+`, and registering a name twice throws.
- Without JavaScript only the fallback is visible.

### With one PHP file per page

There is no built-in router, so when embedding in per-page PHP files **the web server must send `/_defer/*` to an entry file.** An example `.htaccess` for Apache:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^_defer/ defer.php [L,QSA]
```

<!-- readme-test: defer-entry -->
```php
<?php
// public/defer.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Defer\Defer;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;
use Polidog\Rosanna\State\Storage\StorageType;

use function Polidog\Rosanna\State\fc;

require __DIR__ . '/../vendor/autoload.php';

$Notice = fc(
    fn(): Element => H::p(children: 'Notice'),
    key: 'notice',
    storageType: StorageType::Memory,
    defer: new Defer(name: 'notice'),
);

$app = new RosannaState();
$app->registerComponent('App\\Notice', $Notice);
$app->registerDeferred('notice', 'App\\Notice');

$response = $app->handleDeferred(Request::fromGlobals());
if ($response === null) {
    http_response_code(404);

    return;
}

$response->send();
```

### Caching

- `cacheControl` defaults to `private, max-age=0`. Even with a shared-cache policy such as `public, s-maxage=60`, if rendering used the session or set a cookie the header becomes `private, no-store` with a warning in the log. A host that calls `session_start()` on every request gets no shared caching.
- Reading per-visitor data directly from `$_COOKIE` is invisible to Rosanna. Set `private` yourself in that case.
- Do not put action buttons in publicly cached fragments. A visitor with a session who submits a cached form fails signature verification.
- `localCache: true` saves to `localStorage`, and `localCacheTtl` is in seconds. Fragments containing a form, CSRF token or snapshot are not written to `localStorage` even with `localCache`. `window.Rosanna.clearDeferCache(nameOrUrl)` clears it.
- `reloadable: true` keeps the region refetchable, through `window.Rosanna.reloadDefer(nameOrUrl)` or `data-rosanna-reload-defer="name"` (after a successful partial update on a form, on click elsewhere).

## rosanna.js

`RosannaState::renderClientScript($src, $deferPrefix, $nonce, $deferSrc)` prints a `<script defer>` and an inline fallback that fetches defers once if the external file cannot load. Under a strict CSP, pass a nonce as the third argument.

- After a morph a field's value is, in order: what the user edited during the request; the server's value if it rendered something different from last time; the rendered value for fields of the submitted form; otherwise the user's value. Hidden fields and a `button`'s `value` follow the server.
- Give rows of reordered or deleted lists an `id`. Without one they are matched by position.
- A component and its ancestors and descendants update in turn; siblings do not wait. A fixed-value action during a submission is dropped. A `useFormAction` action is resent with the latest value afterwards.
- **A failed partial update is not resent,** because it may already be applied. A cancelable `rosanna:submit-error` (`detail.response` or `detail.error`) fires; if not cancelled, the wrapper gets `data-rosanna-error`.

```js
document.addEventListener('rosanna:submit-error', (e) => {
    e.preventDefault();
    if (e.detail.response?.status === 403) location.reload(); // expired CSRF token
});
```

## CLI

```bash
vendor/bin/rosanna-state publish                 # copy rosanna.js to public/
vendor/bin/rosanna-state compile [paths...]      # compile .psx and write the deferred manifest (default: ./components)
vendor/bin/rosanna-state compile --check         # write nothing, exit 1 if stale; for CI
vendor/bin/rosanna-state compile --watch         # recompile on change
vendor/bin/rosanna-state compile --clean         # empty the output directory
vendor/bin/rosanna-state compile --cache=PATH    # output directory (default: var/cache/psx)
vendor/bin/rosanna-state help
```

A missing path, an unknown option or an unknown command exits 1.

`polidog/rosanna` installs `vendor/bin/rosanna` (`compile` and `help`) next to it. Its `compile` removes the deferred manifest, and `--check` reports one as stale, so use only `rosanna-state compile` in an app using `RosannaState`.

## Security notes

Signatures, CSRF and URL filtering guarantee how values travel. The following is up to your application.

- **`useState` values are client input.** The server computes a setter's value, but a user can resend any action rendered for them as often as they like. **Do not derive authorization, amounts, payment completion or user IDs from hook state.** Read those from `$_SESSION` or a database while rendering.
- **Snapshots appear in HTML in plain text.** The HMAC stops tampering, not reading. Keep secrets and personal data out.
- **Snapshots can be replayed and do not expire.** They stay valid until the key changes (or the session ID, for session-bound ones). Persistent lets anyone with the link reproduce the state. The client also chooses what to send in `_rosanna_snapshots[]`.
- **Do not trust snapshot values through an ancestor's props.** A partial update that carries a snapshot of anything but a descendant of its target gets a 400. A plain POST without `X-Rosanna-Partial` skips this check, because a full-page answer without JavaScript needs the page's snapshots to travel. Do not use an ancestor's state for an authorization decision passed down to a child.
- **With the Session behavior, the POST route still accepts a snapshot from another session.** `_rosanna_snapshot` in the URL is not read, but `_rosanna_snapshot` in an action that passes verification can write an unbound value into your own slot. CSRF protection applies, so only your own session is affected; this falls under hook state being client input.
- **`/_defer/{name}` is a public GET without authentication, outside the host's protection,** even for a defer on a protected page. Check access inside the component. **The query becomes props and is untrusted input.** Validate it, and never let it reach tag names, attribute names or `onClick` and friends.
- **`H` and `Renderer` have their own output rules,** such as the unescaped content of `<style>` and `<script>` and the handling of tag and attribute names. See [the notes in `polidog/rosanna`](https://github.com/polidog/rosanna#security-notes).

### The secret

```bash
php -r 'echo bin2hex(random_bytes(32));'
```

Generate it once and read it from your configuration. **Use the same value across requests and servers.** Changing it invalidates all valid snapshots and forms. Do not commit it. A key shorter than 16 bytes throws `InvalidArgumentException`: signatures appear in the HTML, so a short key can be brute-forced offline. Only the length is checked, so use a random value like the one above.

### Signatures and CSRF

- An action's signature is bound to the page, session, instance and action JSON. Unsigned actions get a 400.
- A form inside a defer is bound to the defer's name and is accepted on a POST to any page. Anyone can fetch a fresh form from the endpoint, so this gives nothing away. The host's protection of the page being posted to still runs.
- A form in HTML inserted into another page gets a 400. Make it a defer, or name the POST target with `$app->signer()->sign($id, $json, $pagePath)`.
- A POST whose `Origin` (or else `Referer`) is not the same origin gets a 403, and so does one with neither. If a session exists, `_rosanna_csrf` is compared too.
- A `_rosanna_action` that is not a JSON object is not treated as Rosanna's and passes through to the host.

### Session cookies

Rosanna does not set cookie flags. Set them in `php.ini` or in `session_start()`. Calling `session_regenerate_id(true)` right after authentication is also the application's job.

```ini
session.cookie_httponly = 1
session.cookie_secure   = 1
session.cookie_samesite = Lax
```

## Known limitations

Even with a whole list in one `useState`, values travel as diffs, so HTML size is proportional to the row count. These shapes **stay quadratic in the row count**:

- With Snapshot, an `onSubmit` form, `input[type=submit|image]` or a `button` with your own `name` or `value` on each row. Each individual form carries a snapshot.
- An operation that changes the whole value, such as reordering, on each row. The whole value is included.
- A full-page answer without JavaScript for Snapshot row components. Each form carries the other instances' snapshots.
- CPU time. `onClick` closures run while rendering.

Others:

- Double clicks and updates from several tabs do not add up; they are redrawn ([Submissions from a stale page](#submissions-from-a-stale-page)).
- Only PHP's native session is supported.
- `component()` (`Polidog\Rosanna\State\component()`) renders no wrapper and always uses Session, so its actions reload the page instead of updating in place.
- PSX output depends on absolute paths ([PSX](#psx)).
- Attribute spread (`<div {...$a} />`) and namespaced attribute names are not available in PSX.
- With a secret key, the first action of a visitor without a cookie starts the session. Other forms that were not redrawn have no CSRF token, so after that response (`X-Rosanna-Session-Started: 1`) `rosanna.js` fetches the page again with a GET and morphs each wrapper to get new forms. Fields being edited, focus and Snapshot state are kept. If the page cannot be fetched, it reloads. Clicks in the meantime follow the in-flight lock: fixed values are dropped and `data-rosanna-with-value` is resent with the latest value. For the same reason, Session actions from forms without a token are not sent at the same time. A stale form in another tab does the same refresh once after a 403 or 400 and fires `rosanna:stale`. That action was not applied, so pressing again works. What remains:
  - The refresh costs one more GET, plus a GET per resolved defer holding a form and a `restore` POST per Snapshot it puts back.
  - Forms outside wrappers that the host built in JS are not renewed. Submitting one gets a 403, so reload on `rosanna:submit-error`.
  - Without JavaScript, the plain POST's answer renews the forms of that tab, but another open tab gets a 403 and needs a reload.
  - If a shared cache answers a request with a cookie using the anonymous page, the forms stay old. As with logged-in pages, do not serve requests with a session cookie from a cache.
- A host that builds a `Request` by hand should keep `Request::$cookies` and `$_COOKIE` the same. Action checks read `Request::$cookies`, while Session hooks resume the session from `$_COOKIE`. `Request::fromGlobals()` keeps them the same.

## Migrating an app that used state on polidog/rosanna

Replacing `new` and `use` is not enough. An app that uses state goes through all of these steps:

1. Run `composer require polidog/rosanna-state`.
2. Change `new Rosanna()` to `new RosannaState()`. `setSnapshotSecret()`, `actions()`, `scope()`, `restoreSnapshots()`, `handleDeferred()` and `renderClientScript()` now belong to `RosannaState`, as do `register()`, `render()` and `createElement()`.
3. Change `use function Polidog\Rosanna\Runtime\useState;` and the like, in `.psx` and PHP, to `Polidog\Rosanna\State\`, following the table below. Change calls of `H::component()` to `State\component()`.
4. Recompile with `vendor/bin/rosanna-state compile`. Generated code copies the `use` lines of the `.psx`, so every `.psx` with state needs recompiling. Only the shape of the component calls the compiler generates (`RenderContext::scope()->psx->render`) stays the same.
5. Change the namespace of `AssetInstaller::publish` in the `scripts` of `composer.json` to `State\Installer\`. `publish` itself moves too: use `vendor/bin/rosanna-state publish`.

| Before (`Polidog\Rosanna\`) | After (`Polidog\Rosanna\State\`) |
|---|---|
| `Runtime\fc`, `Runtime\useState`, `Runtime\useFormAction`, `Runtime\useEffect` | `fc`, `useState`, `useFormAction`, `useEffect` |
| `Html\H::component()` | `component()` |
| `Runtime\Action`, `Runtime\FunctionComponent`, `Runtime\ComponentState`, `Runtime\RendererFactory` and the rest of the state classes in `Runtime\` | The same names directly under `State\` |
| `Runtime\Snapshot` | `Snapshot\Snapshot` |
| `Protocol\*`, `Snapshot\*`, `Storage\*`, `Component\*`, `Http\*`, `Installer\*` | The same names under `State\` |
| `RenderContext::scope()->renderers`, `->signer`, `->signingScope` | `StateScope::of(RenderContext::scope())->renderers` and so on |

`Html\H`, `Html\createElement`, `Runtime\Element`, `Runtime\Renderable`, `Runtime\RenderContext`, `Runtime\RequestLifecycle`, `Runtime\PsxRenderer` and `Psx\*` stay in `polidog/rosanna` under their names.

## Migrating from usePHP

```bash
composer remove polidog/use-php
composer require polidog/rosanna-state
```

1. Replace `Polidog\UsePhp` and `UsePHP` following [the table above](#migrating-an-app-that-used-state-on-polidogrosanna): state goes to `Polidog\Rosanna\State\`, rendering to `Polidog\Rosanna\`, and `UsePHP` becomes `RosannaState`. A plain text replacement to `Polidog\Rosanna` is not enough. If you use `SimpleRouter` or `useRouter`, move to your host's router or per-page PHP files. Relayer users change the `use` of `useRouter` to Relayer's.
2. Fix the `AssetInstaller` namespace in the `scripts` of `composer.json` to `Polidog\Rosanna\State\Installer\`.
3. Change `vendor/bin/usephp` to `vendor/bin/rosanna-state`.
4. Recompile PSX with `vendor/bin/rosanna-state compile`.
5. Run `vendor/bin/rosanna-state publish` to place `rosanna.js`, and fix the `usephp.js` reference in your HTML.
6. If your own JS or templates mention `_usephp_*`, `data-usephp-*`, `X-UsePHP-Partial` or `window.usePHP`, change them to `_rosanna_*`, `data-rosanna-*`, `X-Rosanna-Partial` and `window.Rosanna`.
7. Purge your CDN cache on deploy. Old and new names are never accepted together; old HTML and JS stop working.

A site that uses no hooks and no event props can move to `polidog/rosanna` alone; see [its README](https://github.com/polidog/rosanna#migrating-from-usephp).

The compiler is part of `polidog/rosanna`, so `vendor/bin/rosanna-state compile` also works after `composer install --no-dev`, in a build step or on the production server. Rendering compiled files does not load the compiler or `nikic/php-parser`.

### API mapping

| usePHP | Rosanna |
|---|---|
| `$app->run()`, `getRouter()`, `disableRouter()`, middleware | None; the host does this ([Embedding in a host](#embedding-in-a-host)) |
| `$app->handleAction()` | `$app->actions()->handle($request, replay: ...)`, returning `Response` or `null` |
| `$app->handleDeferred()` | `$app->handleDeferred($request)`, returning `Response` or `null` |
| `isolatedSnapshot()`, `persistentSnapshot()`, `sessionSnapshot()` | `behavior` on `handle()` and `restoreSnapshots()` |
| `sharedSnapshot()` | None; use `SnapshotBehavior::Session` |
| `$app->getCsrfToken()` | `$app->csrf()->token()` |
| `$app->signAction()`, `verifyActionSignature()` | `$app->signer()->sign()`, `verify()` |
| `$app->getSnapshotSerializer()` | `$app->snapshotSerializer()`, returning `null` instead of throwing |
| `$app->redirect()` | None; the redirect target is always the current URL |
| `$app->installPsxErrorHandler()` | None; use `StackTraceRewriter::formatException()` |
| `RenderContext::getApp()`, `setApp()` | `RenderContext::run($app->scope($request), ...)`, `RenderContext::scope()` |
| `H::component()` | `Polidog\Rosanna\State\component()` |
| `CompileCommand::MANIFEST_FILENAME` | `PsxRenderer::MANIFEST_FILENAME` |
| `#[Component(requireKey: ...)]` | None; remove it, or it is an unknown named argument error |

Changes in behavior:

- A `BaseComponent` instance in `children` now reads the storage in `#[Component]` and renders the same wrapper as `fc()`. The HTML changes.
- Reading `useState` as an anonymous visitor does not start a session, and initial values are not stored.
- A `_rosanna_action` that is not JSON passes through to the host instead of getting a 400.
- `_usephp_action_sig` is gone; the value of `_rosanna_action` is JSON holding the action and its signature.
- Hooks outside a component throw `LogicException`.
- String attributes such as `<defer localCache="true" />` are compile errors. Write `{true}`.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
vendor/bin/psalm --no-progress
vendor/bin/php-cs-fixer fix --dry-run --diff
node --test tests/js/*.test.mjs
npm ci && npx playwright install chromium && npx playwright test
```

## License

MIT. See [LICENSE](LICENSE).
