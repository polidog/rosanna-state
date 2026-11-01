<?php

declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RenderContext;

use function Polidog\Rosanna\Runtime\useFormAction;
use function Polidog\Rosanna\Runtime\useState;

require_once __DIR__ . '/../layout.php';

const EXAMPLE_PLANS = ['free' => 'Free', 'team' => 'Team', 'business' => 'Business'];

/**
 * 欄の値はクライアント入力なので、描画のたびにサーバーで確かめる。
 *
 * @return array<string, string> 欄の名前 => エラーの文
 */
function example_validate(string $name, string $email, string $plan, string $agree): array
{
    $errors = [];
    if (trim($name) === '') {
        $errors['name'] = 'Enter your name.';
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (!array_key_exists($plan, EXAMPLE_PLANS)) {
        $errors['plan'] = 'Choose a plan.';
    }
    // チェックを外した checkbox は空文字で届く
    if ($agree !== 'yes') {
        $errors['agree'] = 'Accept the terms to continue.';
    }

    return $errors;
}

$SignupForm = fc(function (): Element {
    [$name, $setName] = useState('');
    [$email, $setEmail] = useState('');
    [$plan, $setPlan] = useState('free');
    [$agree, $setAgree] = useState('');
    // 最初の表示でエラーを出さないよう、送信されたかを hidden の欄で持つ
    [$submitted, $setSubmitted] = useState('');

    $errors = $submitted === '1' ? example_validate($name, $email, $plan, $agree) : [];
    $errorOf = fn(string $field): ?Element => isset($errors[$field])
        ? H::span(className: 'error', children: ' ' . $errors[$field])
        : null;

    return H::div(children: [
        H::form(
            onSubmit: useFormAction([
                'name' => $setName,
                'email' => $setEmail,
                'plan' => $setPlan,
                'agree' => $setAgree,
                'submitted' => $setSubmitted,
            ]),
            children: [
                H::input(type: 'hidden', name: 'submitted', value: '1'),
                H::label(children: ['Name ', H::input(name: 'name', value: $name), $errorOf('name')]),
                H::label(children: ['Email ', H::input(type: 'email', name: 'email', value: $email), $errorOf('email')]),
                H::label(children: [
                    'Plan ',
                    H::select(name: 'plan', children: array_map(
                        fn(string $value, string $label): Element => H::option(value: $value, selected: $value === $plan, children: $label),
                        array_keys(EXAMPLE_PLANS),
                        EXAMPLE_PLANS,
                    )),
                    $errorOf('plan'),
                ]),
                H::label(children: [
                    H::input(type: 'checkbox', name: 'agree', value: 'yes', checked: $agree === 'yes'),
                    ' I accept the terms',
                    $errorOf('agree'),
                ]),
                H::button(children: 'Sign up'),
            ],
        ),
        $submitted === '1' && $errors === []
            ? H::p(id: 'result', children: "Welcome, {$name}! You are on the " . EXAMPLE_PLANS[$plan] . ' plan.')
            : null,
    ]);
}, key: 'example-signup');

$app = new Rosanna();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $SignupForm());
if ($response !== null) {
    $response->send();

    return;
}

$body = RenderContext::run($app->scope($request), fn(): string => $app->renderElement($SignupForm()));

echo example_layout('Form with validation', $body);
