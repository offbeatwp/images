<?php

namespace OffbeatWP\Images;

use Error;
use OffbeatWP\Images\Helpers\ImageHelper;
use OffbeatWP\Contracts\View;
use OffbeatWP\Services\AbstractService;

final class ImagesService extends AbstractService
{
    public const UPLOAD_FOLDER = 'odi';
    protected $focalPointUpdates = [];

    public function register(View $view): void
    {
        add_action( 'admin_enqueue_scripts', [$this, 'enqueueAdminAssets'], 10 );

        add_filter('image_downsize', function ($out, $attachmentId, $size) {
            if (!is_string($size)) {
                return $out;
            }

            $isImage = wp_attachment_is_image($attachmentId);
            if (!$isImage) {
                return $out;
            }

            $image = $this->getImage($attachmentId, $size);

            if ($image) {
                return [$image['url'], $image['width'], $image['height'], true];
            }

            return $out;
        }, 10, 3);

        add_filter('wp_get_attachment_image_attributes', static function ($attr, $attachment, $size) {
            $srcsetList = [];

            if (isset($attr['*srcset']) && is_array($attr['*srcset'])) {
                $attr['*srcset'][] = $size;
                $attr['*srcset'] = array_unique($attr['*srcset']);

                foreach ($attr['*srcset'] as $srcsetSize) {
                    $image = image_downsize($attachment->ID, $srcsetSize);

                    if ($image) {
                        $srcsetList[$image[1]] = "{$image[0]} {$image[1]}w";
                    }
                }

                if ($srcsetList) {
                    ksort($srcsetList);
                    $attr['srcset'] = implode(', ', $srcsetList);
                } else {
                    $attr['srcset'] = null;
                }

                unset($attr['*srcset']);
            }

            return $attr;
        }, 10, 3);

        add_filter('intermediate_image_sizes_advanced', function ($newSizes, $imageMeta, $attachmentId) {
            $onDemandImageSizes = $this->getOnDemandImageSizes();

            foreach ($onDemandImageSizes as $onDemandImageSizeKey => $onDemandImageSize) {
                if (isset($newSizes[$onDemandImageSizeKey])) {
                    unset($newSizes[$onDemandImageSizeKey]);
                }
            }

            return $newSizes;
        }, 10, 3);

        add_filter( 'wp_image_src_get_dimensions', function ($dimensions, $imageSrc, $imageMeta, $attachmentId) {
            // If there are already dimensions, we don't have to get it for this image
            if ($dimensions) {
                return $dimensions;
            }

            $uploadDir = $this->getUploadDir();

            
            if (
                !$uploadDir || // If no uploaddir for on demand images, there is no point to continue;
                strpos($imageSrc, $uploadDir['url']) === false // The url must be a ODI url to continue
            ) {
                return $dimensions; 
            }

            $imagePath = str_replace($uploadDir['url'], $uploadDir['path'], $imageSrc);

            // File must exist
            if (!is_file($imagePath )) {
                return $dimensions;
            }

            $imageInfo = wp_getimagesize($imagePath);

            // Can we get the info of the file, if not return initial value
            if (!$imageInfo) {
                return $dimensions;
            }

            return [$imageInfo[0], $imageInfo[1]];
        }, 10, 4);

        add_action('init', [$this, 'initFocalPoint']);

        $view->registerGlobal('image', new ImageHelper($this));
    }

    /** @return array{width: int, height: int, crop: bool}[] */
    public function getOnDemandImageSizes(): array
    {
        $onDemandSizes = [];

        $sizes = wp_get_registered_image_subsizes();

        foreach ($sizes as $sizeKey => $size) {
            if (preg_match('/^\*(.*)$/', $sizeKey, $match)) {
                $onDemandSizes[$sizeKey] = $size;
                $onDemandSizes[$match[1]] = $size;
            }
        }

        $onDemandImageSizeKeys = apply_filters('image-sizes-on-demand/enable', ['large', 'medium']);

        foreach ($onDemandImageSizeKeys as $onDemandImageSizeKey) {
            if (isset($sizes[$onDemandImageSizeKey])) {
                $onDemandSizes[$onDemandImageSizeKey] = $sizes[$onDemandImageSizeKey];
            }
        }

        return $onDemandSizes;
    }

    /** @return array{path: string, url: string}|null */
    public function getUploadDir(string $relativePath = '', bool $create = true): ?array
    {
        $wpUploadDir = wp_upload_dir();


        if (!$wpUploadDir) {
            return null;
        }

        if ($relativePath) {
            $relativePath = untrailingslashit(preg_replace('/^\/(.*)/', '$1', $relativePath));
        }

        $path = untrailingslashit($wpUploadDir['basedir']) . '/' . self::UPLOAD_FOLDER . '/' . $relativePath;
        $url = untrailingslashit($wpUploadDir['baseurl']) . '/' . self::UPLOAD_FOLDER . '/' . $relativePath;

        if ($create) {
            wp_mkdir_p($path);
        }

        return [
            'path' => $path,
            'url' => $url
        ];
    }

    /** @return array{width: int, height: int, crop: bool}|null */
    public function getSizeDefinition(string $size): ?array
    {
        $sizes = $this->getOnDemandImageSizes();

        if (!empty($sizes[$size])) {
            return $sizes[$size];
        }

        return null;
    }

    /** @return array{width: int, height: int, path: string, url: string}|null */
    public function getImage(int $attachmentId, string $size)
    {
        $meta = wp_get_attachment_metadata($attachmentId);

        if (!is_array($meta) || empty($meta['file'])) {
            return null;
        }

        $mimeType = get_post_mime_type($attachmentId);
        if ($mimeType && strpos($mimeType, 'svg') !== false) {
            return null;
        }

        $imageSizeDefiniton = $this->getSizeDefinition($size);
        if (!$imageSizeDefiniton) {
            if (!preg_match('/^\*(\d+)x(\d+)(c)?(\/([0-9])x)?$/', $size, $matches) || !is_numeric($matches[1]) || !is_numeric($matches[2])) {
                return null;
            }

            $width = (int)$matches[1];
            $height = (int)$matches[2];
            $density = (isset($matches[5]) && is_numeric($matches[5])) ? $matches[5] : 1;

            if ($density > 1) {
                $width *= $density;
                $height *= $density;
            }

            $imageSizeDefiniton = [
                'width' => $width,
                'height' => $height,
                'crop' => isset($matches[3]) && $matches[3] === 'c',
            ];
        }

        $imagePathInfo = pathinfo($meta['file']);
        if (!$imagePathInfo) {
            return null;
        }

        $uploadDir = $this->getUploadDir($imagePathInfo['dirname']);
        if (!$uploadDir) {
            return null;
        }

        $imageFilename = $imagePathInfo['filename'] . '-' . $imageSizeDefiniton['width'] . 'x' . $imageSizeDefiniton['height'] . '.' . $imagePathInfo['extension'];
        $imagePath = $uploadDir['path'] . '/' . $imageFilename;

        if (!file_exists($imagePath) && !$this->generateImage($attachmentId, $imageSizeDefiniton, $imagePath)) {
            return null;
        }

        $imageInfo = wp_getimagesize($imagePath);
        if (!$imageInfo) {
            return null;
        }

        return [
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
            'path' => $imagePath,
            'url' => $uploadDir['url'] . '/' . $imageFilename
        ];
    }

    public function generateImage(int $attachmentId, array $size, string $destinationPath)
    {
        $originalPath = wp_get_original_image_path($attachmentId);

        if (!$originalPath) {
            return null;
        }

        $imageEditor = wp_get_image_editor($originalPath);
        if (is_wp_error($imageEditor)) {
            return null;
        }

        $originalSize = $imageEditor->get_size();

        if (
            $originalSize['width'] < $size['width'] ||
            $originalSize['height'] < $size['height']
        ) {
            return null;
        }

        $focalpointX = get_post_meta($attachmentId, 'focalpoint_x', true) ?: null;
        $focalpointY = get_post_meta($attachmentId, 'focalpoint_y', true) ?: null;

        if (is_numeric($focalpointX) && is_numeric($focalpointY)) {
            // sanitize and distribute parameters
            $dst_w = (int)$size['width'];
            $dst_h = (int)$size['height'];
            $focal_x = (float)$focalpointX;
            $focal_y = (float)$focalpointY;

            // maybe replace empty sizes
            if (!$dst_w) {
                $dst_w = $originalSize['width'];
            }
            if (!$dst_h) {
                $dst_h = $originalSize['height'];
            }

            // calculate cropped image size
            $src_w = $originalSize['width'];
            $src_h = $originalSize['height'];

            if ($originalSize['width'] / $originalSize['height'] > $dst_w / $dst_h) {
                $src_w = round($originalSize['height'] * ($dst_w / $dst_h));
            } else {
                $src_h = round($originalSize['width'] * ($dst_h / $dst_w));
            }

            // calculate focal top left position
            $src_x = $originalSize['width'] * $focal_x - $src_w * $focal_x;
            if ($src_x + $src_w > $originalSize['width']) {
                $src_x += $originalSize['width'] - $src_w - $src_x;
            }
            if ($src_x < 0) {
                $src_x = 0;
            }
            $src_x = round($src_x);

            $src_y = $originalSize['height'] * $focal_y - $src_h * $focal_y;
            if ($src_y + $src_h > $originalSize['height']) {
                $src_y += $originalSize['height'] - $src_h - $src_y;
            }
            if ($src_y < 0) {
                $src_y = 0;
            }
            $src_y = round($src_y);

            $imageEditor->crop((int)$src_x, (int)$src_y, (int)$src_w, (int)$src_h, (int)$dst_w, (int)$dst_h);
            $savedImage = $imageEditor->save($destinationPath);

            return $savedImage;
        }

        $imageEditor->resize($size['width'], $size['height'], $size['crop'] ?? true);
        $savedImage = $imageEditor->save($destinationPath);

        return $savedImage;
    }

    public function deleteImagesForAttachment(int $attachmentId): bool
    {
        $isImage = wp_attachment_is_image($attachmentId);
        if (!$isImage) {
            return false;
        }

        $meta = wp_get_attachment_metadata($attachmentId);
        if (!is_array($meta)) {
            return false;
        }

        $imagePathInfo = pathinfo($meta['file']);
        if (!$imagePathInfo) {
            return false;
        }

        $uploadDir = $this->getUploadDir($imagePathInfo['dirname']);
        if (!$uploadDir) {
            return false;
        }

        $globResult = glob($uploadDir['path'] . '/' . $imagePathInfo['filename'] . '-*') ?: [];
        foreach ($globResult as $filePath) {
            $filename = basename($filePath);

            if (preg_match('/^' . preg_quote($imagePathInfo['filename'], '/') . '-\d+x\d+\.' . $imagePathInfo['extension'] . '/', $filename)) {
                wp_delete_file($filePath);
            }
        }

        return true;
    }

    /* Stuff for focal point selector */


    public function initFocalPoint()
    {
        add_action('attachment_submitbox_misc_actions', [$this, 'addButtonToMediaEditPage'], 99);
        add_filter('attachment_fields_to_edit', [$this, 'addButtonToEditMediaModalFieldsArea'], 99, 2);

        // add_filter( 'rest_request_after_callbacks', [$this, 'watchFocalPointChanges'], 99, 3 );

        add_action('added_post_meta', [$this, 'watchFocalPointChanges'], 10, 3);
        add_action('updated_post_meta', [$this, 'watchFocalPointChanges'], 10, 3);

        register_meta('post', 'focalpoint_x', [
            'type' => 'number',
            'show_in_rest' => true,
            'single' => true,
        ]);

        register_meta('post', 'focalpoint_y', [
            'type' => 'number',
            'show_in_rest' => true,
            'single' => true,
        ]);

        add_action('shutdown', function () {
            if (!empty($this->focalPointUpdates)) {
                foreach ($this->focalPointUpdates as $focalPointUpdate) {
                    $this->deleteImagesForAttachment($focalPointUpdate);
                }
            }
        });
    }

    public function watchFocalPointChanges($metaId, $postId, $metaKey)
    {
        if (
            get_post_type($postId) === 'attachment' &&
            in_array($metaKey, ['focalpoint_x', 'focalpoint_y'])
        ) {
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

    public function addButtonToEditMediaModalFieldsArea($form_fields, $post)
    {
        // @TODO: Enable later
        // if ( ! current_user_can( $this->capability ) || ! $this->is_regeneratable( $post ) ) {
        // 	return $form_fields;
        // }

        $form_fields['image_focal_point'] = [
            'label' => '',
            'input' => 'html',
            'html' => '<button class="button-secondary button-large focal-point-modal-trigger" data-image-info=\'' . json_encode($this->getImageInfoForFocalPointTriggerButton($post)) . '\' title="' . esc_attr(__('Set image focal point', 'offbeatwp')) . '">' . __('Set image focal point', 'offbeatwp') . '</button>',
            'show_in_modal' => true,
            'show_in_edit' => false,
        ];

        return $form_fields;
    }

    public function getImageInfoForFocalPointTriggerButton($post)
    {
        return [
            'id' => $post->ID,
            'url' => wp_get_attachment_url($post->ID),
            'focalpoint_x' => get_post_meta($post->ID, 'focalpoint_x', true) ?: null,
            'focalpoint_y' => get_post_meta($post->ID, 'focalpoint_y', true) ?: null
        ];

    }

    public function enqueueAdminAssets() {
        $entryBuildPath = dirname(__FILE__) . '/../build';
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

    // public function enqueueEntryPoint($name, $entryBuildPath) {
        



    //     $assets  = include $assetPath;
    //     $handleName = 'block-scripts-' . $name;

    //     wp_enqueue_script(
    //         $handleName,
    //         get_template_directory_uri() . '/blocks/build/' . $name . '/index.js',
    //         $assets['dependencies'],
    //         $assets['version'],
    //         ['in_footer' => true]
    //     );

    //     if (file_exists($entryBuildPath . '/style-index.css')) {
    //         wp_enqueue_style(
    //             $handleName,
    //             get_template_directory_uri() . '/blocks/build/' . $name . '/style-index.css',
    //             ['wp-components'],
    //             $assets['version']
    //         );
    //     }

    //     wp_set_script_translations(
    //         $handleName,
    //         'offbeatwp',
    //         get_template_directory() . '/languages'
    //     );  
    // }
}