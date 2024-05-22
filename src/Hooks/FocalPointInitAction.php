<?php

namespace OffbeatWP\Images\Hooks;

use Error;
use OffbeatWP\Hooks\AbstractAction;
use WP_Post;

final class FocalPointInitAction extends AbstractAction
{
    /** @var int[] */
    protected array $focalPointUpdates = [];

    public function action()
    {
        add_action( 'admin_enqueue_scripts', [$this, 'enqueueAdminAssets'], 10 );
        
        add_action('attachment_submitbox_misc_actions', [$this, 'addButtonToMediaEditPage'], 99);
        add_filter('attachment_fields_to_edit', [$this, 'addButtonToEditMediaModalFieldsArea'], 99, 2);

        // add_filter( 'rest_request_after_callbacks', [$this, 'watchFocalPointChanges'], 99, 3 );

        add_action('added_attachment_meta', [$this, 'watchFocalPointChanges'], 10, 3);
        add_action('updated_attachment_meta', [$this, 'watchFocalPointChanges'], 10, 3);
        add_action('updated_post_meta', [$this, 'watchFocalPointChanges'], 10, 3);

        register_post_meta('attachment', 'focalpoint_x', ['type' => 'number', 'show_in_rest' => true, 'single' => true]);
        register_post_meta('attachment', 'focalpoint_y', ['type' => 'number', 'show_in_rest' => true, 'single' => true]);

        add_action('shutdown', function () {
            foreach ($this->focalPointUpdates as $focalPointUpdate) {
                offbeat('images')->deleteImagesForAttachment($focalPointUpdate);
            }
        });
    }

    /**
     * @param int $metaId
     * @param int $postId
     * @param string $metaKey
     */
    public function watchFocalPointChanges($metaId, $postId, $metaKey): void
    {
        if (in_array($metaKey, ['focalpoint_x', 'focalpoint_y'], true)) {
            $this->focalPointUpdates[] = $postId;
        }
    }

    public function addButtonToMediaEditPage()
    {
        global $post;

        // @TODO: Enable later
        // if ( ! current_user_can( $this->capability ) || ! $this->is_regeneratable( $post ) ) {
        // 	return;
        // }

        echo '<div class="misc-pub-section misc-pub-focal-point">';
        echo '<button class="button-secondary button-large focal-point-modal-trigger" data-image-info=\'' . json_encode($this->getImageInfoForFocalPointTriggerButton($post)) . '\' title="' . esc_attr(__('Set image focal point', 'offbeatwp')) . '">' . __('Set image focal point', 'offbeatwp') . '</button>';
        echo '</div>';
    }

    /**
     * @param mixed[] $formFields
     * @param WP_Post $post
     * @return mixed[]
     */
    public function addButtonToEditMediaModalFieldsArea($formFields, $post)
    {
        // @TODO: Enable later
        // if ( ! current_user_can( $this->capability ) || ! $this->is_regeneratable( $post ) ) {
        // 	return $form_fields;
        // }

        $formFields['image_focal_point'] = [
            'label' => '',
            'input' => 'html',
            'html' => '<button class="button-secondary button-large focal-point-modal-trigger" data-image-info=\'' . json_encode($this->getImageInfoForFocalPointTriggerButton($post)) . '\' title="' . esc_attr(__('Set image focal point', 'offbeatwp')) . '">' . __('Set image focal point', 'offbeatwp') . '</button>',
            'show_in_modal' => true,
            'show_in_edit' => false,
        ];

        return $formFields;
    }

    /**
     * @param WP_Post $post
     * @return array{id?: int, url?: string, focalpoint_x?: float, focalpoint_y?: float}
     */
    public function getImageInfoForFocalPointTriggerButton(WP_Post $post): array
    {
        return array_filter([
            'id' => $post->ID,
            'url' => wp_get_attachment_url($post->ID),
            'focalpoint_x' => (float)get_post_meta($post->ID, 'focalpoint_x', true),
            'focalpoint_y' => (float)get_post_meta($post->ID, 'focalpoint_y', true)
        ]);
    }

    public function enqueueAdminAssets() {
        $entryBuildPath = __DIR__ . '/../../build';
        $assetPath = $entryBuildPath . '/index.asset.php';

        if ( ! file_exists( $assetPath ) ) {
            throw new Error(
                esc_html__( 'You need to run `npm run start` or `npm run build` from the package folder first', 'offbeatwp' )
            );
        }

        $assets  = include $assetPath;
        $handleName = 'scripts-offbeatwp-images';

        wp_enqueue_script(
            $handleName,
            get_template_directory_uri() . '/vendor/offbeatwp/images/build/index.js',
            $assets['dependencies'],
            $assets['version'],
            ['in_footer' => true]
        );

        if (file_exists($entryBuildPath . '/style-index.css')) {
            wp_enqueue_style(
                $handleName,
                get_template_directory_uri() . '/vendor/offbeatwp/images/build/style-index.css',
                [],
                $assets['version']
            );
        }

        // $this->enqueueEntryPoint('images', __DIR__ . '/../build/');
    }
}