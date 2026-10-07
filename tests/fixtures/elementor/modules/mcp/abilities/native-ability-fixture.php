<?php

declare(strict_types=1);

namespace Elementor\Modules\Mcp\Abilities;

abstract class Abstract_Ability
{
    public static bool $available = true;
    protected string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function get_id(): string
    {
        return $this->id;
    }

    public function is_available()
    {
        return self::$available ? true : new \WP_Error('elementor_mcp_unavailable');
    }

    public function execute_guarded($input = null)
    {
        return $input;
    }
}

final class Contract_Native_Ability extends Abstract_Ability
{
}
