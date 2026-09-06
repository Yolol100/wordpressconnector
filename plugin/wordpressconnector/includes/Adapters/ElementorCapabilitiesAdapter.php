<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;

final class ElementorCapabilitiesAdapter
{
    public function register(Registry $registry): void
    {
        $registry->register('elementor.capabilities', array($this, 'capabilities'), array(
            'description' => 'Inspect active Elementor document types, widgets, elements, dynamic tags and responsive capabilities.',
        ));
    }

    public function capabilities(array $payload, array $context): array
    {
        $environment = array(
            'elementor' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
            'elementor_pro' => defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : null,
            'theme' => (string) wp_get_theme()->get('Name'),
            'theme_version' => (string) wp_get_theme()->get('Version'),
        );

        if (! class_exists('Elementor\\Plugin')) {
            return array(
                'available' => false,
                'environment' => $environment,
                'document_types' => array(),
                'widgets' => array(),
                'elements' => array(),
                'dynamic_tags' => array(),
                'breakpoints' => array(),
                'warnings' => array('elementor_not_active'),
            );
        }

        $elementor = \Elementor\Plugin::$instance;
        $warnings = array();

        return array(
            'available' => true,
            'environment' => $environment,
            'document_types' => $this->documentTypes($elementor, $warnings),
            'widgets' => $this->widgets($elementor, $warnings),
            'elements' => $this->elements($elementor, $warnings),
            'dynamic_tags' => $this->dynamicTags($elementor, $warnings),
            'breakpoints' => $this->breakpoints($elementor, $warnings),
            'warnings' => array_values(array_unique($warnings)),
        );
    }

    private function documentTypes(object $elementor, array &$warnings): array
    {
        if (! isset($elementor->documents) || ! is_object($elementor->documents) || ! method_exists($elementor->documents, 'get_document_types')) {
            $warnings[] = 'documents_manager_unavailable';
            return array();
        }

        try {
            $types = $elementor->documents->get_document_types();
        } catch (Throwable $error) {
            $warnings[] = 'document_types_inventory_failed';
            return array();
        }

        $result = array();
        foreach ((array) $types as $type => $className) {
            if (! is_string($className) || ! class_exists($className)) {
                continue;
            }

            try {
                $properties = method_exists($className, 'get_properties') ? (array) $className::get_properties() : array();
                $result[(string) $type] = array(
                    'title' => method_exists($className, 'get_title') ? wp_strip_all_tags((string) $className::get_title()) : (string) $type,
                    'cpt' => isset($properties['cpt']) ? array_values(array_map('strval', (array) $properties['cpt'])) : array(),
                    'show_in_library' => isset($properties['show_in_library']) ? (bool) $properties['show_in_library'] : null,
                    'is_editable' => isset($properties['is_editable']) ? (bool) $properties['is_editable'] : null,
                );
            } catch (Throwable $error) {
                $warnings[] = 'document_type_skipped:' . sanitize_key((string) $type);
            }
        }

        ksort($result, SORT_STRING);
        return $result;
    }

    private function widgets(object $elementor, array &$warnings): array
    {
        if (! isset($elementor->widgets_manager) || ! is_object($elementor->widgets_manager) || ! method_exists($elementor->widgets_manager, 'get_widget_types')) {
            $warnings[] = 'widgets_manager_unavailable';
            return array();
        }

        try {
            $widgets = $elementor->widgets_manager->get_widget_types();
        } catch (Throwable $error) {
            $warnings[] = 'widgets_inventory_failed';
            return array();
        }

        $result = array();
        foreach ((array) $widgets as $name => $widget) {
            if (! is_object($widget)) {
                continue;
            }
            try {
                $controls = method_exists($widget, 'get_controls') ? (array) $widget->get_controls() : array();
                $result[(string) $name] = array(
                    'name' => method_exists($widget, 'get_name') ? (string) $widget->get_name() : (string) $name,
                    'title' => method_exists($widget, 'get_title') ? wp_strip_all_tags((string) $widget->get_title()) : (string) $name,
                    'categories' => method_exists($widget, 'get_categories') ? array_values(array_map('strval', (array) $widget->get_categories())) : array(),
                    'control_count' => count($controls),
                );
            } catch (Throwable $error) {
                $warnings[] = 'widget_skipped:' . sanitize_key((string) $name);
            }
        }

        ksort($result, SORT_STRING);
        return $result;
    }

    private function elements(object $elementor, array &$warnings): array
    {
        if (! isset($elementor->elements_manager) || ! is_object($elementor->elements_manager) || ! method_exists($elementor->elements_manager, 'get_element_types')) {
            $warnings[] = 'elements_manager_unavailable';
            return array();
        }

        try {
            $elements = $elementor->elements_manager->get_element_types();
        } catch (Throwable $error) {
            $warnings[] = 'elements_inventory_failed';
            return array();
        }

        $result = array();
        foreach ((array) $elements as $name => $element) {
            if (! is_object($element)) {
                continue;
            }
            try {
                $result[(string) $name] = array(
                    'name' => method_exists($element, 'get_name') ? (string) $element->get_name() : (string) $name,
                    'title' => method_exists($element, 'get_title') ? wp_strip_all_tags((string) $element->get_title()) : (string) $name,
                    'categories' => method_exists($element, 'get_categories') ? array_values(array_map('strval', (array) $element->get_categories())) : array(),
                );
            } catch (Throwable $error) {
                $warnings[] = 'element_skipped:' . sanitize_key((string) $name);
            }
        }

        ksort($result, SORT_STRING);
        return $result;
    }

    private function dynamicTags(object $elementor, array &$warnings): array
    {
        if (! isset($elementor->dynamic_tags) || ! is_object($elementor->dynamic_tags) || ! method_exists($elementor->dynamic_tags, 'get_tags')) {
            return array();
        }

        try {
            $tags = $elementor->dynamic_tags->get_tags();
        } catch (Throwable $error) {
            $warnings[] = 'dynamic_tags_inventory_failed';
            return array();
        }

        $result = array();
        foreach ((array) $tags as $name => $tagInfo) {
            $tag = is_array($tagInfo) && isset($tagInfo['instance']) && is_object($tagInfo['instance']) ? $tagInfo['instance'] : null;
            if (! $tag) {
                continue;
            }
            try {
                $result[(string) $name] = array(
                    'name' => method_exists($tag, 'get_name') ? (string) $tag->get_name() : (string) $name,
                    'title' => method_exists($tag, 'get_title') ? wp_strip_all_tags((string) $tag->get_title()) : (string) $name,
                    'group' => method_exists($tag, 'get_group') && is_scalar($tag->get_group()) ? (string) $tag->get_group() : null,
                    'categories' => method_exists($tag, 'get_categories') ? array_values(array_map('strval', (array) $tag->get_categories())) : array(),
                );
            } catch (Throwable $error) {
                $warnings[] = 'dynamic_tag_skipped:' . sanitize_key((string) $name);
            }
        }

        ksort($result, SORT_STRING);
        return $result;
    }

    private function breakpoints(object $elementor, array &$warnings): array
    {
        if (! isset($elementor->breakpoints) || ! is_object($elementor->breakpoints) || ! method_exists($elementor->breakpoints, 'get_active_breakpoints')) {
            return array();
        }

        try {
            $breakpoints = $elementor->breakpoints->get_active_breakpoints();
        } catch (Throwable $error) {
            $warnings[] = 'breakpoints_inventory_failed';
            return array();
        }

        $result = array();
        foreach ((array) $breakpoints as $name => $breakpoint) {
            if (! is_object($breakpoint)) {
                continue;
            }
            $result[(string) $name] = array(
                'label' => method_exists($breakpoint, 'get_label') ? (string) $breakpoint->get_label() : (string) $name,
                'value' => method_exists($breakpoint, 'get_value') ? (int) $breakpoint->get_value() : null,
                'direction' => method_exists($breakpoint, 'get_direction') ? (string) $breakpoint->get_direction() : null,
            );
        }

        ksort($result, SORT_STRING);
        return $result;
    }
}
