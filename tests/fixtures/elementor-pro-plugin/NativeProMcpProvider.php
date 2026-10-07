<?php
namespace Webactueel\Tests\Fixtures\ElementorProPlugin;
final class NativeProMcpProvider
{
    public function execute($input = null)
    {
        return array('provider' => 'elementor-pro', 'input' => $input);
    }
}
