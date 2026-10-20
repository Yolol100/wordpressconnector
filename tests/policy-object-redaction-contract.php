<?php

declare(strict_types=1);

$plugin = $argv[1] ?? dirname(__DIR__) . '/plugin/wordpressconnector';
require_once $plugin . '/includes/Security/Policy.php';
require_once $plugin . '/includes/Support/Json.php';
require_once $plugin . '/includes/Runtime/Request.php';
require_once $plugin . '/includes/Runtime/Result.php';

use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Runtime\Result;

final class RedactionSerializableFixture implements JsonSerializable {
    public $calls = 0;
    public function jsonSerialize(): array {
        ++$this->calls;
        return array('nested' => (object) array('api_key' => 'fixture-secret', 'visible' => 'ok'));
    }
}
final class RedactionThrowFixture implements JsonSerializable {
    public function jsonSerialize(): array { throw new RuntimeException('fixture-private-error'); }
}
final class RedactionPublicFixture {
    public $visible = 'ok';
    public $api_key = 'fixture-secret';
    private $internal = 'fixture-private-property';
}

$count = 0;
$check = static function (bool $value, string $message) use (&$count): void {
    ++$count;
    if (!$value) throw new RuntimeException($message);
};
$encode = static function ($value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION); };
$input = array('object' => (object) array('api_key' => 'fixture-secret', 'list' => array((object) array('password' => 'fixture-password', 'visible' => 'ok'))));
$clean = Policy::redact($input);
$check(is_array($clean) && $clean['object'] instanceof stdClass, 'Root array and nested object types must be preserved.');
$check($clean['object']->api_key === '[redacted]', 'Object API key was not redacted.');
$check($clean['object']->list[0]->password === '[redacted]', 'Object nested in array was not redacted.');
$check($clean['object']->list[0]->visible === 'ok', 'Public object value changed.');
$check($input['object']->api_key === 'fixture-secret', 'Input object must not be mutated.');
$check(strpos($encode($clean), 'fixture-secret') === false, 'Secret reached encoded result.');
foreach (array(null, false, true, 0, 1.0, '', 'normal', array(), new stdClass(), (object) array('0'=>'zero'), array(2=>'two')) as $value) {
    $check($encode(Policy::redact($value)) === $encode($value), 'JSON type or scalar value changed.');
}
$ser = new RedactionSerializableFixture();
$encoded = $encode(Policy::redact(array('provider' => $ser)));
$check(strpos($encoded,'fixture-secret') === false && strpos($encoded,'visible') !== false, 'JsonSerializable output leaked secrets or lost public fields.');
$check($ser->calls === 1, 'Provider serialization callback must run once.');
$plain = $encode(Policy::redact(new RedactionPublicFixture()));
$check(strpos($plain, 'fixture-secret') === false && strpos($plain, 'fixture-private-property') === false && strpos($plain, 'visible') !== false, 'Object visibility boundary failed.');
$date = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
$check($encode(Policy::redact($date)) === $encode($date), 'Internal object JSON shape changed.');
$check(strpos($encode(Policy::redact(new RedactionThrowFixture())), 'fixture-private-error') === false, 'Serialization exception leaked its message.');
$cycle = new stdClass(); $cycle->child = $cycle; $cycle->token = 'fixture-secret';
$check(strpos($encode(Policy::redact($cycle)), 'fixture-secret') === false, 'Object cycle was not safely bounded.');
$arrayCycle = array(); $arrayCycle['child'] =& $arrayCycle;
$check(strlen($encode(Policy::redact($arrayCycle))) < 8192, 'Array recursion must terminate within depth budget.');
$shared = (object) array('visible'=>'ok');
$check($encode(Policy::redact(array($shared,$shared))) === '[{"visible":"ok"},{"visible":"ok"}]', 'Shared objects must not be confused with cycles.');
$wide = array_fill(0,100100,0);
$check(count(Policy::redact($wide)) < count($wide), 'Traversal node budget not enforced.');
foreach (array('auth_key','secure_auth_key','logged_in_key','nonce_key','AUTH_SALT') as $key) {
    $check(Policy::redact(array($key=>'fixture-secret'))[$key] === '[redacted]', 'Sensitive key protection differs from access policy: '.$key);
}
$request = Request::fromArray(array('version'=>1,'request_id'=>'redaction-runtime-001','action'=>'post.get','payload'=>array(),'dry_run'=>true,'confirm'=>false));
$success = Result::success($request, $input, array('provider'=>(object) array('token'=>'fixture-secret')));
$failure = Result::failure($request, 'Safe failure', array('provider'=>(object) array('api_key'=>'fixture-secret')));
$check(is_array($success) && true === $success['ok'] && strpos($encode($success),'fixture-secret') === false, 'Result::success boundary failed.');
$check(is_array($failure) && false === $failure['ok'] && strpos($encode($failure),'fixture-secret') === false, 'Result::failure boundary failed.');
echo "PASS $count object redaction and result-boundary assertions\n";
