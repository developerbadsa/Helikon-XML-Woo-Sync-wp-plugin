<?php
/**
 * Product and variation sync helpers.
 *
 * @package Helikon_XML_Woo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTX_XML_Woo_Sync_Products {
	/**
	 * State instance.
	 *
	 * @var HTX_XML_Woo_Sync_State
	 */
	private $state;

	/**
	 * Images instance.
	 *
	 * @var HTX_XML_Woo_Sync_Images
	 */
	private $images;

	/**
	 * Parent lookup cache.
	 *
	 * @var array
	 */
	private $parent_cache = array();

	/**
	 * Variation lookup cache.
	 *
	 * @var array
	 */
	private $variation_cache = array();

	/**
	 * Constructor.
	 *
	 * @param HTX_XML_Woo_Sync_State  $state  State service.
	 * @param HTX_XML_Woo_Sync_Images $images Image service.
	 */
	public function __construct( $state, $images ) {
		$this->state  = $state;
		$this->images = $images;
	}

	/**
	 * Sync one grouped batch.
	 *
	 * @param array  $groups    Grouped feed rows.
	 * @param array  $settings  Plugin settings.
	 * @param string $run_token Sync token.
	 * @return array
	 */
	public function sync_groups( $groups, $settings, $run_token ) {
		$summary = $this->get_empty_summary();

		foreach ( $groups as $group ) {
			try {
				$parent_result = $this->upsert_parent( $group, $settings, $run_token );
				if ( is_wp_error( $parent_result ) ) {
					$summary['failed'] += count( $group['items'] );
					$this->state->add_log( $parent_result->get_error_message(), 'error' );
					continue;
				}

				$summary['created']         += $parent_result['created'];
				$summary['updated']         += $parent_result['updated'];
				$summary['parents_created'] += $parent_result['created'];
				$summary['parents_updated'] += $parent_result['updated'];

				foreach ( $group['items'] as $item ) {
					$summary['processed']++;

					$item_result = $this->upsert_variation( $parent_result['parent_id'], $item, $settings, $run_token );
					if ( is_wp_error( $item_result ) ) {
						$summary['failed']++;
						$this->state->add_log( $item_result->get_error_message(), 'error' );
						continue;
					}

					$summary['created']            += $item_result['created'];
					$summary['updated']            += $item_result['updated'];
					$summary['skipped']            += $item_result['skipped'];
					$summary['variations_created'] += $item_result['created'];
					$summary['variations_updated'] += $item_result['updated'];
				}

				if ( method_exists( 'WC_Product_Variable', 'sync' ) ) {
					WC_Product_Variable::sync( $parent_result['parent_id'] );
				}
				wc_delete_product_transients( $parent_result['parent_id'] );
			} catch ( \Throwable $exception ) {
				$summary['failed'] += count( $group['items'] );
				$this->state->add_log(
					sprintf(
						/* translators: 1: group title, 2: error message */
						__( 'Group "%1$s" failed: %2$s', 'helikon-xml-woo-sync' ),
						$group['title'],
						$exception->getMessage()
					),
					'error'
				);
			}
		}

		return $summary;
	}

	/**
	 * Apply missing-item behavior in small batches after a full feed is finished.
	 *
	 * @param string $run_token Sync token.
	 * @param string $action    Missing item action.
	 * @param int    $limit     Batch size.
	 * @return array|WP_Error
	 */
	public function cleanup_missing_variations( $run_token, $action, $limit ) {
		if ( 'ignore' === $action ) {
			return array(
				'complete' => true,
				'count'    => 0,
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => max( 1, absint( $limit ) ),
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => HTX_XML_Woo_Sync_Plugin::META_IS_MANAGED,
						'value' => 'yes',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => HTX_XML_Woo_Sync_Plugin::META_LAST_SYNC_TOKEN,
							'value'   => $run_token,
							'compare' => '!=',
						),
						array(
							'key'     => HTX_XML_Woo_Sync_Plugin::META_LAST_SYNC_TOKEN,
							'compare' => 'NOT EXISTS',
						),
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => HTX_XML_Woo_Sync_Plugin::META_MISSING_MARK_TOKEN,
							'value'   => $run_token,
							'compare' => '!=',
						),
						array(
							'key'     => HTX_XML_Woo_Sync_Plugin::META_MISSING_MARK_TOKEN,
							'compare' => 'NOT EXISTS',
						),
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return array(
				'complete' => true,
				'count'    => 0,
			);
		}

		$count      = 0;
		$parent_ids = array();

		foreach ( $query->posts as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				update_post_meta( $variation_id, HTX_XML_Woo_Sync_Plugin::META_MISSING_MARK_TOKEN, $run_token );
				continue;
			}

			if ( 'outofstock' === $action ) {
				$variation->set_manage_stock( false );
				$variation->set_stock_status( 'outofstock' );
			} elseif ( 'draft' === $action ) {
				$variation->set_status( 'draft' );
			}

			$variation->save();
			update_post_meta( $variation_id, HTX_XML_Woo_Sync_Plugin::META_MISSING_MARK_TOKEN, $run_token );
			$parent_ids[] = $variation->get_parent_id();
			++$count;
		}

		foreach ( array_unique( array_map( 'absint', $parent_ids ) ) as $parent_id ) {
			if ( $parent_id && method_exists( 'WC_Product_Variable', 'sync' ) ) {
				WC_Product_Variable::sync( $parent_id );
				wc_delete_product_transients( $parent_id );
			}
		}

		return array(
			'complete' => count( $query->posts ) < max( 1, absint( $limit ) ),
			'count'    => $count,
		);
	}

	/**
	 * Create or update a variable parent product.
	 *
	 * @param array  $group     Grouped feed rows.
	 * @param array  $settings  Plugin settings.
	 * @param string $run_token Sync token.
	 * @return array|WP_Error
	 */
	private function upsert_parent( $group, $settings, $run_token ) {
		$parent_id = $this->find_parent_id_by_group_key( $group['group_key'], $group['sku_base'] );
		$is_new    = ! $parent_id;
		$product   = $is_new ? new WC_Product_Variable() : wc_get_product( $parent_id );

		if ( ! $product instanceof WC_Product_Variable ) {
			return new WP_Error(
				'htx_parent_type',
				sprintf(
					/* translators: %s: group title */
					__( 'Skipped "%s" because an existing product with the same group key is not a variable product.', 'helikon-xml-woo-sync' ),
					$group['title']
				)
			);
		}

		if ( $group['title'] ) {
			$product->set_name( $group['title'] );
		}

		if ( $group['description'] ) {
			$product->set_description( wp_kses_post( $group['description'] ) );
			$product->set_short_description( wp_kses_post( $group['description'] ) );
		}

		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_attributes( $this->build_parent_attributes( $product, $group['attribute_union'], $this->build_static_attribute_map( $group ) ) );

		if ( $group['sku_base'] ) {
			$this->assign_product_sku( $product, $group['sku_base'], $group['title'] );
		}

		$parent_id = $product->save();
		$this->parent_cache[ $group['group_key'] ] = $parent_id;

		update_post_meta( $parent_id, HTX_XML_Woo_Sync_Plugin::META_GROUP_KEY, $group['group_key'] );
		update_post_meta( $parent_id, HTX_XML_Woo_Sync_Plugin::META_GROUP_EXTERNAL, $group['group_external'] );
		update_post_meta( $parent_id, HTX_XML_Woo_Sync_Plugin::META_SKU_BASE, $group['sku_base'] );
		update_post_meta( $parent_id, HTX_XML_Woo_Sync_Plugin::META_IS_MANAGED, 'yes' );
		update_post_meta( $parent_id, HTX_XML_Woo_Sync_Plugin::META_LAST_SYNC_TOKEN, $run_token );

		$this->update_meta_value( $parent_id, '_htx_brand', $group['brand'] );
		$this->update_meta_value( $parent_id, '_htx_manufacturer', $group['manufacturer'] );
		$this->update_meta_value( $parent_id, '_htx_product_line', $group['product_line'] );
		$this->update_meta_value( $parent_id, '_htx_fabric_name', $group['fabric_name'] );
		$this->update_meta_value( $parent_id, '_htx_fabric_composition', $group['fabric_composition'] );
		$this->update_meta_value( $parent_id, '_htx_hs_code', $this->get_first_non_empty_item_value( $group['items'], 'hs_code' ) );
		$this->sync_parent_categories( $parent_id, $group );
		$this->sync_parent_brand_terms( $parent_id, $group['brand'] );

		$current_image_id = (int) $product->get_image_id();

		if ( $group['main_photo'] ) {
			$image_id = $this->images->import_image( $group['main_photo'], $parent_id, $settings );
			if ( is_wp_error( $image_id ) ) {
				$this->state->add_log(
					sprintf(
						/* translators: 1: product title, 2: message */
						__( 'Featured image kept as-is for "%1$s": %2$s', 'helikon-xml-woo-sync' ),
						$group['title'],
						$image_id->get_error_message()
					),
					'warning'
				);
			} elseif ( $image_id && $current_image_id !== (int) $image_id ) {
				$product->set_image_id( $image_id );
			}
		} elseif ( $current_image_id && $this->images->is_managed_attachment( $current_image_id ) ) {
			$product->set_image_id( 0 );
		}

		$gallery_ids = $this->images->sync_gallery_ids( $product->get_gallery_image_ids(), $group['additional_photos'], $parent_id, $settings );
		$product->set_gallery_image_ids( $gallery_ids );

		$product->save();

		return array(
			'parent_id' => $parent_id,
			'created'   => $is_new ? 1 : 0,
			'updated'   => $is_new ? 0 : 1,
		);
	}

	/**
	 * Create or update a single variation.
	 *
	 * @param int    $parent_id Parent product ID.
	 * @param array  $item      Normalized feed item.
	 * @param array  $settings  Plugin settings.
	 * @param string $run_token Sync token.
	 * @return array|WP_Error
	 */
	private function upsert_variation( $parent_id, $item, $settings, $run_token ) {
		$variation_id = $this->find_variation_id_by_sku( $item['sku'] );
		$is_new       = ! $variation_id;
		$variation    = $is_new ? new WC_Product_Variation() : wc_get_product( $variation_id );

		if ( ! $variation instanceof WC_Product_Variation ) {
			return new WP_Error(
				'htx_variation_type',
				sprintf(
					/* translators: %s: SKU */
					__( 'Skipped SKU %s because the existing product with that SKU is not a variation.', 'helikon-xml-woo-sync' ),
					$item['sku']
				)
			);
		}

		$variation->set_parent_id( $parent_id );
		$variation->set_status( 'publish' );
		try {
			$variation->set_sku( $item['sku'] );
		} catch ( \Exception $e ) {
			$this->state->add_log(
				sprintf(
					/* translators: 1: SKU, 2: error message */
					__( 'Could not set SKU %1$s on variation: %2$s', 'helikon-xml-woo-sync' ),
					$item['sku'],
					$e->getMessage()
				),
				'warning'
			);
		}
		$variation->set_attributes( $this->build_variation_attributes( $item['attributes'] ) );

		if ( '' !== $item['regular_price'] ) {
			$variation->set_regular_price( $item['regular_price'] );
		}

		if ( '' !== $item['sale_price'] ) {
			if ( '' === $variation->get_regular_price( 'edit' ) ) {
				$variation->set_regular_price( $item['sale_price'] );
			}
			$variation->set_sale_price( $item['sale_price'] );
		}

		if ( '' !== $item['stock_qty'] ) {
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( (float) $item['stock_qty'] );
			$variation->set_stock_status( (float) $item['stock_qty'] > 0 ? 'instock' : 'outofstock' );
		} elseif ( '' !== $item['stock_status'] ) {
			$variation->set_manage_stock( false );
			$variation->set_stock_status( $item['stock_status'] );
		}

		if ( '' !== $item['weight'] ) {
			$variation->set_weight( wc_format_decimal( $item['weight'] ) );
		}

		$variation_id = $variation->save();
		$this->variation_cache[ $item['sku'] ] = $variation_id;

		update_post_meta( $variation_id, HTX_XML_Woo_Sync_Plugin::META_IS_MANAGED, 'yes' );
		update_post_meta( $variation_id, HTX_XML_Woo_Sync_Plugin::META_LAST_SYNC_TOKEN, $run_token );
		update_post_meta( $variation_id, HTX_XML_Woo_Sync_Plugin::META_SOURCE_SKU, $item['sku'] );

		$this->update_meta_value( $variation_id, '_htx_erp_id', $item['erp_id'] );
		$this->update_meta_value( $variation_id, '_htx_weight_unit', $item['weight_unit'] );
		$this->update_meta_value( $variation_id, '_htx_ean', $item['ean'] );
		$this->update_meta_value( $variation_id, '_htx_hs_code', $item['hs_code'] );

		if ( method_exists( $variation, 'set_global_unique_id' ) ) {
			try {
				$variation->set_global_unique_id( $item['ean'] );
				$variation->save();
			} catch ( \Throwable $exception ) {
				$this->state->add_log(
					sprintf(
						/* translators: 1: SKU, 2: message */
						__( 'Could not set global unique ID for SKU %1$s: %2$s', 'helikon-xml-woo-sync' ),
						$item['sku'],
						$exception->getMessage()
					),
					'warning'
				);
			}
		}

		$current_image_id = (int) $variation->get_image_id();

		if ( $item['main_photo'] ) {
			$image_id = $this->images->import_image( $item['main_photo'], $variation_id, $settings );
			if ( is_wp_error( $image_id ) ) {
				$this->state->add_log(
					sprintf(
						/* translators: 1: SKU, 2: message */
						__( 'Variation image kept as-is for SKU %1$s: %2$s', 'helikon-xml-woo-sync' ),
						$item['sku'],
						$image_id->get_error_message()
					),
					'warning'
				);
			} elseif ( $image_id && $current_image_id !== (int) $image_id ) {
				$variation->set_image_id( $image_id );
				$variation->save();
			}
		} elseif ( $current_image_id && $this->images->is_managed_attachment( $current_image_id ) ) {
			$variation->set_image_id( 0 );
			$variation->save();
		}

		return array(
			'created' => $is_new ? 1 : 0,
			'updated' => $is_new ? 0 : 1,
			'skipped' => 0,
		);
	}

	/**
	 * Build parent attributes by merging existing local attributes with new values.
	 *
	 * @param WC_Product_Variable $product               Product instance.
	 * @param array               $variation_attribute_map New variation attribute map.
	 * @param array               $display_attribute_map  New display-only attribute map.
	 * @return array
	 */
	private function build_parent_attributes( $product, $variation_attribute_map, $display_attribute_map = array() ) {
		$final_attributes = array();
		$local_values     = array();
		$position         = 0;

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}

			if ( $attribute->is_taxonomy() ) {
				$attribute->set_position( $position++ );
				$final_attributes[] = $attribute;
				continue;
			}

			$name = $attribute->get_name();
			$local_values[ $name ] = array(
				'values'    => array_values(
					array_unique(
						array_filter(
							array_map( 'strval', $attribute->get_options() )
						)
					)
				),
				'visible'   => $attribute->get_visible(),
				'variation' => $attribute->get_variation(),
			);
		}

		foreach ( $variation_attribute_map as $label => $values ) {
			if ( ! isset( $local_values[ $label ] ) ) {
				$local_values[ $label ] = array(
					'values'     => array(),
					'visible'    => true,
					'variation'  => true,
				);
			}

			$local_values[ $label ]['values'] = array_values(
				array_unique(
					array_merge( $local_values[ $label ]['values'], array_filter( array_map( 'strval', (array) $values ) ) )
				)
			);
			$local_values[ $label ]['visible']   = true;
			$local_values[ $label ]['variation'] = true;
		}

		foreach ( $display_attribute_map as $label => $values ) {
			if ( ! isset( $local_values[ $label ] ) ) {
				$local_values[ $label ] = array(
					'values'     => array(),
					'visible'    => true,
					'variation'  => false,
				);
			}

			$local_values[ $label ]['values'] = array_values(
				array_unique(
					array_merge( $local_values[ $label ]['values'], array_filter( array_map( 'strval', (array) $values ) ) )
				)
			);
			$local_values[ $label ]['visible'] = true;
		}

		foreach ( $local_values as $label => $config ) {
			if ( empty( $config['values'] ) ) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $label );
			$attribute->set_options( array_values( $config['values'] ) );
			$attribute->set_position( $position++ );
			$attribute->set_visible( ! empty( $config['visible'] ) );
			$attribute->set_variation( ! empty( $config['variation'] ) );
			$final_attributes[] = $attribute;
		}

		return $final_attributes;
	}

	/**
	 * Build variation attribute payload.
	 *
	 * @param array $attributes Variation attributes.
	 * @return array
	 */
	private function build_variation_attributes( $attributes ) {
		$payload = array();

		foreach ( $attributes as $label => $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$payload[ sanitize_title( $label ) ] = $value;
		}

		return $payload;
	}

	/**
	 * Build display-only product attributes from feed metadata.
	 *
	 * @param array $group Grouped product data.
	 * @return array
	 */
	private function build_static_attribute_map( $group ) {
		$attributes = array();
		$hs_code    = $this->get_first_non_empty_item_value( $group['items'], 'hs_code' );

		foreach (
			array(
				'Brand'        => $group['brand'],
				'Manufacturer' => $group['manufacturer'],
				'Product Line' => $group['product_line'],
				'Fabric'       => $group['fabric_name'],
				'Composition'  => $group['fabric_composition'],
				'HS Code'      => $hs_code,
			) as $label => $value
		) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$attributes[ $label ] = array( $value );
			}
		}

		return $attributes;
	}

	/**
	 * Assign product categories from product line, with brand as a fallback.
	 *
	 * @param int   $parent_id Parent product ID.
	 * @param array $group     Grouped product data.
	 * @return void
	 */
	private function sync_parent_categories( $parent_id, $group ) {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}

		$categories = array();
		if ( ! empty( $group['product_line'] ) ) {
			$categories[] = $group['product_line'];
		} elseif ( ! empty( $group['brand'] ) ) {
			$categories[] = $group['brand'];
		}

		$term_ids = array();
		foreach ( array_unique( array_filter( array_map( 'strval', $categories ) ) ) as $category_name ) {
			$term_id = $this->ensure_term( 'product_cat', $category_name );
			if ( $term_id ) {
				$term_ids[] = $term_id;
			}
		}

		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $parent_id, $term_ids, 'product_cat', false );
		}
	}

	/**
	 * Assign the supplier brand into any supported brand taxonomy.
	 *
	 * @param int    $parent_id Parent product ID.
	 * @param string $brand     Brand label.
	 * @return void
	 */
	private function sync_parent_brand_terms( $parent_id, $brand ) {
		$brand = trim( (string) $brand );

		foreach ( $this->get_supported_brand_taxonomies() as $taxonomy ) {
			wp_set_object_terms( $parent_id, '' !== $brand ? array( $brand ) : array(), $taxonomy, false );
		}
	}

	/**
	 * Get supported brand taxonomies registered on the site.
	 *
	 * @return array
	 */
	private function get_supported_brand_taxonomies() {
		$taxonomies = array();

		foreach ( array( 'product_brand', 'product_brands', 'pwb-brand', 'yith_product_brand', 'berocket_brand', 'pa_brand', 'pa_brands' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$taxonomies[] = $taxonomy;
			}
		}

		return $taxonomies;
	}

	/**
	 * Create or fetch a term in the given taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $name     Term label.
	 * @return int
	 */
	private function ensure_term( $taxonomy, $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}

		$existing = term_exists( $name, $taxonomy );
		if ( is_array( $existing ) && ! empty( $existing['term_id'] ) ) {
			return (int) $existing['term_id'];
		}

		if ( is_int( $existing ) ) {
			return (int) $existing;
		}

		$created = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $created ) ) {
			if ( 'term_exists' === $created->get_error_code() ) {
				$term_id = $created->get_error_data();
				if ( is_array( $term_id ) && ! empty( $term_id['term_id'] ) ) {
					return (int) $term_id['term_id'];
				}
				if ( is_numeric( $term_id ) ) {
					return (int) $term_id;
				}
			}

			$this->state->add_log(
				sprintf(
					/* translators: 1: taxonomy slug, 2: term name, 3: error message */
					__( 'Could not create %1$s term "%2$s": %3$s', 'helikon-xml-woo-sync' ),
					$taxonomy,
					$name,
					$created->get_error_message()
				),
				'warning'
			);

			return 0;
		}

		return ! empty( $created['term_id'] ) ? (int) $created['term_id'] : 0;
	}

	/**
	 * Get the first non-empty value from a grouped item list.
	 *
	 * @param array  $items Item rows.
	 * @param string $key   Item array key.
	 * @return string
	 */
	private function get_first_non_empty_item_value( $items, $key ) {
		foreach ( (array) $items as $item ) {
			if ( empty( $item[ $key ] ) ) {
				continue;
			}

			return trim( (string) $item[ $key ] );
		}

		return '';
	}

	/**
	 * Update or delete a product meta value.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Meta key.
	 * @param string $value     Meta value.
	 * @return void
	 */
	private function update_meta_value( $object_id, $meta_key, $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			delete_post_meta( $object_id, $meta_key );
			return;
		}

		update_post_meta( $object_id, $meta_key, $value );
	}

	/**
	 * Find a parent by stable group key.
	 *
	 * @param string $group_key Stable group key.
	 * @param string $sku_base  Base SKU.
	 * @return int
	 */
	private function find_parent_id_by_group_key( $group_key, $sku_base ) {
		if ( isset( $this->parent_cache[ $group_key ] ) ) {
			return (int) $this->parent_cache[ $group_key ];
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => HTX_XML_Woo_Sync_Plugin::META_GROUP_KEY,
				'meta_value'     => $group_key,
			)
		);

		if ( ! empty( $ids ) ) {
			$this->parent_cache[ $group_key ] = (int) $ids[0];
			return (int) $ids[0];
		}

		if ( '' !== $sku_base ) {
			$legacy_ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'private', 'draft' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'   => HTX_XML_Woo_Sync_Plugin::META_SKU_BASE,
							'value' => $sku_base,
						),
						array(
							'key'   => HTX_XML_Woo_Sync_Plugin::META_IS_MANAGED,
							'value' => 'yes',
						),
					),
				)
			);

			if ( ! empty( $legacy_ids ) ) {
				$this->parent_cache[ $group_key ] = (int) $legacy_ids[0];
				return (int) $legacy_ids[0];
			}
		}

		return 0;
	}

	/**
	 * Find a variation ID by SKU.
	 *
	 * @param string $sku Variation SKU.
	 * @return int
	 */
	private function find_variation_id_by_sku( $sku ) {
		if ( isset( $this->variation_cache[ $sku ] ) ) {
			return (int) $this->variation_cache[ $sku ];
		}

		$variation_id = wc_get_product_id_by_sku( $sku );
		$this->variation_cache[ $sku ] = $variation_id ? (int) $variation_id : 0;

		return (int) $this->variation_cache[ $sku ];
	}

	/**
	 * Assign a product SKU only when it is safe to do so.
	 *
	 * @param WC_Product $product Product object.
	 * @param string     $sku     Target SKU.
	 * @param string     $label   Product label for logs.
	 * @return void
	 */
	private function assign_product_sku( $product, $sku, $label ) {
		$sku         = trim( (string) $sku );
		$current_sku = (string) $product->get_sku();

		if ( '' === $sku || $current_sku === $sku ) {
			return;
		}

		$existing_id = wc_get_product_id_by_sku( $sku );
		if ( $existing_id && (int) $existing_id !== (int) $product->get_id() ) {
			$this->state->add_log(
				sprintf(
					/* translators: 1: product label, 2: SKU */
					__( 'Parent SKU for "%1$s" was left unchanged because SKU %2$s already exists elsewhere.', 'helikon-xml-woo-sync' ),
					$label,
					$sku
				),
				'warning'
			);
			return;
		}

		try {
			$product->set_sku( $sku );
		} catch ( Exception $exception ) {
			$this->state->add_log(
				sprintf(
					/* translators: 1: product label, 2: error message */
					__( 'Could not set parent SKU for "%1$s": %2$s', 'helikon-xml-woo-sync' ),
					$label,
					$exception->getMessage()
				),
				'warning'
			);
		}
	}

	/**
	 * Get an empty batch summary.
	 *
	 * @return array
	 */
	private function get_empty_summary() {
		return array(
			'processed'          => 0,
			'created'            => 0,
			'updated'            => 0,
			'skipped'            => 0,
			'failed'             => 0,
			'parents_created'    => 0,
			'parents_updated'    => 0,
			'variations_created' => 0,
			'variations_updated' => 0,
		);
	}
}
