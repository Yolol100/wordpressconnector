<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

final class CoreAdapter
{
    public function register(Registry $registry): void
    {
        $registry->register('post.list', array($this, 'postList'), array('description' => 'List posts, pages or custom post types.'));
        $registry->register('post.get', array($this, 'postGet'), array('description' => 'Read one post object.'));
        $registry->register('post.create', array($this, 'postCreate'), array('mutation' => true, 'description' => 'Create a post, page or custom post type object.'));
        $registry->register('post.update', array($this, 'postUpdate'), array('mutation' => true, 'description' => 'Update a post, page or custom post type object.'));
        $registry->register('post.trash', array($this, 'postTrash'), array('mutation' => true, 'description' => 'Move a post object to Trash.'));
        $registry->register('post.restore', array($this, 'postRestore'), array('mutation' => true, 'description' => 'Restore a trashed post object.'));
        $registry->register('post.revisions', array($this, 'postRevisions'), array('description' => 'List WordPress revisions for a post.'));
        $registry->register('post.restore_revision', array($this, 'postRestoreRevision'), array('mutation' => true, 'description' => 'Restore a WordPress post revision.'));

        $registry->register('meta.get', array($this, 'metaGet'), array('privileged' => true, 'description' => 'Read generic post, term, user or comment metadata.'));
        $registry->register('meta.update', array($this, 'metaUpdate'), array('mutation' => true, 'privileged' => true, 'description' => 'Update generic post, term, user or comment metadata.'));
        $registry->register('meta.delete', array($this, 'metaDelete'), array('mutation' => true, 'privileged' => true, 'description' => 'Delete generic post, term, user or comment metadata.'));

        $registry->register('term.list', array($this, 'termList'), array('description' => 'List terms in any registered taxonomy.'));
        $registry->register('term.get', array($this, 'termGet'), array('description' => 'Read one taxonomy term.'));
        $registry->register('term.create', array($this, 'termCreate'), array('mutation' => true, 'description' => 'Create a taxonomy term.'));
        $registry->register('term.update', array($this, 'termUpdate'), array('mutation' => true, 'description' => 'Update a taxonomy term.'));
        $registry->register('term.delete', array($this, 'termDelete'), array('mutation' => true, 'privileged' => true, 'description' => 'Delete a taxonomy term.'));
        $registry->register('term.assign', array($this, 'termAssign'), array('mutation' => true, 'description' => 'Assign taxonomy terms to an object.'));

        $registry->register('menu.list', array($this, 'menuList'), array('description' => 'List classic navigation menus and locations.'));
        $registry->register('menu.get', array($this, 'menuGet'), array('description' => 'Read a classic navigation menu and items.'));
        $registry->register('menu.create', array($this, 'menuCreate'), array('mutation' => true, 'description' => 'Create a classic navigation menu.'));
        $registry->register('menu.update', array($this, 'menuUpdate'), array('mutation' => true, 'description' => 'Rename a classic navigation menu.'));
        $registry->register('menu.delete', array($this, 'menuDelete'), array('mutation' => true, 'privileged' => true, 'description' => 'Delete a classic navigation menu.'));
        $registry->register('menu.item_upsert', array($this, 'menuItemUpsert'), array('mutation' => true, 'description' => 'Create or update a classic navigation menu item.'));
        $registry->register('menu.location_set', array($this, 'menuLocationSet'), array('mutation' => true, 'description' => 'Assign a classic menu to a theme location.'));

        $registry->register('option.get', array($this, 'optionGet'), array('privileged' => true, 'description' => 'Read a WordPress option.'));
        $registry->register('option.update', array($this, 'optionUpdate'), array('mutation' => true, 'privileged' => true, 'description' => 'Update a WordPress option.'));
        $registry->register('option.delete', array($this, 'optionDelete'), array('mutation' => true, 'privileged' => true, 'description' => 'Delete a WordPress option.'));
        $registry->register('network_option.get', array($this, 'networkOptionGet'), array('privileged' => true, 'description' => 'Read a multisite network option.'));
        $registry->register('network_option.update', array($this, 'networkOptionUpdate'), array('mutation' => true, 'privileged' => true, 'description' => 'Update a multisite network option.'));
        $registry->register('network_option.delete', array($this, 'networkOptionDelete'), array('mutation' => true, 'privileged' => true, 'description' => 'Delete a multisite network option.'));
        $registry->register('theme_mod.get', array($this, 'themeModGet'), array('privileged' => true, 'description' => 'Read a theme modification.'));
        $registry->register('theme_mod.update', array($this, 'themeModUpdate'), array('mutation' => true, 'privileged' => true, 'description' => 'Update a theme modification.'));
        $registry->register('theme_mod.remove', array($this, 'themeModRemove'), array('mutation' => true, 'privileged' => true, 'description' => 'Remove a theme modification.'));

        $registry->register('comment.list', array($this, 'commentList'), array('privileged' => true, 'sensitive' => true, 'description' => 'List comments including private author metadata.'));
        $registry->register('comment.get', array($this, 'commentGet'), array('privileged' => true, 'sensitive' => true, 'description' => 'Read one comment.'));
        $registry->register('comment.update', array($this, 'commentUpdate'), array('mutation' => true, 'privileged' => true, 'sensitive' => true, 'description' => 'Update a comment.'));
        $registry->register('comment.trash', array($this, 'commentTrash'), array('mutation' => true, 'privileged' => true, 'sensitive' => true, 'description' => 'Trash a comment.'));
    }

    public function postList(array $payload): array
    {
        $postType = isset($payload['post_type']) ? $payload['post_type'] : 'post';
        $status = isset($payload['status']) ? $payload['status'] : (Policy::publicRepositoryContext() ? 'publish' : 'any');
        $perPage = isset($payload['per_page']) ? max(1, min(100, (int) $payload['per_page'])) : 50;
        $page = isset($payload['page']) ? max(1, (int) $payload['page']) : 1;

        $query = new \WP_Query(array(
            'post_type' => $postType,
            'post_status' => $status,
            's' => isset($payload['search']) ? sanitize_text_field((string) $payload['search']) : '',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => isset($payload['orderby']) ? sanitize_key((string) $payload['orderby']) : 'ID',
            'order' => isset($payload['order']) && 'ASC' === strtoupper((string) $payload['order']) ? 'ASC' : 'DESC',
        ));

        $items = array();
        foreach ($query->posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }
            try {
                Policy::assertPostReadable($post);
                $items[] = $this->postSnapshot($post);
            } catch (RuntimeException $error) {
                continue;
            }
        }

        return array(
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
        );
    }

    public function postGet(array $payload): array
    {
        $post = $this->requirePost($payload);
        Policy::assertPostReadable($post);
        return array('post' => $this->postSnapshot($post), 'fingerprint' => Fingerprint::make($this->postSnapshot($post)));
    }

    public function postCreate(array $payload, array $context): array
    {
        $fields = $this->sanitizePostFields($payload, false);
        if (empty($fields['post_type'])) {
            $fields['post_type'] = 'post';
        }

        if (! post_type_exists($fields['post_type'])) {
            throw new RuntimeException('Unknown post type: ' . $fields['post_type']);
        }
        Policy::assertReadablePostType($fields['post_type']);

        if (! empty($context['dry_run'])) {
            return array('would_create' => $fields, '_current_fingerprint' => Fingerprint::make(array('new' => true, 'post_type' => $fields['post_type'])));
        }

        $id = wp_insert_post(wp_slash($fields), true);
        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }

        $post = get_post((int) $id);
        return array(
            'post' => $this->postSnapshot($post),
            '_rollback' => array('action' => 'post.trash', 'payload' => array('id' => (int) $id)),
        );
    }

    public function postUpdate(array $payload, array $context): array
    {
        $post = $this->requirePost($payload);
        Policy::assertReadablePostType((string) $post->post_type);
        $before = $this->postSnapshot($post);
        $fields = $this->sanitizePostFields($payload, true);
        $fields['ID'] = (int) $post->ID;

        $after = array_merge($before, $this->snapshotFromUpdateFields($fields));
        $result = array(
            'before' => $before,
            'after' => $after,
            '_current_fingerprint' => Fingerprint::make($before),
        );

        if (! empty($context['dry_run'])) {
            return $result;
        }

        $updated = wp_update_post(wp_slash($fields), true);
        if (is_wp_error($updated)) {
            throw new RuntimeException($updated->get_error_message());
        }

        $result['after'] = $this->postSnapshot(get_post((int) $updated));
        $result['_rollback'] = array('action' => 'post.update', 'payload' => $this->postRollbackPayload($before));
        return $result;
    }

    public function postTrash(array $payload, array $context): array
    {
        $post = $this->requirePost($payload);
        $before = $this->postSnapshot($post);
        $result = array('before' => $before, 'would_status' => 'trash', '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) {
            return $result;
        }

        $trashed = wp_trash_post((int) $post->ID);
        if (! $trashed) {
            throw new RuntimeException('Could not trash post.');
        }
        $result['post'] = $this->postSnapshot(get_post((int) $post->ID));
        $result['_rollback'] = array('action' => 'post.restore', 'payload' => array('id' => (int) $post->ID));
        return $result;
    }

    public function postRestore(array $payload, array $context): array
    {
        $post = $this->requirePost($payload);
        $before = $this->postSnapshot($post);
        $result = array('before' => $before, '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) {
            $result['would_restore'] = true;
            return $result;
        }

        $restored = wp_untrash_post((int) $post->ID);
        if (! $restored) {
            throw new RuntimeException('Could not restore post.');
        }
        $result['post'] = $this->postSnapshot(get_post((int) $post->ID));
        $result['_rollback'] = array('action' => 'post.trash', 'payload' => array('id' => (int) $post->ID));
        return $result;
    }

    public function postRevisions(array $payload): array
    {
        $post = $this->requirePost($payload);
        Policy::assertPostReadable($post);
        $items = array();
        foreach (wp_get_post_revisions((int) $post->ID) as $revision) {
            $items[] = $this->postSnapshot($revision);
        }
        return array('revisions' => $items);
    }

    public function postRestoreRevision(array $payload, array $context): array
    {
        $revisionId = isset($payload['revision_id']) ? (int) $payload['revision_id'] : 0;
        $revision = wp_get_post_revision($revisionId);
        if (! $revision) {
            throw new RuntimeException('Revision not found.');
        }
        $post = get_post((int) $revision->post_parent);
        if (! $post instanceof \WP_Post) {
            throw new RuntimeException('Revision parent not found.');
        }
        $before = $this->postSnapshot($post);
        $result = array('before' => $before, 'revision' => $this->postSnapshot($revision), '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) {
            return $result;
        }
        $restored = wp_restore_post_revision($revisionId);
        if (! $restored) {
            throw new RuntimeException('Could not restore revision.');
        }
        $result['after'] = $this->postSnapshot(get_post((int) $post->ID));
        $result['_rollback'] = array('action' => 'post.update', 'payload' => $this->postRollbackPayload($before));
        return $result;
    }

    public function metaGet(array $payload): array
    {
        list($type, $id, $key) = $this->metaTarget($payload);
        Policy::assertMetaKeyAllowed($key);
        return array('value' => get_metadata($type, $id, $key, true));
    }

    public function metaUpdate(array $payload, array $context): array
    {
        list($type, $id, $key) = $this->metaTarget($payload);
        Policy::assertMetaKeyAllowed($key);
        $before = get_metadata($type, $id, $key, true);
        $after = array_key_exists('value', $payload) ? $payload['value'] : null;
        $result = array('before' => $before, 'after' => $after, '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) {
            return $result;
        }
        if (false === update_metadata($type, $id, $key, $after)) {
            $current = get_metadata($type, $id, $key, true);
            if ($current !== $after) {
                throw new RuntimeException('Metadata update failed.');
            }
        }
        $result['_rollback'] = array('action' => 'meta.update', 'payload' => array('object_type' => $type, 'object_id' => $id, 'key' => $key, 'value' => $before));
        return $result;
    }

    public function metaDelete(array $payload, array $context): array
    {
        list($type, $id, $key) = $this->metaTarget($payload);
        Policy::assertMetaKeyAllowed($key);
        $before = get_metadata($type, $id, $key, true);
        $result = array('before' => $before, '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) {
            return $result;
        }
        delete_metadata($type, $id, $key);
        $result['_rollback'] = array('action' => 'meta.update', 'payload' => array('object_type' => $type, 'object_id' => $id, 'key' => $key, 'value' => $before));
        return $result;
    }

    public function termList(array $payload): array
    {
        $taxonomy = isset($payload['taxonomy']) ? sanitize_key((string) $payload['taxonomy']) : 'category';
        if (! taxonomy_exists($taxonomy)) {
            throw new RuntimeException('Unknown taxonomy: ' . $taxonomy);
        }
        $taxonomyObject = get_taxonomy($taxonomy);
        if (Policy::publicRepositoryContext() && (! $taxonomyObject || ! $taxonomyObject->public)) {
            throw new RuntimeException('Non-public taxonomies cannot be exported in public-repository mode.');
        }
        $args = array(
            'taxonomy' => $taxonomy,
            'hide_empty' => Input::bool($payload, 'hide_empty'),
            'number' => isset($payload['per_page']) ? max(1, min(200, (int) $payload['per_page'])) : 100,
            'offset' => isset($payload['offset']) ? max(0, (int) $payload['offset']) : 0,
            'search' => isset($payload['search']) ? sanitize_text_field((string) $payload['search']) : '',
        );
        $terms = get_terms($args);
        if (is_wp_error($terms)) {
            throw new RuntimeException($terms->get_error_message());
        }
        return array('terms' => array_map(array($this, 'termSnapshot'), $terms));
    }

    public function termGet(array $payload): array
    {
        $taxonomy = isset($payload['taxonomy']) ? sanitize_key((string) $payload['taxonomy']) : '';
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $term = get_term($id, $taxonomy ?: '');
        if (! $term instanceof \WP_Term) {
            throw new RuntimeException('Term not found.');
        }
        $taxonomyObject = get_taxonomy((string) $term->taxonomy);
        if (Policy::publicRepositoryContext() && (! $taxonomyObject || ! $taxonomyObject->public)) {
            throw new RuntimeException('Non-public taxonomies cannot be exported in public-repository mode.');
        }
        $snapshot = $this->termSnapshot($term);
        return array('term' => $snapshot, 'fingerprint' => Fingerprint::make($snapshot));
    }

    public function termCreate(array $payload, array $context): array
    {
        $taxonomy = isset($payload['taxonomy']) ? sanitize_key((string) $payload['taxonomy']) : '';
        $name = isset($payload['name']) ? sanitize_text_field((string) $payload['name']) : '';
        if (! taxonomy_exists($taxonomy) || '' === $name) {
            throw new RuntimeException('taxonomy and name are required.');
        }
        $args = array();
        foreach (array('slug', 'description') as $field) {
            if (isset($payload[$field])) {
                $args[$field] = 'slug' === $field ? sanitize_title((string) $payload[$field]) : sanitize_textarea_field((string) $payload[$field]);
            }
        }
        if (isset($payload['parent'])) {
            $args['parent'] = (int) $payload['parent'];
        }
        if (! empty($context['dry_run'])) {
            return array('would_create' => array('taxonomy' => $taxonomy, 'name' => $name, 'args' => $args), '_current_fingerprint' => Fingerprint::make(array('new' => true, 'taxonomy' => $taxonomy)));
        }
        $created = wp_insert_term($name, $taxonomy, $args);
        if (is_wp_error($created)) {
            throw new RuntimeException($created->get_error_message());
        }
        $id = (int) $created['term_id'];
        return array('term' => $this->termSnapshot(get_term($id, $taxonomy)), '_rollback' => array('action' => 'term.delete', 'payload' => array('taxonomy' => $taxonomy, 'id' => $id)));
    }

    public function termUpdate(array $payload, array $context): array
    {
        $taxonomy = isset($payload['taxonomy']) ? sanitize_key((string) $payload['taxonomy']) : '';
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $term = get_term($id, $taxonomy);
        if (! $term instanceof \WP_Term) {
            throw new RuntimeException('Term not found.');
        }
        $before = $this->termSnapshot($term);
        $args = array();
        if (isset($payload['name'])) $args['name'] = sanitize_text_field((string) $payload['name']);
        if (isset($payload['slug'])) $args['slug'] = sanitize_title((string) $payload['slug']);
        if (isset($payload['description'])) $args['description'] = sanitize_textarea_field((string) $payload['description']);
        if (isset($payload['parent'])) $args['parent'] = (int) $payload['parent'];
        $result = array('before' => $before, 'changes' => $args, '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) return $result;
        $updated = wp_update_term($id, $taxonomy, $args);
        if (is_wp_error($updated)) throw new RuntimeException($updated->get_error_message());
        $result['after'] = $this->termSnapshot(get_term($id, $taxonomy));
        $result['_rollback'] = array('action' => 'term.update', 'payload' => array_merge(array('taxonomy' => $taxonomy, 'id' => $id), $before));
        return $result;
    }

    public function termDelete(array $payload, array $context): array
    {
        $taxonomy = isset($payload['taxonomy']) ? sanitize_key((string) $payload['taxonomy']) : '';
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $term = get_term($id, $taxonomy);
        if (! $term instanceof \WP_Term) throw new RuntimeException('Term not found.');
        $before = $this->termSnapshot($term);
        $result = array('before' => $before, '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) return $result;
        $deleted = wp_delete_term($id, $taxonomy);
        if (is_wp_error($deleted) || false === $deleted) throw new RuntimeException(is_wp_error($deleted) ? $deleted->get_error_message() : 'Term delete failed.');
        $result['_rollback'] = array('action' => 'term.create', 'payload' => array_merge(array('taxonomy' => $taxonomy), $before));
        return $result;
    }

    public function termAssign(array $payload, array $context): array
    {
        $objectId = isset($payload['object_id']) ? (int) $payload['object_id'] : 0;
        $taxonomy = isset($payload['taxonomy']) ? sanitize_key((string) $payload['taxonomy']) : '';
        $terms = isset($payload['terms']) && is_array($payload['terms']) ? $payload['terms'] : array();
        $append = Input::bool($payload, 'append');
        if (! $objectId || ! taxonomy_exists($taxonomy)) throw new RuntimeException('object_id and valid taxonomy are required.');
        $before = wp_get_object_terms($objectId, $taxonomy, array('fields' => 'ids'));
        if (is_wp_error($before)) throw new RuntimeException($before->get_error_message());
        $termIds = array_map('intval', $terms);
        $result = array('before' => $before, 'after' => $append ? array_values(array_unique(array_merge($before, $termIds))) : $termIds, '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) return $result;
        $assigned = wp_set_object_terms($objectId, $termIds, $taxonomy, $append);
        if (is_wp_error($assigned)) throw new RuntimeException($assigned->get_error_message());
        $result['_rollback'] = array('action' => 'term.assign', 'payload' => array('object_id' => $objectId, 'taxonomy' => $taxonomy, 'terms' => array_map('intval', $before), 'append' => false));
        return $result;
    }

    public function menuList(): array
    {
        $menus = array();
        foreach (wp_get_nav_menus() as $menu) {
            $menus[] = array('term_id' => (int) $menu->term_id, 'name' => (string) $menu->name, 'slug' => (string) $menu->slug, 'count' => (int) $menu->count);
        }
        return array('menus' => $menus, 'locations' => get_nav_menu_locations(), 'registered_locations' => get_registered_nav_menus());
    }

    public function menuGet(array $payload): array
    {
        $menu = wp_get_nav_menu_object($payload['id'] ?? ($payload['name'] ?? 0));
        if (! $menu) throw new RuntimeException('Menu not found.');
        $items = array();
        foreach ((array) wp_get_nav_menu_items($menu->term_id) as $item) {
            $items[] = array(
                'ID' => (int) $item->ID,
                'title' => (string) $item->title,
                'url' => (string) $item->url,
                'menu_item_parent' => (int) $item->menu_item_parent,
                'object' => (string) $item->object,
                'object_id' => (int) $item->object_id,
                'type' => (string) $item->type,
                'target' => (string) $item->target,
                'classes' => array_values(array_filter((array) $item->classes)),
                'description' => (string) $item->description,
            );
        }
        return array('menu' => array('term_id' => (int) $menu->term_id, 'name' => (string) $menu->name, 'slug' => (string) $menu->slug), 'items' => $items);
    }

    public function menuCreate(array $payload, array $context): array
    {
        $name = isset($payload['name']) ? sanitize_text_field((string) $payload['name']) : '';
        if ('' === $name) throw new RuntimeException('name is required.');
        if (! empty($context['dry_run'])) return array('would_create' => $name, '_current_fingerprint' => Fingerprint::make(array('new_menu' => $name)));
        $id = wp_create_nav_menu($name);
        if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
        return array('menu_id' => (int) $id, '_rollback' => array('action' => 'menu.delete', 'payload' => array('id' => (int) $id)));
    }

    public function menuUpdate(array $payload, array $context): array
    {
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $menu = wp_get_nav_menu_object($id);
        if (! $menu) throw new RuntimeException('Menu not found.');
        $name = isset($payload['name']) ? sanitize_text_field((string) $payload['name']) : (string) $menu->name;
        $before = array('id' => $id, 'name' => (string) $menu->name);
        if (! empty($context['dry_run'])) return array('before' => $before, 'after' => array('id' => $id, 'name' => $name), '_current_fingerprint' => Fingerprint::make($before));
        $updated = wp_update_nav_menu_object($id, array('menu-name' => $name));
        if (is_wp_error($updated)) throw new RuntimeException($updated->get_error_message());
        return array('menu_id' => (int) $updated, '_rollback' => array('action' => 'menu.update', 'payload' => $before));
    }

    public function menuDelete(array $payload, array $context): array
    {
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $snapshot = $this->menuGet(array('id' => $id));
        if (! empty($context['dry_run'])) return array('before' => $snapshot, '_current_fingerprint' => Fingerprint::make($snapshot));
        $deleted = wp_delete_nav_menu($id);
        if (is_wp_error($deleted)) throw new RuntimeException($deleted->get_error_message());
        return array('deleted' => $id, 'rollback_supported' => false);
    }

    public function menuItemUpsert(array $payload, array $context): array
    {
        $menuId = isset($payload['menu_id']) ? (int) $payload['menu_id'] : 0;
        $itemId = isset($payload['item_id']) ? (int) $payload['item_id'] : 0;
        if (! wp_get_nav_menu_object($menuId)) throw new RuntimeException('Menu not found.');
        $before = $itemId ? get_post($itemId) : null;
        $args = array();
        $map = array(
            'title' => 'menu-item-title', 'url' => 'menu-item-url', 'description' => 'menu-item-description',
            'attr_title' => 'menu-item-attr-title', 'target' => 'menu-item-target', 'xfn' => 'menu-item-xfn',
            'status' => 'menu-item-status', 'type' => 'menu-item-type', 'object' => 'menu-item-object',
        );
        foreach ($map as $source => $target) {
            if (isset($payload[$source])) $args[$target] = sanitize_text_field((string) $payload[$source]);
        }
        if (isset($payload['object_id'])) $args['menu-item-object-id'] = (int) $payload['object_id'];
        if (isset($payload['parent'])) $args['menu-item-parent-id'] = (int) $payload['parent'];
        if (isset($payload['position'])) $args['menu-item-position'] = (int) $payload['position'];
        if (! empty($context['dry_run'])) return array('would_upsert' => array('menu_id' => $menuId, 'item_id' => $itemId, 'args' => $args), '_current_fingerprint' => Fingerprint::make($before ? get_post_meta($itemId) : array('new' => true)));
        $updated = wp_update_nav_menu_item($menuId, $itemId, $args);
        if (is_wp_error($updated)) throw new RuntimeException($updated->get_error_message());
        return array('item_id' => (int) $updated, 'rollback_supported' => false);
    }

    public function menuLocationSet(array $payload, array $context): array
    {
        $location = isset($payload['location']) ? sanitize_key((string) $payload['location']) : '';
        $menuId = isset($payload['menu_id']) ? (int) $payload['menu_id'] : 0;
        $registered = get_registered_nav_menus();
        if (! isset($registered[$location])) throw new RuntimeException('Unknown menu location.');
        $locations = get_nav_menu_locations();
        $before = isset($locations[$location]) ? (int) $locations[$location] : 0;
        if (! empty($context['dry_run'])) return array('before' => $before, 'after' => $menuId, '_current_fingerprint' => Fingerprint::make($locations));
        $locations[$location] = $menuId;
        set_theme_mod('nav_menu_locations', $locations);
        return array('location' => $location, 'menu_id' => $menuId, '_rollback' => array('action' => 'menu.location_set', 'payload' => array('location' => $location, 'menu_id' => $before)));
    }

    public function optionGet(array $payload): array
    {
        $name = $this->requireKey($payload, 'name');
        Policy::assertOptionKeyAllowed($name);
        return array('name' => $name, 'value' => get_option($name, null));
    }

    public function optionUpdate(array $payload, array $context): array
    {
        $name = $this->requireKey($payload, 'name');
        Policy::assertOptionKeyAllowed($name);
        $before = get_option($name, null);
        $value = $payload['value'] ?? null;
        if (! empty($context['dry_run'])) return array('before' => $before, 'after' => $value, '_current_fingerprint' => Fingerprint::make($before));
        update_option($name, $value, array_key_exists('autoload', $payload) ? Input::bool($payload, 'autoload') : null);
        return array('name' => $name, 'value' => get_option($name, null), '_rollback' => array('action' => 'option.update', 'payload' => array('name' => $name, 'value' => $before)));
    }

    public function optionDelete(array $payload, array $context): array
    {
        $name = $this->requireKey($payload, 'name');
        Policy::assertOptionKeyAllowed($name);
        $before = get_option($name, null);
        if (! empty($context['dry_run'])) return array('before' => $before, '_current_fingerprint' => Fingerprint::make($before));
        delete_option($name);
        return array('deleted' => $name, '_rollback' => array('action' => 'option.update', 'payload' => array('name' => $name, 'value' => $before)));
    }

    public function networkOptionGet(array $payload): array
    {
        if (! is_multisite()) throw new RuntimeException('Not a multisite installation.');
        $name = $this->requireKey($payload, 'name');
        Policy::assertOptionKeyAllowed($name);
        return array('name' => $name, 'value' => get_site_option($name, null));
    }

    public function networkOptionUpdate(array $payload, array $context): array
    {
        if (! is_multisite()) throw new RuntimeException('Not a multisite installation.');
        $name = $this->requireKey($payload, 'name');
        Policy::assertOptionKeyAllowed($name);
        $before = get_site_option($name, null);
        $value = $payload['value'] ?? null;
        if (! empty($context['dry_run'])) return array('before' => $before, 'after' => $value, '_current_fingerprint' => Fingerprint::make($before));
        update_site_option($name, $value);
        return array('name' => $name, 'value' => get_site_option($name, null), '_rollback' => array('action' => 'network_option.update', 'payload' => array('name' => $name, 'value' => $before)));
    }

    public function networkOptionDelete(array $payload, array $context): array
    {
        if (! is_multisite()) throw new RuntimeException('Not a multisite installation.');
        $name = $this->requireKey($payload, 'name');
        Policy::assertOptionKeyAllowed($name);
        $before = get_site_option($name, null);
        if (! empty($context['dry_run'])) return array('before' => $before, '_current_fingerprint' => Fingerprint::make($before));
        delete_site_option($name);
        return array('deleted' => $name, '_rollback' => array('action' => 'network_option.update', 'payload' => array('name' => $name, 'value' => $before)));
    }

    public function themeModGet(array $payload): array
    {
        $name = $this->requireKey($payload, 'name');
        Policy::assertKeyAllowed($name);
        return array('name' => $name, 'value' => get_theme_mod($name, null));
    }

    public function themeModUpdate(array $payload, array $context): array
    {
        $name = $this->requireKey($payload, 'name');
        Policy::assertKeyAllowed($name);
        $before = get_theme_mod($name, null);
        $value = $payload['value'] ?? null;
        if (! empty($context['dry_run'])) return array('before' => $before, 'after' => $value, '_current_fingerprint' => Fingerprint::make($before));
        set_theme_mod($name, $value);
        return array('name' => $name, 'value' => get_theme_mod($name, null), '_rollback' => array('action' => 'theme_mod.update', 'payload' => array('name' => $name, 'value' => $before)));
    }

    public function themeModRemove(array $payload, array $context): array
    {
        $name = $this->requireKey($payload, 'name');
        Policy::assertKeyAllowed($name);
        $before = get_theme_mod($name, null);
        if (! empty($context['dry_run'])) return array('before' => $before, '_current_fingerprint' => Fingerprint::make($before));
        remove_theme_mod($name);
        return array('removed' => $name, '_rollback' => array('action' => 'theme_mod.update', 'payload' => array('name' => $name, 'value' => $before)));
    }

    public function commentList(array $payload): array
    {
        $comments = get_comments(array(
            'post_id' => isset($payload['post_id']) ? (int) $payload['post_id'] : 0,
            'status' => isset($payload['status']) ? sanitize_key((string) $payload['status']) : 'all',
            'number' => isset($payload['per_page']) ? max(1, min(100, (int) $payload['per_page'])) : 50,
            'offset' => isset($payload['offset']) ? max(0, (int) $payload['offset']) : 0,
        ));
        return array('comments' => array_map(array($this, 'commentSnapshot'), $comments));
    }

    public function commentGet(array $payload): array
    {
        $comment = get_comment(isset($payload['id']) ? (int) $payload['id'] : 0);
        if (! $comment instanceof \WP_Comment) throw new RuntimeException('Comment not found.');
        return array('comment' => $this->commentSnapshot($comment));
    }

    public function commentUpdate(array $payload, array $context): array
    {
        $comment = get_comment(isset($payload['id']) ? (int) $payload['id'] : 0);
        if (! $comment instanceof \WP_Comment) throw new RuntimeException('Comment not found.');
        $before = $this->commentSnapshot($comment);
        $fields = array('comment_ID' => (int) $comment->comment_ID);
        $map = array('content' => 'comment_content', 'author' => 'comment_author', 'author_email' => 'comment_author_email', 'author_url' => 'comment_author_url', 'approved' => 'comment_approved');
        foreach ($map as $source => $target) {
            if (isset($payload[$source])) $fields[$target] = (string) $payload[$source];
        }
        if (! empty($context['dry_run'])) return array('before' => $before, 'changes' => $fields, '_current_fingerprint' => Fingerprint::make($before));
        $updated = wp_update_comment(wp_slash($fields), true);
        if (is_wp_error($updated)) throw new RuntimeException($updated->get_error_message());
        return array('comment' => $this->commentSnapshot(get_comment((int) $comment->comment_ID)), '_rollback' => array('action' => 'comment.update', 'payload' => array('id' => (int) $comment->comment_ID, 'content' => $before['content'], 'author' => $before['author'], 'author_email' => $before['author_email'], 'author_url' => $before['author_url'], 'approved' => $before['approved'])));
    }

    public function commentTrash(array $payload, array $context): array
    {
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $comment = get_comment($id);
        if (! $comment instanceof \WP_Comment) throw new RuntimeException('Comment not found.');
        $before = $this->commentSnapshot($comment);
        if (! empty($context['dry_run'])) return array('before' => $before, '_current_fingerprint' => Fingerprint::make($before));
        if (! wp_trash_comment($id)) throw new RuntimeException('Could not trash comment.');
        return array('trashed' => $id, 'rollback_supported' => false);
    }

    private function requirePost(array $payload): \WP_Post
    {
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $post = get_post($id);
        if (! $post instanceof \WP_Post) throw new RuntimeException('Post not found.');
        return $post;
    }

    private function sanitizePostFields(array $payload, bool $partial): array
    {
        $fields = array();
        $stringMap = array(
            'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status',
            'slug' => 'post_name', 'type' => 'post_type', 'password' => 'post_password', 'comment_status' => 'comment_status',
            'ping_status' => 'ping_status', 'date' => 'post_date', 'date_gmt' => 'post_date_gmt',
        );
        foreach ($stringMap as $source => $target) {
            if (array_key_exists($source, $payload)) $fields[$target] = (string) $payload[$source];
        }
        if (isset($payload['author'])) $fields['post_author'] = (int) $payload['author'];
        if (isset($payload['parent'])) $fields['post_parent'] = (int) $payload['parent'];
        if (isset($payload['menu_order'])) $fields['menu_order'] = (int) $payload['menu_order'];
        if (! $partial && ! isset($fields['post_status'])) $fields['post_status'] = 'draft';
        return $fields;
    }

    private function postSnapshot(\WP_Post $post): array
    {
        return array(
            'id' => (int) $post->ID,
            'type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'title' => (string) $post->post_title,
            'slug' => (string) $post->post_name,
            'content' => (string) $post->post_content,
            'excerpt' => (string) $post->post_excerpt,
            'author' => (int) $post->post_author,
            'parent' => (int) $post->post_parent,
            'menu_order' => (int) $post->menu_order,
            'date' => (string) $post->post_date,
            'date_gmt' => (string) $post->post_date_gmt,
            'modified' => (string) $post->post_modified,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'comment_status' => (string) $post->comment_status,
            'ping_status' => (string) $post->ping_status,
            'password_protected' => '' !== (string) $post->post_password,
            'featured_media' => (int) get_post_thumbnail_id((int) $post->ID),
            'permalink' => get_permalink((int) $post->ID),
        );
    }

    private function postRollbackPayload(array $snapshot): array
    {
        return array(
            'id' => $snapshot['id'], 'title' => $snapshot['title'], 'content' => $snapshot['content'], 'excerpt' => $snapshot['excerpt'],
            'status' => $snapshot['status'], 'slug' => $snapshot['slug'], 'author' => $snapshot['author'], 'parent' => $snapshot['parent'],
            'menu_order' => $snapshot['menu_order'], 'comment_status' => $snapshot['comment_status'], 'ping_status' => $snapshot['ping_status'],
        );
    }

    private function snapshotFromUpdateFields(array $fields): array
    {
        $reverse = array('post_title' => 'title', 'post_content' => 'content', 'post_excerpt' => 'excerpt', 'post_status' => 'status', 'post_name' => 'slug', 'post_author' => 'author', 'post_parent' => 'parent', 'menu_order' => 'menu_order', 'comment_status' => 'comment_status', 'ping_status' => 'ping_status');
        $result = array();
        foreach ($reverse as $field => $key) {
            if (array_key_exists($field, $fields)) $result[$key] = $fields[$field];
        }
        return $result;
    }

    private function metaTarget(array $payload): array
    {
        $type = isset($payload['object_type']) ? sanitize_key((string) $payload['object_type']) : '';
        if (! in_array($type, array('post', 'term', 'user', 'comment'), true)) throw new RuntimeException('object_type must be post, term, user or comment.');
        $id = isset($payload['object_id']) ? (int) $payload['object_id'] : 0;
        $key = $this->requireKey($payload, 'key');
        if (! $id) throw new RuntimeException('object_id is required.');
        return array($type, $id, $key);
    }

    private function termSnapshot(\WP_Term $term): array
    {
        return array('id' => (int) $term->term_id, 'taxonomy' => (string) $term->taxonomy, 'name' => (string) $term->name, 'slug' => (string) $term->slug, 'description' => (string) $term->description, 'parent' => (int) $term->parent, 'count' => (int) $term->count);
    }

    private function commentSnapshot(\WP_Comment $comment): array
    {
        return array(
            'id' => (int) $comment->comment_ID, 'post_id' => (int) $comment->comment_post_ID, 'author' => (string) $comment->comment_author,
            'author_email' => (string) $comment->comment_author_email, 'author_url' => (string) $comment->comment_author_url,
            'author_ip' => (string) $comment->comment_author_IP, 'date' => (string) $comment->comment_date, 'content' => (string) $comment->comment_content,
            'approved' => (string) $comment->comment_approved, 'type' => (string) $comment->comment_type, 'parent' => (int) $comment->comment_parent, 'user_id' => (int) $comment->user_id,
        );
    }

    private function requireKey(array $payload, string $field): string
    {
        $value = isset($payload[$field]) ? (string) $payload[$field] : '';
        if ('' === $value || strlen($value) > 191) throw new RuntimeException($field . ' is required and must be <= 191 characters.');
        return $value;
    }
}
