<?php
/*
Plugin Name: Product Image Variation Matcher
Description: Upload a folder of images and match them to products in a selected category, adding a new variation with the image as a downloadable attachment.
Version: 2.4
Author: Your Name
*/

// Register the configuration page
add_action('admin_menu', 'pivm_register_menu');
function pivm_register_menu() {
    add_menu_page(
        'Product Image Variation Matcher', // Page title
        'Image Variation Matcher',         // Menu title
        'manage_options',                  // Capability
        'image-variation-matcher',         // Menu slug
        'pivm_config_page',                // Function
        'dashicons-images-alt',            // Icon URL
        99                                 // Position
    );
}

// Configuration page content
function pivm_config_page() {
    ?>
    <div class="wrap">
        <h1>Product Image Variation Matcher</h1>
        <form method="post" enctype="multipart/form-data">
            <label for="category">Select Category:</label>
            <select name="category" id="category">
                <?php
                $categories = get_terms('product_cat', ['hide_empty' => false]);
                foreach ($categories as $category) {
                    echo '<option value="' . $category->term_id . '">' . $category->name . '</option>';
                }
                ?>
            </select>
            <br><br>
            <label for="variation_name">Variation Name:</label>
            <input type="text" name="variation_name" id="variation_name" value="Digitális">
            <br><br>
            <label for="variation_price">Variation Price (HUF):</label>
            <input type="number" step="0.01" name="variation_price" id="variation_price" value="0.00">
            <br><br>
            <label for="image_folder">Upload Image Folder (ZIP):</label>
            <input type="file" name="image_folder" id="image_folder">
            <br><br>
            <input type="submit" name="submit" value="Upload and Process">
        </form>
        <h2>Debug Log</h2>
        <pre><?php echo get_transient('pivm_debug_log'); ?></pre>
    </div>
    <?php

    // Handle form submission
    if (isset($_POST['submit'])) {
        pivm_handle_upload();
        // Display updated log
        echo '<h2>Debug Log</h2><pre>' . get_transient('pivm_debug_log') . '</pre>';
    }
}

// Handle file upload and processing
function pivm_handle_upload() {
    // Clear previous log
    set_transient('pivm_debug_log', '', HOUR_IN_SECONDS);
    $success = true;

    if (!empty($_FILES['image_folder']['tmp_name'])) {
        $category_id = intval($_POST['category']);
        $variation_name = sanitize_text_field($_POST['variation_name']);
        $variation_price = floatval($_POST['variation_price']);
        $zip = new ZipArchive();
        $res = $zip->open($_FILES['image_folder']['tmp_name']);
        if ($res === TRUE) {
            $upload_dir = wp_upload_dir();
            $extract_path = $upload_dir['basedir'] . '/temp_images';
            if (!file_exists($extract_path)) {
                mkdir($extract_path, 0755, true);
            }
            $zip->extractTo($extract_path);
            $zip->close();
            
            // Create the images directory if it doesn't exist
            $images_dir = $upload_dir['basedir'] . '/images';
            if (!file_exists($images_dir)) {
                mkdir($images_dir, 0755, true);
            }

            // Process images
            $images = glob($extract_path . '/*.{jpg,jpeg,png,gif}', GLOB_BRACE);
            pivm_debug_log("Found " . count($images) . " images in the folder.");
            foreach ($images as $image) {
                $filename = pathinfo($image, PATHINFO_FILENAME);
                $new_image_path = $images_dir . '/' . basename($image);

                // Move the image to the images directory
                if (rename($image, $new_image_path)) {
                    $product = pivm_get_product_by_name($filename, $category_id);
                    if ($product) {
                        pivm_debug_log("Product found for filename: $filename");
                        pivm_add_variation($product, $variation_name, $variation_price, $new_image_path);
                    } else {
                        pivm_debug_log("No product found for filename: $filename");
                        $success = false;
                    }
                } else {
                    pivm_debug_log("Failed to move image: $image");
                    $success = false;
                }
            }
            // Clean up extracted files
            array_map('unlink', glob("$extract_path/*.*"));
            rmdir($extract_path);

            if ($success) {
                echo '<div class="notice notice-success"><p>Images processed successfully.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Some images could not be processed.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Failed to open ZIP file.</p></div>';
        }
    }
}

// Get product by name
function pivm_get_product_by_name($filename, $category_id) {
    $args = [
        'post_type' => 'product',
        'posts_per_page' => -1,
        'tax_query' => [
            [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $category_id,
            ]
        ],
        's' => $filename // Search by product name
    ];
    $query = new WP_Query($args);
    pivm_debug_log("Querying product for filename: $filename, found " . $query->found_posts . " posts.");
    return $query->have_posts() ? $query->posts[0] : null;
}

// Add variation to product
function pivm_add_variation($product, $variation_name, $variation_price, $image_path) {
    $product_id = $product->ID;

    // Ensure the attribute value is updated
    $product_attributes = get_post_meta($product_id, '_product_attributes', true);
    if (isset($product_attributes['class'])) {
        if (!empty($product_attributes['class']['value'])) {
            $product_attributes['class']['value'] .= ' | ' . $variation_name;
        } else {
            $product_attributes['class']['value'] = 'kicsi | kozepes | nagy | ' . $variation_name;
        }
    } else {
        $product_attributes['class'] = [
            'name' => 'class',
            'value' => 'kicsi | kozepes | nagy | ' . $variation_name,
            'position' => 0,
            'is_visible' => 1,
            'is_variation' => 1,
            'is_taxonomy' => 0
        ];
    }
    update_post_meta($product_id, '_product_attributes', $product_attributes);

    // Create variation
    $variation_id = wp_insert_post([
        'post_title' => $variation_name,
        'post_name' => 'product-' . $product_id . '-variation-' . $variation_name,
        'post_status' => 'publish',
        'post_parent' => $product_id,
        'post_type' => 'product_variation',
    ]);

    if ($variation_id) {
        update_post_meta($variation_id, '_downloadable', 'yes');
        update_post_meta($variation_id, '_virtual', 'yes');
        update_post_meta($variation_id, '_regular_price', $variation_price);
        
        // Attach image as downloadable file
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => mime_content_type($image_path),
            'post_title' => basename($image_path),
            'post_content' => '',
            'post_status' => 'inherit'
        ], $image_path);

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attachment_id, $image_path);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        update_post_meta($variation_id, '_downloadable_files', [
            'file' => [
                'name' => basename($image_path),
                'file' => wp_get_attachment_url($attachment_id)
            ]
        ]);

        pivm_debug_log("Variation created for product ID $product_id with variation ID $variation_id and image $image_path.");
    } else {
        pivm_debug_log("Failed to create variation for product ID $product_id.");
    }
}

// Debug logging function
function pivm_debug_log($message) {
    $log = get_transient('pivm_debug_log');
    $log .= "[PIVM] $message\n";
    set_transient('pivm_debug_log', $log, HOUR_IN_SECONDS);
}
?>