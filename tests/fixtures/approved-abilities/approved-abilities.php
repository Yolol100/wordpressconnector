<?php
declare(strict_types=1);

namespace ConnectorApprovedAbilitiesFixture;

final class Provider
{
    public static int $calls = 0;

    public function execute($input): array
    {
        ++self::$calls;
        return array('ok' => true, 'input' => $input, 'api_key' => 'must-not-be-exposed');
    }
}
