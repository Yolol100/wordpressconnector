<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

final class GutenbergAdapter
{
    public function register(Registry $registry): void
    {
        $registry->register('gutenberg.inspect', array($this, 'inspect'), array('description' => 'Parse Gutenberg/block content into a block tree.'));
        $registry->register('gutenberg.replace', array($this, 'replace'), array('mutation' => true, 'description' => 'Replace the entire serialized block document.'));
        $registry->register('gutenberg.patch', array($this, 'patch'), array('mutation' => true, 'description' => 'Patch one block by nested numeric path.'));
    }

    public function inspect(array $payload): array
    {
        $post = $this->post($payload);
        Policy::assertPostReadable($post);
        $blocks = parse_blocks((string) $post->post_content);
        return array(
            'post_id' => (int) $post->ID,
            'blocks' => $blocks,
            'fingerprint' => Fingerprint::make($blocks),
        );
    }

    public function replace(array $payload, array $context): array
    {
        $post = $this->post($payload);
        $beforeContent = (string) $post->post_content;
        $beforeBlocks = parse_blocks($beforeContent);

        if (isset($payload['blocks']) && is_array($payload['blocks'])) {
            $afterContent = serialize_blocks($payload['blocks']);
        } elseif (isset($payload['content'])) {
            $afterContent = (string) $payload['content'];
            parse_blocks($afterContent);
        } else {
            throw new RuntimeException('gutenberg.replace requires payload.blocks or payload.content.');
        }

        $result = array(
            'post_id' => (int) $post->ID,
            'before_blocks' => $beforeBlocks,
            'after_blocks' => parse_blocks($afterContent),
            '_current_fingerprint' => Fingerprint::make($beforeBlocks),
        );

        if (! empty($context['dry_run'])) {
            return $result;
        }

        $updated = wp_update_post(wp_slash(array('ID' => (int) $post->ID, 'post_content' => $afterContent)), true);
        if (is_wp_error($updated)) {
            throw new RuntimeException($updated->get_error_message());
        }

        $result['_rollback'] = array('action' => 'gutenberg.replace', 'payload' => array('id' => (int) $post->ID, 'content' => $beforeContent));
        return $result;
    }

    public function patch(array $payload, array $context): array
    {
        $post = $this->post($payload);
        $beforeContent = (string) $post->post_content;
        $blocks = parse_blocks($beforeContent);
        $path = isset($payload['path']) && is_array($payload['path']) ? array_map('intval', $payload['path']) : array();
        if (! $path) {
            throw new RuntimeException('gutenberg.patch requires a non-empty numeric payload.path.');
        }

        $patched = $blocks;
        $block =& $this->blockByPath($patched, $path);
        $beforeBlock = $block;

        if (isset($payload['replace_block']) && is_array($payload['replace_block'])) {
            $block = $payload['replace_block'];
        } else {
            if (isset($payload['attrs']) && is_array($payload['attrs'])) {
                $replaceAttrs = Input::bool($payload, 'replace_attrs');
                $block['attrs'] = $replaceAttrs ? $payload['attrs'] : array_merge(isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array(), $payload['attrs']);
            }
            if (array_key_exists('innerHTML', $payload)) {
                $block['innerHTML'] = (string) $payload['innerHTML'];
                if (empty($block['innerContent']) || ! is_array($block['innerContent'])) {
                    $block['innerContent'] = array((string) $payload['innerHTML']);
                } elseif (1 === count($block['innerContent']) && is_string($block['innerContent'][0])) {
                    $block['innerContent'][0] = (string) $payload['innerHTML'];
                } else {
                    throw new RuntimeException('innerHTML patch is ambiguous for blocks with nested innerContent. Use replace_block.');
                }
            }
        }

        $afterContent = serialize_blocks($patched);
        $result = array(
            'post_id' => (int) $post->ID,
            'path' => $path,
            'before_block' => $beforeBlock,
            'after_block' => $block,
            '_current_fingerprint' => Fingerprint::make($blocks),
        );

        if (! empty($context['dry_run'])) {
            return $result;
        }

        $updated = wp_update_post(wp_slash(array('ID' => (int) $post->ID, 'post_content' => $afterContent)), true);
        if (is_wp_error($updated)) {
            throw new RuntimeException($updated->get_error_message());
        }

        $result['_rollback'] = array('action' => 'gutenberg.replace', 'payload' => array('id' => (int) $post->ID, 'content' => $beforeContent));
        return $result;
    }

    private function post(array $payload): \WP_Post
    {
        $post = get_post(isset($payload['id']) ? (int) $payload['id'] : 0);
        if (! $post instanceof \WP_Post) {
            throw new RuntimeException('Post not found.');
        }
        Policy::assertReadablePostType((string) $post->post_type);
        return $post;
    }

    private function &blockByPath(array &$blocks, array $path): array
    {
        $current =& $blocks;
        foreach ($path as $depth => $index) {
            if (! isset($current[$index]) || ! is_array($current[$index])) {
                throw new RuntimeException('Block path does not exist at depth ' . $depth . '.');
            }
            $block =& $current[$index];
            if ($depth === count($path) - 1) {
                return $block;
            }
            if (! isset($block['innerBlocks']) || ! is_array($block['innerBlocks'])) {
                throw new RuntimeException('Block path enters a block without innerBlocks.');
            }
            $current =& $block['innerBlocks'];
        }

        throw new RuntimeException('Invalid block path.');
    }
}
