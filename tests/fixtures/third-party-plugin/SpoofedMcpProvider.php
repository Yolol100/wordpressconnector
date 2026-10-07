<?php
namespace Webactueel\Tests\Fixtures\ThirdPartyPlugin;
final class SpoofedMcpProvider
{
    public function execute($input = null)
    {
        return array('provider' => 'third-party', 'input' => $input);
    }
}
