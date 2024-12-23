<?php
namespace mt2Tech\MarkupByAttribute\Backend;
use mt2Tech\MarkupByAttribute\Utility as Utility;
/**
 * PriceMarkupHandler creates an abstract shell class with basic Markup-by-Attribute
 * product variation functions. It is extended by the appropriate classes depending on which
 * bulk editing functions are being invoked.
 */
abstract class PriceMarkupHandler {
	/** @var	string	The type of price (regular or sale) */
	protected $price_type;
	/** @var	int		The ID of the product being processed */
	protected $product_id;
	/** @var	float	The base price of the product */
	protected $base_price;
	/** @var	string	The base price formatted according to the store's currency settings */
	protected $base_price_formatted;
	/** @var	int		The number of decimal places to use for price calculations */
	protected $price_decimals;

	/**
	 * Constructor for the PriceMarkupHandler class.
	 *
	 * @param	string	$bulk_action	The bulk action being performed
	 * @param	int		$product_id		The ID of the product being processed
	 * @param	float	$base_price		The base price of the product
	 */
	public function __construct($bulk_action, $product_id, $base_price) {
		
		// Create 'regular_price'	string	in one place
		if (!defined('REGULAR_PRICE')) {
			define('REGULAR_PRICE', 'regular_price');
		}

		// Extract price_type from bulk_action
		if ($bulk_action) {
			$bulk_action_array = explode("_", $bulk_action);
			$this->price_type = $bulk_action_array[1] . "_" . $bulk_action_array[2];
		}

		$this->product_id = $product_id;
		$this->base_price = $base_price;
		$this->base_price_formatted = strip_tags(wc_price(abs($this->base_price)));
		$this->price_decimals = wc_get_price_decimals();
	}

	/**
	 * Apply markup to product price. This method must be implemented by child classes.
	 *
	 * @param	string	$price_type The type of price (regular or sale)
	 * @param	array	$data		The data for the markup operation
	 * @param	int		$product_id The ID of the product
	 * @param	array	$variations List of product variations
	 */
	abstract public function applyMarkup($price_type, $data, $product_id, $variations);
}

/**
 * Concrete class for handling product price setting, which extends PriceMarkupHandler
 * and overrides its abstract methods.
 */
class PriceSetHandler extends PriceMarkupHandler {
	/** @var	array Cache for term meta to reduce database queries */
	protected $term_meta_cache = [];

	/**
	 * Constructor for the PriceSetHandler class.
	 *
	 * @param	string	$bulk_action	The bulk action being performed
	 * @param	array	$data			The data for the price setting operation
	 * @param	int		$product_id		The ID of the product being processed
	 * @param	array	$variations		List of product variations
	 */
	public function __construct($bulk_action, $data, $product_id, $variations) {
		parent::__construct($bulk_action, $product_id, is_numeric($data["value"]) ? (float) $data["value"] : 0);
	}

	/**
	 * Build a table of markup values for the product.
	 * 
	 * @param array $attribute_data Array of attributes with their labels and terms
	 * @param int   $product_id	The ID of the product
	 * @return array The markup table
	 */
	protected function build_markup_table($attribute_data, $product_id) {
		global $mt2mba_utility;
		$markup_table = [];

		// Calculate markup for each term for this product
		foreach ($attribute_data as $taxonomy => $data) {
			$attrb_label = $data['label'];
			foreach ($data['terms'] as $term) {
				$markup = get_term_meta($term->term_id, 'mt2mba_markup', true);

				// Set price to calculate markup against
				if ($this->price_type === REGULAR_PRICE || MT2MBA_SALE_PRICE_MARKUP === 'yes') {
					$price = $this->base_price;
				} else {
					$price = get_metadata("post", $product_id, "mt2mba_base_" . REGULAR_PRICE, true);
				}

				if (!empty($markup)) {
					if (strpos($markup, "%")) {
						// Markup is a percentage
						$markup_value = ($price * floatval($markup)) / 100;
					} else {
						// Markup is a flat amount
						$markup_value = floatval($markup);
					}

					// Round markup value
					$markup_value = MT2MBA_ROUND_MARKUP == "yes" ? 
						round($markup_value, 0) : 
						round($markup_value, $this->price_decimals);

					if ($markup_value != 0) {
						$markup_table[$taxonomy][$term->slug] = [
							'term_id' => $term->term_id,
							'markup' => $markup_value,
						];
						
						if (MT2MBA_DESC_BEHAVIOR !== "ignore" && $this->price_type === REGULAR_PRICE) {
							$markup_table[$taxonomy][$term->slug]['description'] = 
								$mt2mba_utility->format_description_markup(
									$markup_value,
									$attrb_label, 
									$term->name
								);
						}
					}
				}
			}
		}
		return $markup_table;
	}

	/**
	 * Apply markup value updates to the product.
	 *
	 * @param	array	$markup_table	The markup table for the product
	 */
	protected function apply_markup_value_updates($markup_table) {
		global $wpdb;

		// Delete all existing mt2mba_{term_id}_markup_amount records for this product
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta}
			WHERE post_id = %d
			AND meta_key LIKE 'mt2mba_%_markup_amount'",
			$this->product_id
		));

		// Build queries, then bulk insert new mt2mba_{term_id}_markup_amount into postmeta.
		$meta_data = [];

		foreach ($markup_table as $attribute => $options) {
			foreach ($options as $option => $details) {
				$term_id = $details['term_id'];
				$markup = number_format(floatval($details['markup']), $this->price_decimals, '.', '');
				$meta_key = "mt2mba_{$term_id}_markup_amount";
				$meta_data[] = $wpdb->prepare("(%d, %s, %s)", $this->product_id, $meta_key, $markup);
			}
		}

		if (!empty($meta_data)) {
			// Bulk insert new records
			$wpdb->query("
				INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
				VALUES " . implode(", ", $meta_data)
			);
		}
	}

	/**
	 * Perform bulk update of variation prices and descriptions.
	 * Uses MySQL's handling of duplicate keys to effectively perform an UPSERT operation.
	 * When inserting a duplicate (post_id, meta_key) pair, MySQL will update the existing value.
	 *
	 * @param	array	$updates	Array of updates to apply. Each element contains:
	 *								- id:			(int)		Variation ID
	 *								- price:		(float)		New price value
	 *								- description:	(string)	New variation description
	 */
	protected function bulk_variation_update($updates) {
		global $wpdb;
	
		$variation_ids = [];
		$price_inserts = [];
		$description_updates = [];
	
		// Build arrays for our SQL operations
		foreach ($updates as $update) {
			$variation_ids[] = (int)$update['id'];

			// Reformat price if not null
			if ($update['price'] !== null) {
				$update['price'] = number_format($update['price'], $this->price_decimals, '.', '');
			}

			// Each variation needs both '_price' and price type records
			$price_inserts[] = $wpdb->prepare(
				"(%d, %s, %s),
				(%d, %s, %s)",
				$update['id'], 
				'_price',
				$update['price'],
				$update['id'], 
				'_' . $this->price_type,
				$update['price']
			);

			if (isset($update['description'])) {
				$description_updates[] = $wpdb->prepare(
					"(%d, '_variation_description', %s)", 
					$update['id'], 
					$update['description']
				);
			}
		}

		// Start transaction for data consistency
		$wpdb->query('START TRANSACTION');
	
		try {
			// Delete existing price records first
			if (!empty($variation_ids)) {
				$placeholders = array_fill(0, count($variation_ids), '%d');
				$meta_keys = array('_price', '_' . $this->price_type);
				
				$wpdb->query($wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} 
					WHERE post_id IN (" . implode(',', $placeholders) . ")
					AND meta_key IN (%s, %s)",
					array_merge($variation_ids, $meta_keys)
				));
			}
	
			// Insert new price records
			if (!empty($price_inserts)) {
				$wpdb->query(
					"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) 
					VALUES " . implode(", ", $price_inserts)
				);
			}
	
			// Handle descriptions for regular price updates
			if ($this->price_type === REGULAR_PRICE && !empty($description_updates)) {
				// Remove existing descriptions
				$wpdb->query($wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta}
					WHERE post_id IN (" . implode(',', array_fill(0, count($variation_ids), '%d')) . ")
					AND meta_key = '_variation_description'",
					$variation_ids
				));
	
				// Insert new descriptions
				$wpdb->query(
					"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
					VALUES " . implode(", ", $description_updates)
				);
			}
	
			$wpdb->query('COMMIT');
	
		} catch (Exception $e) {
			$wpdb->query('ROLLBACK');
			throw $e;
		}
	}

	/**
	 * Main function to apply markup to product variations.
	 *
	 * @param	string	$bulk_action	The bulk action being performed
	 * @param	array	$data			The data for the markup operation
	 * @param	int		$product_id		The ID of the product
	 * @param	array	$variations		List of product variations
	 */
	public function applyMarkup($bulk_action, $data, $product_id, $variations) {
		global $mt2mba_utility;

		// If setting sale price to zero...
		if ($this->base_price == 0 && $this->price_type !== REGULAR_PRICE) {
			delete_post_meta($product_id, "mt2mba_base_{$this->price_type}");
			$this->price_type = REGULAR_PRICE;
			$data['value'] = get_metadata("post", $product_id, "mt2mba_base_{$this->price_type}", true);
			$handler = new PriceSetHandler("variable_{$this->price_type}", $data, $product_id, $variations);
			$handler->applyMarkup($bulk_action, $data, $product_id, $variations);
			return;
		}

		// Retrieve all attributes and their terms for the product
		$attribute_data = [];
		foreach (wc_get_product($product_id)->get_attributes() as $pa_attrb) {
			if ($pa_attrb->is_taxonomy()) {
				$taxonomy = $pa_attrb->get_name();
				$attribute_data[$taxonomy] = [
					'label' => wc_attribute_label($taxonomy),
					'terms' => get_terms([
						"taxonomy" => $taxonomy, 
						"hide_empty" => false
					])
				];
			}
		}

		// Build a table of the markup values for the product
		$markup_table = $this->build_markup_table($attribute_data, $product_id);

		// Bulk save product markup values
		if ($this->price_type === REGULAR_PRICE) {
			$this->apply_markup_value_updates($markup_table);
		}

		// Save new base price
		$rounded_base = round($this->base_price, $this->price_decimals);
		update_post_meta($product_id, "mt2mba_base_{$this->price_type}", $rounded_base);
		if ($this->price_type === REGULAR_PRICE) {
			//	Store the current base price in a transient
			set_transient('mt2mba_current_base_' . $product_id, $rounded_base, HOUR_IN_SECONDS);
		}

		//	Format the base price description for the variations
		$base_price_description = MT2MBA_HIDE_BASE_PRICE === 'no' ? html_entity_decode(MT2MBA_PRICE_META . $this->base_price_formatted) : '';

		//	Set up table with variation prices
		$variation_updates = [];
		foreach ($variations as $variation_id) {
			$variation = wc_get_product($variation_id);

			// If base price is intentionally set to exactly zero...
			if ($this->base_price == 0 && MT2MBA_ALLOW_ZERO === 'yes') {
				// Clean up any existing markup description
					$description = "";
					if ($this->price_type === REGULAR_PRICE) {
						$description = $variation->get_description();
						$description = $mt2mba_utility->remove_bracketed_string(PRODUCT_MARKUP_DESC_BEG, PRODUCT_MARKUP_DESC_END, $description);
					}

					$variation_updates[] = [
						'id' => $variation_id,
						'price' => 0,
						'description' => trim($description)
					];
					continue;    // Exit loop and go onto the next variation
			}

			$variation_price = $this->base_price;

			$markup_description = '';
			$attributes = $variation->get_attributes();
			foreach ($attributes as $attribute_id => $term_id) {
				if (isset($markup_table[$attribute_id][$term_id])) {
					$markup = (float) $markup_table[$attribute_id][$term_id]["markup"];
					$variation_price += $markup;
					if (isset($markup_table[$attribute_id][$term_id]["description"])) {
						$markup_description .= PHP_EOL . $markup_table[$attribute_id][$term_id]["description"];
					}
				}
			}	//	END: foreach ($attributes as $attribute_id => $term_id)

			//	Set variation price to null if negative, allow zero pricing
			if ($variation_price < 0) {
				$variation_price = null;
			}

			// Set the description
			$description = "";
			if ($this->price_type === REGULAR_PRICE) {
				if (MT2MBA_DESC_BEHAVIOR !== "overwrite") {
					$description = $variation->get_description();
					$description = $mt2mba_utility->remove_bracketed_string(PRODUCT_MARKUP_DESC_BEG, PRODUCT_MARKUP_DESC_END, $description);
				}
				if ($markup_description && $variation_price != null) {
					$description .= PHP_EOL . PRODUCT_MARKUP_DESC_BEG . $base_price_description . $markup_description . PRODUCT_MARKUP_DESC_END;
				}
			}

			// And plug it into the $variation_updates array
			$variation_updates[] = [
				'id' => $variation_id,
				'price' => $variation_price,
				'description' => trim($description)
			];
		}	// END: foreach ($variations as $variation_id)

		// Bulk update all variations from the variations_update table
		if (!empty($variation_updates)) {
			// Bulk update the variation prices and descriptions
			$this->bulk_variation_update($variation_updates);
		}
	}

}

/**
 * Concrete class for handling product price increase/decrease, which extends
 * PriceMarkupHandler and overrides its abstract methods.
 */
class PriceUpdateHandler extends PriceMarkupHandler {
	/**
	 * PriceUpdateHandler constructor.
	 * @param	string	$bulk_action	The selection from the variation bulk actions menu.
	 * @param	array	$data			Values passed in from JScript pop-up.
	 * @param	string	$product_id		ID of the variable product.
	 * @param	array	$variations		List of variation IDs for the variable product.
	 */
	public function __construct($bulk_action, $data, $product_id, $variations) {
		parent::__construct($bulk_action, $product_id, is_numeric($data["value"]) ? (float) $data["value"] : 0);
	}

	/**
	 * reapply base price based on bulk action and markup.
	 * @param	string	$bulk_action	The selection from the variation bulk actions menu.
	 * @param	string	$markup			The amount or percentage to increase or decrease by.
	 * @param	float	$base_price		The original base price that we are changing.
	 * @return	float					The new base price (before markup).
	 */
	private function recalc_base_price($bulk_action, $markup, $base_price) {
		// Indicate whether we are increasing or decreasing
		$signed_data = strpos($bulk_action, "decrease") ? 0 - floatval($markup) : floatval($markup);

		// Calc based on whether it is a percentage or fixed number
		if (strpos($markup, "%")) {
			return $base_price + ($base_price * $signed_data) / 100;
		} else {
			return $base_price + $signed_data;
		}
	}

	/**
	 * Increase or decrease product price and apply markup.
	 * @param	string	$bulk_action	The selection from the variation bulk actions menu.
	 * @param	array	$data			Values passed in from JScript pop-up.
	 * @param	string	$product_id		ID of the variable product.
	 * @param	array	$variations 	List of variation IDs for the variable product.
	 */
	public function applyMarkup($bulk_action, $data, $product_id, $variations) {
		// If base price metadata is present, that means the product contains variables with attribute pricing.
		$base_price = get_metadata("post", $product_id, "mt2mba_base_{$this->price_type}", true);
		if ($base_price) {
			// reapply a new base price according to the bulk action.
			// Bulk action could be any of
			//	 * variable_regular_price_increase
			//	 * variable_regular_price_decrease
			//	 * variable_sale_price_increase
			//	 * variable_sale_price_decrease
			$new_data = [];
			$new_data["value"] = $this->recalc_base_price($bulk_action, $data["value"], $base_price);
			// And then loop back through changing the bulk action type to one of the two 'set price' options.
			// This will reset the prices on all variations to the new base regular/sale price plus the
			// attribute markup.
			//	 * variable_regular_price
			//	 * variable_sale_price
			$handler = new PriceSetHandler("variable_{$this->price_type}", $new_data, $product_id, $variations);
			$handler->applyMarkup($bulk_action, $data, $product_id, $variations);
		}
	}
}

/**
 * Concrete class for handling product markup deletion, which extends
 * PriceMarkupHandler and overrides its abstract methods.
 */
class MarkupDeleteHandler extends PriceMarkupHandler {
	/**
	 * MarkupDeleteHandler constructor. Does nothing (required to prevent parent::__construct() from firing).
	 * @param	string	$var1		Empty string to satisfy $handler->applyMarkup().
	 * @param	string	$var2		Empty string to satisfy $handler->applyMarkup().
	 * @param	string	$product_id	The product whose metadata is to be deleted.
	 * @param	array	$var4		Empty array to satisfy $handler->applyMarkup().
	 */
	public function __construct($var1, $var2, $product_id, $var4) {
		// Nothing here (required to prevent parent::__construct() from firing)
	}

	/**
	 * Delete all Markup-by-Attribute metadata for product whose variations are deleted
	 * @param	string	$var1		Empty string to satisfy $handler->applyMarkup().
	 * @param	string	$var2		Empty string to satisfy $handler->applyMarkup().
	 * @param	string	$product_id The product whose metadata is to be deleted.
	 * @param	array	$var4		Empty array to satisfy $handler->applyMarkup().
	 */
	public function applyMarkup($var1, $var2, $product_id, $var4) {
		// Delete all Markup-by-Attribute metadata for product
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->postmeta} WHERE post_id = '{$product_id}' AND meta_key LIKE 'mt2mba_%'"
		);
	}
}

/**
 * Main class for handling product backend actions, such as hooking into WordPress and WooCommerce
 * to apply markup to product prices based on various bulk actions.
 */
class Product {
	/**
	 * Initialization method visible before instantiation.
	 */
	public function __construct() {
		// Override the max variation threshold with value from settings
		if (!defined("WC_MAX_LINKED_VARIATIONS")) {
			define("WC_MAX_LINKED_VARIATIONS", MT2MBA_MAX_VARIATIONS);
		}
	
		// Hook mt2mba markup code into bulk actions
		add_action("woocommerce_bulk_edit_variations", [$this, "mt2mba_apply_markup_to_price"], 10, 4);
	
		// Add action to enqueue reapply markup JavaScript
		add_action('admin_enqueue_scripts', [$this, 'ajax_enqueue_reapply_markups_js']);
		
		// Add AJAX handlers for reapply markup
		add_action('wp_ajax_mt2mba_reapply_markup', [$this, 'ajax_handle_reapply_markup'], 10, 1);

		// In Product class constructor
		add_action('wp_ajax_mt2mba_get_formatted_price', [$this, 'ajax_get_formatted_price']);
	}

	/**
	 * Enqueue the reapply markup JavaScript file and required dependencies.
	 * Sets up all necessary localization data including security nonces for both
	 * our custom markup recalculation and WooCommerce's variation loading.
	 *
	 * @param string $hook The current admin page hook
	 */
	public function ajax_enqueue_reapply_markups_js($hook) {
		// Only load on product edit page
		if (!in_array($hook, ['post.php', 'post-new.php'])) {
			return;
		}
		
		// Only load for product post type
		if (get_post_type() !== 'product') {
			return;
		}
		
		// Get the product
		$product = wc_get_product(get_the_ID());
		
		// Only load for variable products
		if ($product && $product->is_type('variable')) {
			wp_enqueue_script(
				'mt2mba-reapply-markup',
				plugins_url('js/jq-mt2mba-reapply-markups-product.js', dirname(__FILE__)),
				['jquery', 'wc-admin-variation-meta-boxes'],
				MT2MBA_VERSION,
				true
			);
	
			// Get base price in store currency format
			$base_price = get_post_meta($product->get_id(), 'mt2mba_base_regular_price', true);
			$formatted_price = strip_tags(wc_price($base_price));

			wp_localize_script(
				'mt2mba-reapply-markup',
				'mt2mbaLocal',
				array(
					'ajaxUrl' => admin_url('admin-ajax.php'),
					'security' => wp_create_nonce('mt2mba_reapply_markup'),
					'variationsNonce' => wp_create_nonce('load-variations'),
					'i18n' => array(
						'reapplyMarkups' => __('Reapply markups to prices', 'markup-by-attribute'),
						'confirmReapply' => __('Reprice variations at %s, plus or minus the markups?', 'markup-by-attribute'),
						'failedRecalculating' => __('Failed to reapply markups. Please try again.', 'markup-by-attribute')
					)
				)
			);
		}
	}

	/**
	 * Handle the AJAX request to reapply markup
	 * 
	 * @param int $product_id Optional product ID for bulk operations
	 */
	public function ajax_handle_reapply_markup() {
		try {
			// Basic validation checks
			if (!check_ajax_referer('mt2mba_reapply_markup', 'security', false)) {
				wp_send_json_error(['message' => __('Permission denied', 'markup-by-attribute')]);
				return;
			}
			
			if (!current_user_can('edit_products')) {
				wp_send_json_error(['message' => __('Permission denied', 'markup-by-attribute')]);
				return;
			}
	
			// Get and validate product
			$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
			if (!$product_id || !($product = wc_get_product($product_id)) || !$product->is_type('variable')) {
				wp_send_json_error(['message' => __('Invalid product ID', 'markup-by-attribute')]);
				return;
			}
	
			// Start transaction
			global $wpdb;
			$wpdb->query('START TRANSACTION');
	
			try {
				// Process variations
				$variations = $product->get_children();
				if (!empty($variations)) {
					// Handle regular price
					$base_regular_price = get_post_meta($product_id, 'mt2mba_base_regular_price', true);
					$data = ['value' => $base_regular_price];
					$handler = new PriceSetHandler('variable_regular_price', $data, $product_id, $variations);
					$handler->applyMarkup('variable_regular_price', $data, $product_id, $variations);
	
					// Handle sale price if it exists
					$base_sale_price = get_post_meta($product_id, 'mt2mba_base_sale_price', true);
					if (!empty($base_sale_price)) {
						$data = ['value' => $base_sale_price];
						$handler = new PriceSetHandler('variable_sale_price', $data, $product_id, $variations);
						$handler->applyMarkup('variable_sale_price', $data, $product_id, $variations);
					}
				}
	
				$wpdb->query('COMMIT');
				
				// Clear WordPress cache
				wp_cache_flush();
				clean_post_cache($product_id);
				
				// Clear WooCommerce specific caches
				wc_delete_product_transients($product_id);
				if (!empty($variations)) {
					foreach ($variations as $variation_id) {
						clean_post_cache($variation_id);
						wc_delete_product_transients($variation_id);
					}
				}
				
				// Clear variable product price cache
				delete_transient('wc_var_prices_' . $product_id);
				
				// Delete WooCommerce's variation parent price meta
				delete_post_meta($product_id, '_price');
				delete_post_meta($product_id, '_min_variation_price');
				delete_post_meta($product_id, '_max_variation_price');
				delete_post_meta($product_id, '_min_variation_regular_price');
				delete_post_meta($product_id, '_max_variation_regular_price');
				delete_post_meta($product_id, '_min_variation_sale_price');
				delete_post_meta($product_id, '_max_variation_sale_price');
	
				// Force WooCommerce to recalculate prices
				if (class_exists('\WC_Product_Variable')) {
					\WC_Product_Variable::sync($product_id);
				}
	
				wp_send_json_success(['completed' => true]);
	
			} catch (Exception $e) {
				$wpdb->query('ROLLBACK');
				throw $e;
			}
	
		} catch (Exception $e) {
			wp_send_json_error(['message' => $e->getMessage()]);
		}
	}

	public function ajax_get_formatted_price() {
		check_ajax_referer('mt2mba_reapply_markup', 'security');
		
		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		if (!$product_id) {
			wp_send_json_error();
			return;
		}
	
		// Check transient first
		$base_price = get_transient('mt2mba_current_base_' . $product_id);
		if ($base_price === false) {
			// Fall back to stored meta
			$base_price = get_post_meta($product_id, 'mt2mba_base_regular_price', true);
		}
	
		wp_send_json_success([
			'formatted_price' => html_entity_decode(strip_tags(wc_price($base_price)))
		]);
	}

	/**
	 * Hook into woocommerce_bulk_edit_variations and adjust price after setting new one.
	 * @param	string	$bulk_action	The selection from the variation bulk actions menu.
	 * @param	array	$data			Values passed in from JScript pop-up.
	 * @param	string	$product_id		ID of the variable product.
	 * @param	array	$variations		List of variation IDs for the variable product.
	 */
	public function mt2mba_apply_markup_to_price($bulk_action, $data, $product_id, $variations) {
		// Determine which class should extend PriceMarkupHandler based on the bulk_action
		if ($bulk_action == "variable_regular_price" || $bulk_action == "variable_sale_price") {
			// Set either the regular price or the sale price
			$handler = new PriceSetHandler($bulk_action, $data, $product_id, $variations);

		} elseif (strpos($bulk_action, "_price_increase") || strpos($bulk_action, "_price_decrease")) {
			// Increase or decrease the regular price or the sale price
			$handler = new PriceUpdateHandler($bulk_action, $data, $product_id, $variations);

		} elseif ($bulk_action == "delete_all") {
			// Delete all markup metadata for product
			$handler = new MarkupDeleteHandler("", [], $product_id, []);

		} else {
			// If none of the above, leave and don't execute $handler
			return;
		}

		// Invoke the applyMarkup() function from the class that was decided above
		$handler->applyMarkup((string) $bulk_action, (array) $data, (string) $product_id, (array) $variations);
	}
}
?>