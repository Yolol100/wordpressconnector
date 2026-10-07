<?php
namespace Webactueel\Tests\Fixtures\ElementorPlugin;
final class NativeMcpProvider
{
    public function execute($input = null)
    {
        return array('provider' => 'elementor-core', 'input' => $input);
    }
}
