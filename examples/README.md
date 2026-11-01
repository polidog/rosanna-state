# Rosanna examples

[日本語](README.ja.md)

Small samples that all run on one server. Each one fits in a single `index.php` (example 10 adds PSX files). The only shared code is the HTML shell in [layout.php](layout.php).

## Running

Run from the repository root. PHP 8.5+ is required.

```bash
composer install
bin/rosanna compile examples/10-psx/components --cache=examples/10-psx/var/cache/psx   # only needed for 10-psx
ROSANNA_SNAPSHOT_SECRET=$(php -r 'echo bin2hex(random_bytes(32));') php -S localhost:8000 examples/router.php
```

Open http://localhost:8000 for the list.

## Examples

Read in order to go from the basics to embedding in a host. The last column says whether the sample works without `rosanna.js`.

| Example | What it shows | Main APIs | Without JS |
|---|---|---|---|
| [01-counter](01-counter/index.php) | The basic shape of a component and its host | `fc`, `useState`, `actions()->handle` | Works |
| [02-form](02-form/index.php) | Taking input and validating it on the server | `useFormAction`, `select`, `checkbox` | Works |
| [03-cart](03-cart/index.php) | One state for a whole list, with totals from server prices | array `useState`, row `id` | Works |
| [04-class-component](04-class-component/index.php) | Classes, and keys that separate instances | `#[Component]`, `BaseComponent`, `register`, `render` | Works |
| [05-storage](05-storage/index.php) | What each storage type keeps after a reload | `StorageType`, `SnapshotPersist` | Works except Memory and persist |
| [06-shareable-url](06-shareable-url/index.php) | Sharing state through the URL | `SnapshotBehavior::Persistent`, `restoreSnapshots` | Works |
| [07-live-search](07-live-search/index.php) | Filtering as you type while keeping focus | `onInput`, `StorageType::Snapshot` | Works through the button |
| [08-guard](08-guard/index.php) | A host protecting a page, next to the host's own form | `pagePath`, `csrf()` | Works |
| [09-defer](09-defer/index.php) | A shared page body with per-visitor fragments | `Defer`, `H::defer`, `handleDeferred` | Shows only the fallback |
| [10-psx](10-psx/) | Tags and nested components | `.psx`, `rosanna compile`, `loadComponentManifest` | Works |

## Notes

- **The secret comes from the environment.** The command above picks a new one on every start, so actions on pages left open will fail. Open them again.
- **The samples share a session** because they share an origin. Each one uses its own `fc()` keys so their state does not mix.
- **[router.php](router.php) only exists to list the samples.** In your application a host router or one PHP file per page does this job, and `vendor/bin/rosanna publish` puts `rosanna.js` in `public/`.
- **Signing in to 08-guard checks no password.** It only shows the order of the checks.
- **No hook appends a form value to an array.** `useFormAction` overwrites a hook with the field's value. For a to-do style screen where typed text joins a list, write to your database from an ordinary host form and read it back while rendering. Per-row operations can follow 03-cart.

Worker mode needs FrankenPHP or similar, so it is not included here. See [worker mode in the README](../README.md#worker-mode).

## Tests

`tests/browser/examples.spec.ts` drives every sample in a real browser, most of them both with and without JavaScript.

```bash
npm ci && npx playwright install chromium
npx playwright test tests/browser/examples.spec.ts
```
