<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wordpress-escape-fixture.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Support/Json.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Support/class-fingerprint.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Runtime/class-request.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Security/class-filesystempolicy.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-siteeditoradapter.php';

$serviceAliases = array(
    'Webactueel\WordPressConnector\Adapters\SiteEditorAdapter' => array(
        'listTemplates' => 'Webactueel\WordPressConnector\Adapters\SiteEditorActions::list_templates',
        'globalStyles' => 'Webactueel\WordPressConnector\Adapters\SiteEditorActions::global_styles',
    ),
    'Webactueel\WordPressConnector\Runtime\Request' => array(
        'fromFile' => 'Webactueel\WordPressConnector\Runtime\RequestMethods::from_file',
        'fromArray' => 'Webactueel\WordPressConnector\Runtime\RequestMethods::from_array',
        'dryRun' => 'Webactueel\WordPressConnector\Runtime\RequestMethods::dry_run',
        'expectedFingerprint' => 'Webactueel\WordPressConnector\Runtime\RequestMethods::expected_fingerprint',
        'expectedStateToken' => 'Webactueel\WordPressConnector\Runtime\RequestMethods::expected_state_token',
    ),
    'Webactueel\WordPressConnector\Security\FilesystemPolicy' => array(
        'assertListable' => 'Webactueel\WordPressConnector\Security\FilesystemPolicyMethods::assert_listable',
        'assertReadableText' => 'Webactueel\WordPressConnector\Security\FilesystemPolicyMethods::assert_readable_text',
        'assertWritableText' => 'Webactueel\WordPressConnector\Security\FilesystemPolicyMethods::assert_writable_text',
        'assertLifecycleText' => 'Webactueel\WordPressConnector\Security\FilesystemPolicyMethods::assert_lifecycle_text',
        'affectsBrowserExecution' => 'Webactueel\WordPressConnector\Security\FilesystemPolicyMethods::affects_browser_execution',
        'isWritableText' => 'Webactueel\WordPressConnector\Security\FilesystemPolicyMethods::is_writable_text',
    ),
    'Webactueel\WordPressConnector\Support\Fingerprint' => array(
        'siteToken' => 'Webactueel\WordPressConnector\Support\FingerprintMethods::site_token',
        'siteTokenFromFingerprint' => 'Webactueel\WordPressConnector\Support\FingerprintMethods::site_token_from_fingerprint',
    ),
);

foreach ($serviceAliases as $className => $aliases) {
    $reflection = new ReflectionClass($className);
    if ($reflection->getTraitAliases() !== $aliases) {
        throw new RuntimeException('Service method alias contract changed: '.$className);
    }
    foreach ($aliases as $legacy => $implementation) {
        $canonical = substr($implementation, strrpos($implementation, '::') + 2);
        $old = new ReflectionMethod($className, $legacy);
        $new = new ReflectionMethod($className, $canonical);
        // PHP 7.4 reflects the original trait method name for native aliases.
        $reflectedName = PHP_VERSION_ID < 80000 ? $canonical : $legacy;
        if (!method_exists($className, $legacy) || !method_exists($className, $canonical) ||
            !$old->isPublic() || $old->getName() !== $reflectedName ||
            $old->getDeclaringClass()->getName() !== $className ||
            $old->isStatic() !== $new->isStatic() ||
            (string)$old->getReturnType() !== (string)$new->getReturnType() ||
            count($old->getParameters()) !== count($new->getParameters())) {
            throw new RuntimeException('Service method signature changed: '.$className.'::'.$legacy);
        }
        foreach ($old->getParameters() as $index => $parameter) {
            $other = $new->getParameters()[$index];
            if ($parameter->getName() !== $other->getName() ||
                (string)$parameter->getType() !== (string)$other->getType() ||
                $parameter->isOptional() !== $other->isOptional() ||
                $parameter->isPassedByReference() !== $other->isPassedByReference() ||
                $parameter->isDefaultValueAvailable() !== $other->isDefaultValueAvailable() ||
                ($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() !== $other->getDefaultValue())) {
                throw new RuntimeException('Service named argument contract changed: '.$legacy);
            }
        }
    }
}


use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Support\Fingerprint;

$fingerprint = Fingerprint::make(array('post_id' => 10, 'data' => array('x' => 1)));
$token = Fingerprint::siteTokenFromFingerprint($fingerprint);
if (! preg_match('/^[a-f0-9]{64}\z/', $token)) {
    fwrite(STDERR, "Site state token is not a SHA-256 HMAC.\n");
    exit(1);
}

$request = Request::fromArray(array(
    'version' => 1,
    'request_id' => 'state-token-001',
    'action' => 'elementor.replace_document',
    'payload' => array('id' => 10),
    'dry_run' => false,
    'confirm' => true,
    'expected_fingerprint' => $fingerprint,
    'expected_state_token' => $token,
));
if ($request->expectedStateToken() !== $token || $request->expectedFingerprint() !== $fingerprint) {
    fwrite(STDERR, "Request state guards were not preserved.\n");
    exit(1);
}

try {
    Request::fromArray(array(
        'version' => 1,
        'request_id' => 'state-token-002',
        'action' => 'post.update',
        'payload' => array('id' => 10),
        'dry_run' => true,
        'confirm' => false,
        'expected_state_token' => 'bad',
    ));
    fwrite(STDERR, "Invalid expected_state_token was accepted.\n");
    exit(1);
} catch (RuntimeException $error) {
}

echo "state token contract OK\n";
