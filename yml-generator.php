<?php
/**
 * YML Generator for Yandex Webmaster
 * 
 * @package YML-Generator
 * @version 1.0.0
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set error logging
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/yml-generator-error.log');

try {
    // Try different possible WordPress locations
    $possible_paths = array(
        dirname(__FILE__) . '/wp-load.php',                    // Same directory
        dirname(__FILE__) . '/../wp-load.php',                 // One level up
        dirname(__FILE__) . '/../../wp-load.php',              // Two levels up
        dirname(__FILE__) . '/../../../wp-load.php',           // Three levels up
        dirname(__FILE__) . '/wordpress/wp-load.php',          // WordPress subdirectory
        dirname(__FILE__) . '/../wordpress/wp-load.php',       // WordPress subdirectory one level up
        dirname(__FILE__) . '/../../wordpress/wp-load.php',    // WordPress subdirectory two levels up
    );

    $wp_load_path = null;
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            $wp_load_path = $path;
            break;
        }
    }

    if (!$wp_load_path) {
        throw new Exception('WordPress load file not found. Please place this file in your WordPress root directory or specify the correct path to wp-load.php');
    }

    require_once($wp_load_path);

    // Check WooCommerce status
    if (!function_exists('is_plugin_active')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }

    // Check if WooCommerce is installed
    if (!file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php')) {
        throw new Exception('WooCommerce is not installed. Please install WooCommerce first.');
    }

    // Check if WooCommerce is active
    if (!is_plugin_active('woocommerce/woocommerce.php')) {
        throw new Exception('WooCommerce is installed but not active. Please activate WooCommerce in WordPress admin panel.');
    }

    // Check if WooCommerce is properly loaded
    if (!function_exists('wc_get_product')) {
        throw new Exception('WooCommerce is not properly loaded. Please try deactivating and reactivating WooCommerce.');
    }

    class YML_Generator {
        private $shop_name;
        private $company_name;
        private $url;
        private $categories = array();
        private $exclude_categories = array();
        private $show_all = false;

        public function __construct() {
            if (!function_exists('get_bloginfo')) {
                throw new Exception('WordPress function get_bloginfo not available');
            }

            $this->shop_name = get_bloginfo('name');
            $this->company_name = get_bloginfo('name');
            $this->url = get_site_url();
            
            // Parse URL parameters
            $this->parse_url_parameters();
        }

        private function parse_url_parameters() {
            // Check for all products parameter
            if (isset($_GET['all']) && $_GET['all'] == 1) {
                $this->show_all = true;
                return;
            }

            // Parse categories
            if (isset($_GET['categories'])) {
                $categories = explode(',', $_GET['categories']);
                foreach ($categories as $category) {
                    if (is_numeric($category)) {
                        $this->categories[] = intval($category);
                    } else {
                        $term = get_term_by('slug', $category, 'product_cat');
                        if ($term) {
                            $this->categories[] = $term->term_id;
                        }
                    }
                }
            }

            // Parse exclude categories
            if (isset($_GET['exclude_categories'])) {
                $exclude_categories = explode(',', $_GET['exclude_categories']);
                foreach ($exclude_categories as $category) {
                    if (is_numeric($category)) {
                        $this->exclude_categories[] = intval($category);
                    } else {
                        $term = get_term_by('slug', $category, 'product_cat');
                        if ($term) {
                            $this->exclude_categories[] = $term->term_id;
                        }
                    }
                }
            }
        }

        public function generate() {
            // Clear any previous output
            if (ob_get_level()) {
                ob_end_clean();
            }

            // Set proper headers
            header('Content-Type: application/xml; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            
            // Start output buffering
            ob_start();
            
            echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            echo '<!DOCTYPE yml_catalog SYSTEM "shops.dtd">' . "\n";
            echo '<yml_catalog date="' . date('Y-m-d H:i') . '">' . "\n";
            echo '<shop>' . "\n";
            
            // Shop information
            echo '<name>' . htmlspecialchars($this->shop_name) . '</name>' . "\n";
            echo '<company>' . htmlspecialchars($this->company_name) . '</company>' . "\n";
            echo '<url>' . htmlspecialchars($this->url) . '</url>' . "\n";
            
            // Currencies
            echo '<currencies>' . "\n";
            echo '<currency id="RUB" rate="1"/>' . "\n";
            echo '</currencies>' . "\n";
            
            // Categories
            $this->generate_categories();
            
            // Products
            $this->generate_products();
            
            echo '</shop>' . "\n";
            echo '</yml_catalog>';

            // Get the buffer contents and clean it
            $output = ob_get_clean();
            
            // Output the XML
            echo $output;
        }

        private function generate_categories() {
            echo '<categories>' . "\n";
            
            $args = array(
                'taxonomy' => 'product_cat',
                'hide_empty' => true,
            );
            
            $categories = get_terms($args);
            
            if (is_wp_error($categories)) {
                throw new Exception('Error getting categories: ' . $categories->get_error_message());
            }
            
            foreach ($categories as $category) {
                if ($this->should_include_category($category->term_id)) {
                    echo '<category id="' . $category->term_id . '">' . 
                         htmlspecialchars($category->name) . 
                         '</category>' . "\n";
                }
            }
            
            echo '</categories>' . "\n";
        }

        private function generate_products() {
            echo '<offers>' . "\n";
            
            $args = array(
                'post_type' => 'product',
                'posts_per_page' => -1,
                'post_status' => 'publish',
            );
            
            if (!$this->show_all && !empty($this->categories)) {
                $args['tax_query'] = array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'term_id',
                        'terms' => $this->categories,
                    ),
                );
            }
            
            $products = new WP_Query($args);
            
            if ($products->have_posts()) {
                while ($products->have_posts()) {
                    $products->the_post();
                    $product = wc_get_product(get_the_ID());
                    
                    if ($product && $this->should_include_product($product)) {
                        $this->generate_product_offer($product);
                    }
                }
            }
            
            wp_reset_postdata();
            
            echo '</offers>' . "\n";
        }

        private function generate_product_offer($product) {
            if (!$product) {
                return;
            }

            // Get product data
            $regular_price = $product->get_regular_price();
            $sale_price = $product->get_sale_price();
            $price = $product->get_price();
            $stock_status = $product->get_stock_status();
            $stock_quantity = $product->get_stock_quantity();
            $sku = $product->get_sku();
            $brand = $product->get_attribute('pa_brand');
            $sales_notes = $product->get_attribute('pa_sales_notes');
            $delivery = $product->get_attribute('pa_delivery');
            $pickup = $product->get_attribute('pa_pickup');
            $store = $product->get_attribute('pa_store');

            // Skip products without price
            if (empty($price)) {
                return;
            }

            // Generate offer
            echo '<offer id="' . $product->get_id() . '" available="true">' . "\n";
            
            // Basic product information
            $name = $product->get_name();
            if (empty($name)) {
                $name = 'Товар #' . $product->get_id();
            }
            echo '<name>' . htmlspecialchars($name) . '</name>' . "\n";

            $url = get_permalink($product->get_id());
            if (empty($url)) {
                $url = $this->url;
            }
            echo '<url>' . htmlspecialchars($url) . '</url>' . "\n";
            
            // Prices
            if ($sale_price && $sale_price < $regular_price) {
                echo '<oldprice>' . $regular_price . '</oldprice>' . "\n";
            }
            echo '<price>' . $price . '</price>' . "\n";
            echo '<currencyId>RUB</currencyId>' . "\n";
            
            // Category
            $categories = get_the_terms($product->get_id(), 'product_cat');
            if ($categories && !is_wp_error($categories)) {
                echo '<categoryId>' . $categories[0]->term_id . '</categoryId>' . "\n";
            } else {
                // Если категория не найдена, используем ID 1 (обычно это "Без категории")
                echo '<categoryId>1</categoryId>' . "\n";
            }
            
            // Picture
            if ($product->get_image_id()) {
                $image_url = wp_get_attachment_url($product->get_image_id());
                if (!empty($image_url)) {
                    echo '<picture>' . htmlspecialchars($image_url) . '</picture>' . "\n";
                }
            }
            
            // Description
            $description = $product->get_description();
            if (empty($description)) {
                $description = $name; // Используем название товара как описание, если описание пустое
            }
            echo '<description>' . htmlspecialchars($description) . '</description>' . "\n";
            
            // Sales notes (required by Yandex)
            if (empty($sales_notes)) {
                $sales_notes = 'В наличии';
            }
            echo '<sales_notes>' . htmlspecialchars($sales_notes) . '</sales_notes>' . "\n";

            // Vendor
            if (!empty($brand)) {
                echo '<vendor>' . htmlspecialchars($brand) . '</vendor>' . "\n";
            }

            // Vendor code (SKU)
            if ($sku) {
                echo '<vendorCode>' . htmlspecialchars($sku) . '</vendorCode>' . "\n";
            }

            // Delivery options
            if ($delivery === 'true' || $delivery === '1') {
                echo '<delivery>true</delivery>' . "\n";
            } else {
                echo '<delivery>false</delivery>' . "\n";
            }
            
            // Pickup options
            if ($pickup === 'true' || $pickup === '1') {
                echo '<pickup>true</pickup>' . "\n";
            } else {
                echo '<pickup>false</pickup>' . "\n";
            }
            
            // Store options
            if ($store === 'true' || $store === '1') {
                echo '<store>true</store>' . "\n";
            } else {
                echo '<store>false</store>' . "\n";
            }
            
            // Additional parameters
            if ($product->get_weight()) {
                echo '<param name="Вес">' . $product->get_weight() . ' кг</param>' . "\n";
            }
            if ($sku) {
                echo '<param name="Артикул">' . htmlspecialchars($sku) . '</param>' . "\n";
            }
            
            echo '</offer>' . "\n";
        }

        private function should_include_category($category_id) {
            if ($this->show_all) {
                return !in_array($category_id, $this->exclude_categories);
            }
            
            if (empty($this->categories)) {
                return !in_array($category_id, $this->exclude_categories);
            }
            
            return in_array($category_id, $this->categories) && 
                   !in_array($category_id, $this->exclude_categories);
        }

        private function should_include_product($product) {
            if ($this->show_all) {
                return true;
            }
            
            $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'ids'));
            
            if (empty($this->categories)) {
                return empty(array_intersect($product_categories, $this->exclude_categories));
            }
            
            return !empty(array_intersect($product_categories, $this->categories)) && 
                   empty(array_intersect($product_categories, $this->exclude_categories));
        }
    }

    // Initialize and generate YML
    $yml_generator = new YML_Generator();
    $yml_generator->generate();

} catch (Exception $e) {
    // Clear any previous output
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    error_log('YML Generator Error: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Error: ' . htmlspecialchars($e->getMessage());
} 