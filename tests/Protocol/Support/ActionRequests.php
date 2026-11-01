<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Protocol\Support;

use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Protocol\ActionEnvelope;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\RenderContext;

/**
 * 描画した HTML からブラウザと同じ送信の内容を作る。
 */
trait ActionRequests
{
    private string $logFile = '';

    private string $outerErrorLog = '';

    private function captureLog(): void
    {
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'rosanna-log-');
        $this->outerErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    private function releaseLog(): void
    {
        ini_set('error_log', $this->outerErrorLog);
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    private function log(): string
    {
        return (string) file_get_contents($this->logFile);
    }

    private static function get(string $uri = '/page'): Request
    {
        [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
        parse_str($query, $params);
        /** @var array<string, mixed> $params */

        return new Request('GET', $uri, $path, $query, $params, [], [], ['host' => 'app.test']);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $headers
     */
    private static function post(array $fields, bool $partial = false, string $uri = '/page', array $headers = []): Request
    {
        [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
        $path = '/' . ltrim(explode('#', str_replace('\\', '/', $path), 2)[0], '/');
        parse_str($query, $params);
        /** @var array<string, mixed> $params */
        $headers += ['host' => 'app.test', 'origin' => 'http://app.test'];
        if ($partial) {
            $headers['x-rosanna-partial'] = '1';
        }

        return new Request('POST', $uri, $path, $query, $params, $fields, [], $headers);
    }

    private static function page(Rosanna $app, callable $build, string $uri = '/page'): string
    {
        return RenderContext::run($app->scope(self::get($uri)), static function () use ($app, $build): string {
            RenderContext::beginRender();
            $element = $build();

            return is_string($element) ? $element : $app->renderElement($element);
        });
    }

    private static function dom(string $html): \Dom\HTMLDocument
    {
        return \Dom\HTMLDocument::createFromString('<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOERROR);
    }

    /**
     * $nth 番目の action のボタンを押したときの送信の内容。
     *
     * @return array<string, mixed>
     */
    private static function press(string $html, int $nth = 0): array
    {
        $document = self::dom($html);
        $button = $document->querySelectorAll('button[name="_rosanna_action"]')->item($nth);
        self::assertNotNull($button, 'The action button is not in: ' . $html);
        $form = $document->getElementById((string) $button->getAttribute('form'));
        self::assertNotNull($form, 'The button form is not in: ' . $html);

        return self::fieldsOf($form) + ['_rosanna_action' => (string) $button->getAttribute('value')];
    }

    /**
     * onSubmit のフォームの送信の内容。
     *
     * @param array<string, string> $values 欄の値
     * @return array<string, mixed>
     */
    private static function submit(string $html, array $values = []): array
    {
        $form = self::dom($html)->querySelector('form[data-rosanna-form]:not([hidden])');
        self::assertNotNull($form, 'The form is not in: ' . $html);

        return $values + self::fieldsOf($form);
    }

    /** @return array<string, mixed> PHP が読むのと同じ形 */
    private static function fieldsOf(\Dom\Element $form): array
    {
        $pairs = [];
        foreach ($form->querySelectorAll('input') as $input) {
            $name = $input->getAttribute('name');
            if ($name !== null && $name !== '') {
                $pairs[] = rawurlencode($name) . '=' . rawurlencode((string) $input->getAttribute('value'));
            }
        }
        parse_str(implode('&', $pairs), $fields);
        /** @var array<string, mixed> $fields */

        return $fields;
    }

    /**
     * 署名済みの action を手で作る。サーバーが描画した場合と同じ。
     *
     * @param array<string, mixed>|string $action
     * @return array<string, mixed>
     */
    private static function signed(Rosanna $app, string $instanceId, array|string $action, string $path = '/page'): array
    {
        $json = is_string($action) ? $action : (string) json_encode($action);

        return [
            '_rosanna_component' => $instanceId,
            '_rosanna_action' => new ActionEnvelope($json, $app->signer()->sign($instanceId, $json, $path))->toJson(),
        ];
    }

    /** @return array<string, mixed> */
    private static function restoreFields(string $instanceId, ?string $signature, string $snapshot): array
    {
        $json = (string) json_encode(['type' => 'restore', 'payload' => new \stdClass(), 'componentId' => $instanceId, 'storageType' => 'snapshot']);

        return [
            '_rosanna_component' => $instanceId,
            '_rosanna_action' => new ActionEnvelope($json, $signature)->toJson(),
            '_rosanna_snapshot' => $snapshot,
        ];
    }

    private static function wrapperAttribute(string $html, string $attribute, string $instanceId): string
    {
        foreach (self::dom($html)->querySelectorAll('[data-rosanna]') as $wrapper) {
            if ($wrapper->getAttribute('data-rosanna') === $instanceId) {
                return (string) $wrapper->getAttribute($attribute);
            }
        }
        self::fail("No wrapper for {$instanceId} in: {$html}");
    }

    /** ラッパーのスナップショットの state */
    private static function snapshotState(string $json): mixed
    {
        $data = json_decode($json, true);
        self::assertIsArray($data);

        return $data['state'];
    }
}
