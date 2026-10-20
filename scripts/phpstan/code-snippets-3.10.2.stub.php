<?php
/**
 * Development-only declarations from Code Snippets 3.10.2 (not runtime code).
 * Source: https://github.com/codesnippetspro/code-snippets/tree/v3.10.2
 * Files: src/php/snippet-ops.php, src/php/Model/Snippet.php, src/php/Model/Model.php.
 * Keep the optional-provider availability checks inside the real adapter.
 */

namespace Code_Snippets\Model {
    /**
     * The upstream base class implements magic access for the fields below.
     * Omitting it makes PHP 8.2+ analysis reject the documented properties.
     */
    abstract class Model {
        /** @return mixed */
        public function __get(string $field) {}
        /** @param mixed $value */
        public function __set(string $field, $value) {}
        /** @return bool */
        public function __isset(string $field) {}
    }

    /**
     * @property int $id
     * @property string $name
     * @property string $code
     * @property string $desc
     * @property string $scope
     * @property bool $active
     * @property bool $network
     * @property bool $locked
     * @property bool $trashed
     */
    class Snippet extends Model {
        /** @param array<string, mixed>|object|null $initial_data */
        public function __construct($initial_data = null) {}
    }
}

namespace Code_Snippets {
    use Code_Snippets\Model\Snippet;

    /** @param list<int> $ids
     *  @return list<Snippet>
     */
    function get_snippets(array $ids = [], ?bool $network = null): array {}

    function get_snippet(int $id = 0, ?bool $network = null): ?Snippet {}

    /** @param Snippet|array<string, mixed> $snippet */
    function save_snippet($snippet): ?Snippet {}

    /** @return Snippet|string */
    function activate_snippet(int $id, ?bool $network = null) {}

    function deactivate_snippet(int $id, ?bool $network = null): ?Snippet {}

    function delete_snippet(int $id, ?bool $network = null): bool {}

    /** @param array<string, mixed> $fields */
    function update_snippet_fields(int $snippet_id, array $fields, ?bool $network = null) {}
}
