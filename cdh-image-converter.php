<?php
/*
Plugin Name: CodeHive Image Converter to WebP
Description: The best image converter plugin on the market, simple, lightweight and optimized. Convert your images to WebP, force them to load and have a much faster website.
Version: 2.0.0
Author: CodeHive
Author URI: https://codehive.com.br
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
Text Domain: chd_image_converter

Requires at least: 4.0
Tested up to: 6.0

WC requires at least: 3.0
WC tested up to: 6.8

@package CodeHive Image Converter
@category Core
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

global $wpdb;
define("CDH_TABLE_WEBP_CONVERSION", $wpdb->prefix . 'cdh_webp_conversion');

/**
 * Add support to Woocommerce HPOS
 * 
 * @since 19/08/2024
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * 
 * When activate plugin: Create database and add custom schedule
 * 
 */
function cdh_image_converter_activation() {

    if (check_image_libraries()) {
        global $wpdb;

        $table_name = CDH_TABLE_WEBP_CONVERSION;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id INT(11) NOT NULL AUTO_INCREMENT,
            attachment_id INT(11) NOT NULL,
            image_path VARCHAR(255) NOT NULL,
            status ENUM('pending', 'completed', 'error') DEFAULT 'pending',
            size VARCHAR(50) NULL,
            PRIMARY KEY (id),
            INDEX status_index (status) -- Índice para o campo ENUM status
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Verificar se a coluna já existe
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'status'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN status ENUM('pending', 'completed', 'error') NOT NULL DEFAULT 'pending'");
            $wpdb->query("ALTER TABLE $table_name ADD INDEX status_index (status)");
        }

        // add cron schedule
        cdh_active_scheduled_to_convert_images();
    }

}
register_activation_hook(__FILE__, 'cdh_image_converter_activation');

function cdh_active_scheduled_to_convert_images(){
    if (!wp_next_scheduled('cdh_convert_images_to_webp_cron_event')) {
        wp_schedule_event(time(), 'cdh_img_converter_6hourly', 'cdh_convert_images_to_webp_cron_event');
    }
}

/**
 * We clear crons on disabled plugin
 */
register_deactivation_hook( __FILE__, 'cdh_image_converter_deactivation' );
function cdh_image_converter_deactivation() { // runs on plugin is disabled;
    wp_clear_scheduled_hook( 'cdh_convert_images_to_webp_cron_event' );
}

/**
 *
 * When plugin is loaded, verify if plugin can be work
 *
 */
add_action('plugins_loaded', function() {
    if (check_image_libraries()) {
        // Verifica se pode usar GD ou imagick
        add_action('admin_notices', function() {
            if (!extension_loaded('gd') && !class_exists('Imagick')) {
                echo '<div class="error"><p>';
                echo 'As bibliotecas GD ou imagick não estão disponíveis. O plugin não pode ser executado.';
                echo '</p></div>';
            }
        });

        // Converte imagens para WebP em segundo plano no upload
        add_filter('wp_generate_attachment_metadata', 'convert_images_to_webp_on_upload', 10, 2);

        // Remove os arquivos WebP ao excluir uma imagem
        add_action('delete_attachment', 'delete_webp_images');

        // Força o carregamento das imagens no formato WebP, caso possível
        add_filter('wp_get_attachment_image_src', 'force_webp_image', 10, 4);

        add_filter( 'wp_get_attachment_image', 'force_webp_implements_in_html', 10, 5 );
    } else {
        // Exibe um aviso caso GD ou imagick não estejam disponíveis
        add_action('admin_notices', function() {
            echo '<div class="error"><p>';
            echo 'As bibliotecas GD ou imagick não estão disponíveis. O plugin não pode ser executado.';
            echo '</p></div>';
        });
    }
});

// Verifica se GD ou imagick estão disponíveis
function check_image_libraries() {
    return extension_loaded('gd') || class_exists('Imagick');
}

// Verifica se a extensão da imagem é suportada
function is_supported_image($file) {
    $supported_formats = array('jpg', 'jpeg', 'png');

    $file_extension = pathinfo($file, PATHINFO_EXTENSION);
    return in_array(strtolower($file_extension), $supported_formats);
}

// Converte a imagem para WebP
function convert_to_webp($image_path) {
    // Obter o valor padrão de qualidade para imagens WebP
    $webp_quality = apply_filters('wp_editor_set_quality', null);

    // Obtém o caminho completo do diretório de upload
    $upload_dir = wp_upload_dir();
    if (filter_var($image_path, FILTER_VALIDATE_URL)) { //thumbnails 

        // Remove tudo o que vem após "uploads" na URL
        $relative_path = strstr($image_path, '/uploads');

        if ($relative_path !== false) {
            // Remove "uploads" do caminho relativo
            $relative_path = substr($relative_path, strlen('/uploads'));

            // Concatena o caminho absoluto do diretório base com o caminho relativo do arquivo
            $image_path = $upload_dir['basedir'] . $relative_path;
        }

    }
        
    $file_dirname = pathinfo( $image_path, PATHINFO_DIRNAME );
    $file_name_no_ext = pathinfo( $image_path, PATHINFO_FILENAME );
    $output_path = $file_dirname . '/' . $file_name_no_ext . '.webp';

    if (extension_loaded('gd')) {
        $image = imagecreatefromstring(file_get_contents($image_path));

        if ($image !== false) {
            imagepalettetotruecolor($image);
            imagewebp($image, $output_path, $webp_quality); // Ajuste o valor de qualidade (0-100) conforme necessário
            imagedestroy($image);
        }

    } else if (class_exists('Imagick')) {
        $image = new Imagick($image_path);
        $image->setImageFormat('webp');
        $image->setCompressionQuality($webp_quality); // Ajuste o valor de qualidade (0-100) conforme necessário

        if ($image->writeImage($output_path)) {
            $image->destroy();
        }
    }

    if (file_exists($output_path)) {
        chmod($output_path, 0644);
        $relative_path = str_replace($upload_dir['basedir'], '', $output_path);
       
        return $relative_path;
    }

    return false;
}

/**
 * Convert all thumbnails to webp
 */
function convert_thumbnails_to_webp($post_id) {
    $thumbnail_sizes = get_intermediate_image_sizes();

    foreach ($thumbnail_sizes as $size) {
        $image = wp_get_attachment_image_src($post_id, $size);
        if ($image && is_supported_image($image[0])) {
            $webp_image = convert_to_webp($image[0]);

            if ($webp_image) {
                update_post_meta($post_id, '_wp_attachment_' . $size . '_webp', $webp_image);
            }
        }
    }
}

/**
 *
 * Remove all Webp Imagens when exclude Originals images
 *
 */
function delete_webp_images($post_id) {
    $thumbnail_sizes = get_intermediate_image_sizes();
    $upload_dir = wp_upload_dir();

    //exclui as miniaturas geradas
    foreach ($thumbnail_sizes as $size) {
        $webp_image = get_post_meta($post_id, '_wp_attachment_' . $size . '_webp', true);
        $image_path = $upload_dir['basedir'] . $webp_image;
        if ($webp_image && file_exists($image_path)) {
            unlink( $image_path );
        }
    }

    // Obter o caminho do arquivo da imagem original em WEBP
    $webp_image = get_post_meta($post_id, '_wp_attachment_webp', true);
    $original_image_path = $upload_dir['basedir'] . $webp_image;

    // Verificar se a imagem original existe
    if ($original_image_path && file_exists($original_image_path)) {
        // Excluir a imagem original
        unlink($original_image_path);
    }

}

/**
 * Convert Imagens on upload images
 */
function convert_images_to_webp_on_upload($metadata, $attachment_id) {
    $file = get_attached_file($attachment_id);

    if (is_supported_image($file)) {
        $webp_image = convert_to_webp($file);

        if ($webp_image) {
            update_post_meta($attachment_id, '_wp_attachment_webp', $webp_image);
        }

        //convert_thumbnails_to_webp($attachment_id);
    }

    return $metadata;
}

/**
 * Force Webp images when html is loaded
 */
function force_webp_image($image, $attachment_id, $size) {
    if (!empty($image[0])) {
        // Obtém o caminho completo do diretório de upload
        $upload_dir = wp_upload_dir();

        // Remove tudo o que vem após "uploads" na URL
        $relative_path = strstr($image[0], '/uploads');

        if ($relative_path !== false) {
            // Remove "uploads" do caminho relativo
            $relative_path = substr($relative_path, strlen('/uploads'));

            // Concatena o caminho absoluto do diretório base com o caminho relativo do arquivo
            $image_path = $upload_dir['basedir'] . $relative_path;

            $file_extension = pathinfo($image_path, PATHINFO_EXTENSION);
            $file_dirname = pathinfo( $image_path, PATHINFO_DIRNAME );
            $file_name_no_ext = pathinfo( $image_path, PATHINFO_FILENAME );

            $image_path = $file_dirname . '/' . $file_name_no_ext . '.webp';

            // Verifica se o arquivo em formato WebP existe
            if (file_exists($image_path)) {
                $base_url = $upload_dir['baseurl']; // Obtém a URL base do diretório de uploads

                // Remove a parte do caminho que corresponde ao diretório de uploads
                $path = str_replace($upload_dir['basedir'], '', $image_path);

                // Remove a barra inicial, se houver
                $path = ltrim($path, '/');

                // Constrói a URL completa do arquivo
                $new_image_url = $base_url . '/' . $path;

                // Força o carregamento em WebP
                $image[0] = $new_image_url;
            }else{
                $image_path = $file_dirname . '/' . $file_name_no_ext . '.' . $file_extension;
                if(is_file($image_path)){
                    $data = array(
                        'attachment_id' => $attachment_id,
                        'image_path' => $image_path,
                        'size' => $size
                    );

                    add_attachament_to_database($data);
                }

            }
        }
    }

    return $image;
}


/**
 * Force Webp images when html is loaded
 */
function force_webp_implements_in_html( $html, $attachment_id, $size, $icon, $attr ){
    if(isset( $attr['src'])){
        $originalSource = $attr['src'];
        $srcset = $originalSource;
        if(isset($attr['srcset'])){
            $srcset = $attr['srcset'];
        }

        $sizes = "";
        if(isset($attr['sizes'])){
            $sizes = $attr['sizes'];
        }

        $filenameWithoutExt = substr($originalSource, 0, strrpos($originalSource, "."));

        $webpOriginaFilePlusWebpSrc = WP_CONTENT_DIR . "/" . strstr($originalSource, "uploads") . ".webp";
        $webpOriginaFileMinusExtSrc = WP_CONTENT_DIR . "/" . strstr($filenameWithoutExt, "uploads") . ".webp";

        $hasPicture = false;
        $blogUrl = get_bloginfo('wpurl');
        if(file_exists($webpOriginaFilePlusWebpSrc)){
            $newSource = $blogUrl . "/wp-content/" . strstr($webpOriginaFilePlusWebpSrc, "uploads");
            $srcset = str_replace($originalSource, $newSource, $srcset);
            $html = str_replace($originalSource, $newSource, $html);
            $hasPicture = true;

        }else if(file_exists($webpOriginaFileMinusExtSrc)){
            $newSource = $blogUrl . "/wp-content/" . strstr($webpOriginaFileMinusExtSrc, "uploads");
            $srcset = str_replace($originalSource, $newSource, $srcset);
            $html = str_replace($originalSource, $newSource, $html);
            $hasPicture = true;
        }

        if($hasPicture){
            $html = '<picture><source type="image/webp" srcset="'.$srcset.'" sizes="'.$sizes.'">'.$html.'</picture>';
        }else{
            // Obtém o caminho completo do diretório de upload
            $upload_dir = wp_upload_dir();

            // Remove tudo o que vem após "uploads" na URL
            $relative_path = strstr($originalSource, '/uploads');
            // Remove "uploads" do caminho relativo
            $relative_path = substr($relative_path, strlen('/uploads'));

            // Concatena o caminho absoluto do diretório base com o caminho relativo do arquivo
            $image_path = $upload_dir['basedir'] . $relative_path;

            $file_dirname = pathinfo( $image_path, PATHINFO_DIRNAME );
            $file_name_no_ext = pathinfo( $image_path, PATHINFO_FILENAME );
            $file_extension = pathinfo($image_path, PATHINFO_EXTENSION);

            $image_path = $file_dirname . '/' . $file_name_no_ext . '.' . $file_extension;

            if(is_file($image_path)){
                $data = array(
                    'attachment_id' => $attachment_id,
                    'image_path' => $image_path,
                    'size' => $size
                );

                add_attachament_to_database($data);
            }

        }
    }

    return $html;
}


/**
 * Define default Jpeg Quality
 */
function set_jpeg_quality($quality) {
    return 85; // Defina o valor desejado de qualidade (0-100)
}
add_filter('jpeg_quality', 'set_jpeg_quality', 99999);

/**
 * Define default Webp Quality
 */
function set_webp_quality($quality) {
    return 85; // Defina o valor desejado de qualidade (0-100)
}
add_filter('wp_editor_set_quality', 'set_webp_quality', 99999);

/**
 *
 * Save attachament in database to convert in webp images
 *
 */
function add_attachament_to_database($data){
    global $wpdb;
    $table_name = CDH_TABLE_WEBP_CONVERSION;
    // Verifica se já existe um registro com o mesmo attachment_id e tamanho na tabela
    $existing_conversion = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE attachment_id = %d AND size = %s",
            $data['attachment_id'],
            $data['size']
        )
    );

    if (!$existing_conversion) {
        $wpdb->insert(
            $table_name,
            array(
                'attachment_id' => $data['attachment_id'],
                'image_path' => $data['image_path'],
                'size' => $data['size']
            ),
            array(
                '%d',
                '%s',
                '%s'
            )
        );
    }
}

/**
 *
 * Convert images in background
 *
 */
add_action( 'cdh_convert_images_to_webp_cron_event', 'cdh_convert_images_to_webp_in_background');
function cdh_convert_images_to_webp_in_background() {
    $conversion_data = cdh_get_pending_conversions();

    if ($conversion_data) {
        foreach ($conversion_data as $data) {
            global $wpdb;
            $table_name = CDH_TABLE_WEBP_CONVERSION;

            // Atualizar o status para 'in_progress'
            $wpdb->update(
                $table_name,
                array('status' => 'in_progress'),
                array('id' => $data->id),
                array('%s'),
                array('%d')
            );

            $image_path = $data->image_path;
            $webp_image = convert_to_webp($image_path);

            if ($webp_image) {
                if ($data->size) {
                    update_post_meta($data->attachment_id, '_wp_attachment_' . $data->size . '_webp', $webp_image);
                } else {
                    update_post_meta($data->attachment_id, '_wp_attachment_webp', $webp_image);
                }

                // Atualizar o status para 'completed'
                $wpdb->update(
                    $table_name,
                    array('status' => 'completed'),
                    array('id' => $data->id),
                    array('%s'),
                    array('%d')
                );
            } else {
                // Atualizar o status para 'error'
                $wpdb->update(
                    $table_name,
                    array('status' => 'error'),
                    array('id' => $data->id),
                    array('%s'),
                    array('%d')
                );
            }
        }
    }

    // add cron schedule
    cdh_active_scheduled_to_convert_images();
}

/**
 * We configure custom runtimes for the crons we create
 */
add_filter('cron_schedules', function($schedules) {
    $schedules['cdh_img_converter_6hourly'] = [
        'interval' => 21600, // A cada 6 horas
        'display' => __('A cada 6 horas')
    ];
    return $schedules;
});

/**
 * Get pending images to convert to webp
 */
function cdh_get_pending_conversions($limit = 30) {
    global $wpdb;
    $table_name = CDH_TABLE_WEBP_CONVERSION;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE status = %s LIMIT %d",
            'pending',
            $limit
        )
    );
}