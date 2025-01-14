<?php
/**
 * Class file for Fieldmanager_Datasource_Term
 *
 * @package Fieldmanager
 */

/**
 * Datasource to populate autocomplete and option fields with WordPress terms.
 */
class Fieldmanager_Datasource_Term extends Fieldmanager_Datasource {

	/**
	 * Taxonomy name or array of taxonomy names.
	 *
	 * @var string|array
	 */
	public $taxonomy = null;

	/**
	 * Helper for taxonomy-based option sets; arguments to find terms.
	 *
	 * @var array
	 */
	public $taxonomy_args = array();

	/**
	 * Sort taxonomy hierarchically and indent child categories with dashes?
	 *
	 * @var bool
	 */
	public $taxonomy_hierarchical = false;

	/**
	 * How far to descend into taxonomy hierarchy (0 for no limit).
	 *
	 * @var int
	 */
	public $taxonomy_hierarchical_depth = 0;

	/**
	 * Pass $append = true to wp_set_object_terms?
	 *
	 * @var bool
	 */
	public $append_taxonomy = false;

	/**
	 * If true, additionally save taxonomy terms to WP's terms tables.
	 *
	 * @var string
	 */
	public $taxonomy_save_to_terms = true;

	/**
	 * If true, only save this field to the taxonomy tables, and do not serialize in the FM array.
	 *
	 * @var string
	 */
	public $only_save_to_taxonomy = false;

	/**
	 * If true, store the term_taxonomy_id instead of the term_id.
	 *
	 * @var bool
	 */
	public $store_term_taxonomy_id = false;

	/**
	 * Build this datasource using Ajax.
	 *
	 * @var bool
	 */
	public $use_ajax = true;

	/**
	 * Constructor.
	 *
	 * @param array $options The options for the term datasource.
	 */
	public function __construct( $options = array() ) {
		global $wp_taxonomies;

		// Default to showing empty tags, which generally makes more sense for the types of fields
		// that fieldmanager supports.
		if ( ! isset( $options['taxonomy_args']['hide_empty'] ) ) {
			$options['taxonomy_args']['hide_empty'] = false;
		}

		parent::__construct( $options );

		// Ensure that $taxonomy_save_to_terms is true if it needs to be.
		if ( $this->only_save_to_taxonomy ) {
			$this->taxonomy_save_to_terms = true;
		}

		if ( $this->taxonomy_save_to_terms ) {
			// Ensure that the taxonomies are sortable if we're not using FM storage.
			foreach ( $this->get_taxonomies() as $taxonomy ) {
				if ( ! empty( $wp_taxonomies[ $taxonomy ] ) ) {
					$wp_taxonomies[ $taxonomy ]->sort = true;
				}
			}
		}
	}

	/**
	 * Get taxonomies; normalizes $this->taxonomy to an array.
	 *
	 * @return array Taxonomies.
	 */
	public function get_taxonomies() {
		return is_array( $this->taxonomy ) ? $this->taxonomy : array( $this->taxonomy );
	}

	/**
	 * Get an action to register by hashing (non cryptographically for speed)
	 * the options that make this datasource unique.
	 *
	 * @return string ajax action
	 */
	public function get_ajax_action() {
		if ( ! empty( $this->ajax_action ) ) {
			return $this->ajax_action;
		}
		$unique_key  = wp_json_encode( $this->taxonomy_args );
		$unique_key .= wp_json_encode( $this->get_taxonomies() );
		$unique_key .= (string) $this->taxonomy_hierarchical;
		$unique_key .= (string) $this->taxonomy_hierarchical_depth;
		$unique_key .= get_called_class();
		return 'fm_datasource_term_' . crc32( $unique_key );
	}

	/**
	 * Unique among FM types, the taxonomy datasource can store data outside FM's array.
	 * This is how we add it back into the array for editing.
	 *
	 * @param  Fieldmanager_Field $field  The base field.
	 * @param  array              $values The loaded values.
	 * @return array $values Loaded up, if applicable.
	 */
	public function preload_alter_values( Fieldmanager_Field $field, $values ) {
		if ( $this->only_save_to_taxonomy ) {
			$taxonomies = $this->get_taxonomies();
			$terms      = get_terms(
				array(
					'object_ids' => array( $field->data_id ),
					'orderby'    => 'term_order',
					'taxonomy'   => array( $taxonomies[0] ),
				)
			);

			// If not found, bail out.
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				return array();
			}

			if ( count( $terms ) > 0 ) {
				// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- baseline
				if ( 1 == $field->limit && empty( $field->multiple ) ) {
					return $terms[0]->term_id;
				} else {
					$ret = array();
					foreach ( $terms as $term ) {
						$ret[] = $term->term_id;
					}
					return $ret;
				}
			}
		}
		return $values;
	}

	/**
	 * Sort function for `get_the_terms` result set.
	 *
	 * @deprecated 1.2.2 Handled with get_terms() in Fieldmanager_Datasource_Term::preload_alter_values().
	 *
	 * @param  WP_Term $term_a First term.
	 * @param  WP_Term $term_b Second term.
	 * @return bool Whether or not it is in order.
	 */
	public function sort_terms( $term_a, $term_b ) {
		if ( $term_a === $term_b ) {
			return 0;
		}
		return ( $term_a->term_order < $term_b->term_order ) ? -1 : 1;
	}

	/**
	 * Presave hook to set taxonomy data.
	 *
	 * @param  Fieldmanager_Field $field          The base field.
	 * @param  array              $values         The new values.
	 * @param  array              $current_values The current values.
	 * @return array $values The sanitized values.
	 */
	public function presave_alter_values( Fieldmanager_Field $field, $values, $current_values ) {
		if ( ! is_array( $values ) ) {
			$values = array( $values );
		}

		// maybe we can create terms here.
		if ( $field instanceof \Fieldmanager_Autocomplete && ! $field->exact_match && isset( $this->taxonomy ) ) {
			foreach ( $values as $i => $value ) {
				// could be a mix of valid term IDs and new terms.
				if ( is_numeric( $value ) ) {
					continue;
				}

				/**
				 * The JS adds an '=' to the front of numeric values if it's not
				 * a found term to prevent problems with new numeric terms.
				 */
				if ( '=' === substr( $value, 0, 1 ) ) {
					$value = sanitize_text_field( substr( $value, 1 ) );
				}

				$term = get_term_by( 'name', $value, $this->taxonomy );

				if ( ! $term ) {
					$term = wp_insert_term( $value, $this->taxonomy );
					if ( is_wp_error( $term ) ) {
						unset( $value );
						continue;
					}
					$term = (object) $term;
				}
				$values[ $i ] = $term->term_id;
			}
		}

		// If this is a taxonomy-based field, must also save the value(s) as an object term.
		if ( $this->taxonomy_save_to_terms && isset( $this->taxonomy ) && ! empty( $values ) ) {
			$tax_values = array();
			foreach ( $values as $value ) {
				if ( ! empty( $value ) ) {
					if ( is_numeric( $value ) ) {
						$tax_values[] = $value;
					} elseif ( is_array( $value ) ) {
						$tax_values = $value;
					}
				}
			}
			$this->pre_save_taxonomy( $tax_values, $field );
		}
		if ( $this->only_save_to_taxonomy ) {
			if ( empty( $values ) && ! ( $this->append_taxonomy ) ) {
				$this->pre_save_taxonomy( array(), $field );
			}
			return array();
		}
		return $values;
	}

	/**
	 * Sanitize a value.
	 *
	 * @param  Fieldmanager_Field $field         The base field.
	 * @param  array              $value         The new values.
	 * @param  array              $current_value The current values.
	 * @return array $values The sanitized values.
	 */
	public function presave( Fieldmanager_Field $field, $value, $current_value ) {
		return empty( $value ) ? $value : intval( $value );
	}

	/**
	 * Save taxonomy data.
	 *
	 * @param mixed              $tax_values The taxonomies.
	 * @param Fieldmanager_Field $field      The base field.
	 */
	public function pre_save_taxonomy( $tax_values, $field ) {

		$tax_values = array_map( 'intval', $tax_values );
		$tax_values = array_unique( $tax_values );
		$taxonomies = $this->get_taxonomies();

		$tree          = $field->get_form_tree();
		$oldest_parent = array_shift( $tree );

		// Not sure why this is erroring, but clear it up.
		if (! isset( $oldest_parent->current_context ) ) {
			return;
		}

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! isset( $oldest_parent->current_context->taxonomies_to_save[ $taxonomy ] ) ) {
				$oldest_parent->current_context->taxonomies_to_save[ $taxonomy ] = array(
					'term_ids' => array(),
					'append'   => $this->append_taxonomy,
				);
			} else {
				// Append any means append all.
				$oldest_parent->current_context->taxonomies_to_save[ $taxonomy ]['append'] = $oldest_parent->current_context->taxonomies_to_save[ $taxonomy ]['append'] && $this->append_taxonomy;
			}
		}

		// Store the each term for this post. Handle grouped fields differently since multiple taxonomies are present.
		if ( count( $taxonomies ) > 1 ) {
			// Build the taxonomy insert data.
			$taxonomies_to_save = array();
			foreach ( $tax_values as $term_id ) {
				$term = $this->get_term( $term_id );
				$oldest_parent->current_context->taxonomies_to_save[ $term->taxonomy ]['term_ids'][] = $term_id;
			}
		} else {
			$oldest_parent->current_context->taxonomies_to_save[ $taxonomies[0] ]['term_ids'] = array_merge( $oldest_parent->current_context->taxonomies_to_save[ $taxonomies[0] ]['term_ids'], $tax_values );
		}
	}

	/**
	 * Get taxonomy data per $this->taxonomy_args.
	 *
	 * @param  string $fragment The query string.
	 * @return array The results.
	 */
	public function get_items( $fragment = null ) {

		// If taxonomy_hierarchical is set, assemble recursive term list, then bail out.
		if ( $this->taxonomy_hierarchical ) {
			$tax_args = $this->taxonomy_args;

			// If no part of the hierarchy requested, return everything.
			if ( ! isset( $tax_args['parent'] ) && ! isset( $tax_args['child_of'] ) ) {
				$tax_args['parent'] = 0;
			}

			$tax_args['taxonomy'] = $this->get_taxonomies();
			$parent_terms         = get_terms( $tax_args );

			return $this->build_hierarchical_term_data( $parent_terms, $this->taxonomy_args, 0, array(), $fragment );
		}

		$tax_args = $this->taxonomy_args;
		if ( ! empty( $fragment ) ) {
			$tax_args['search'] = $fragment;
		}
		$tax_args['taxonomy'] = $this->get_taxonomies();
		$terms                = get_terms( $tax_args );

		// If the taxonomy list was an array and group display is set, ensure all terms are grouped by taxonomy.
		// Use the order of the taxonomy array list for sorting the groups to make this controllable for developers.
		// Order of the terms within the groups is already controllable via $taxonomy_args.
		// Skip this entirely if there is only one taxonomy even if group display is set as it would be unnecessary.
		if ( count( $this->get_taxonomies() ) > 1 && $this->grouped && $this->allow_optgroups ) {
			// Group the data.
			$term_groups = array();
			foreach ( $this->get_taxonomies() as $tax ) {
				$term_groups[ $tax ] = array();
			}
			foreach ( $terms as $term ) {
				$term_groups[ $term->taxonomy ][ $term->term_id ] = $term->name;
			}
			return $term_groups;
		}

		// Put the taxonomy data into the proper data structure to be used for display.
		$stack = array();
		foreach ( $terms as $term ) {
			// Store the label for the taxonomy as the group since it will be used for display.
			$key           = $this->store_term_taxonomy_id ? $term->term_taxonomy_id : $term->term_id;
			$stack[ $key ] = $term->name;
		}
		return apply_filters( 'fm_datasource_term_get_items', $stack, $terms, $this, $fragment );
	}

	/**
	 * Helper to support recursive building of a hierarchical taxonomy list.
	 *
	 * @param  array  $parent_terms The parent terms.
	 * @param  array  $tax_args     As used in top-level get_terms() call.
	 * @param  int    $depth        Current recursive depth level.
	 * @param  array  $stack        Current stack.
	 * @param  string $pattern      Optional matching pattern.
	 * @return array $stack Stack of terms or false if no children found.
	 */
	protected function build_hierarchical_term_data( $parent_terms, $tax_args, $depth, $stack = array(), $pattern = '' ) {

		// Walk through each term passed, add it (at current depth) to the data stack.
		foreach ( $parent_terms as $term ) {
			$prefix = '';

			// Prefix term based on depth. For $depth = 0, prefix will remain empty.
			for ( $i = 0; $i < $depth; $i++ ) {
				$prefix .= '--';
			}

			$key           = $this->store_term_taxonomy_id ? $term->term_taxonomy_id : $term->term_id;
			$stack[ $key ] = $prefix . ' ' . $term->name;

			// Find child terms of this. If any, recurse on this function.
			$tax_args['parent'] = $term->term_id;
			if ( ! empty( $pattern ) ) {
				$tax_args['search'] = $pattern;
			}
			$child_terms = get_terms( $this->get_taxonomies(), $tax_args );
			// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- baseline
			if ( 0 == $this->taxonomy_hierarchical_depth || $depth + 1 < $this->taxonomy_hierarchical_depth ) {
				if ( ! empty( $child_terms ) ) {
					$stack = $this->build_hierarchical_term_data( $child_terms, $this->taxonomy_args, $depth + 1, $stack );
				}
			}
		}
		return $stack;
	}

	/**
	 * Translate term id to title, e.g. for autocomplete.
	 *
	 * @param mixed $value The term ID.
	 * @return string The term title.
	 */
	public function get_value( $value ) {
		$id = intval( $value );
		if ( ! $id ) {
			return null;
		}

		$term  = $this->get_term( $id );
		$value = is_object( $term ) ? $term->name : '';
		return apply_filters( 'fm_datasource_term_get_value', $value, $term, $this );
	}

	/**
	 * Get term by ID only, potentially using multiple taxonomies.
	 *
	 * @param int $term_id The term ID.
	 * @return object|null
	 */
	private function get_term( $term_id ) {
		if ( $this->store_term_taxonomy_id ) {
			global $wpdb;

			// Cache the query.
			$cache_key = 'fm_datasource_term_get_term_' . $term_id;
			$term      = wp_cache_get( $cache_key );
			if ( false === $term ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- baseline
				$term = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT t.*, tt.*
					FROM $wpdb->terms AS t  INNER JOIN $wpdb->term_taxonomy AS tt ON t.term_id = tt.term_id
					WHERE tt.term_taxonomy_id = %d LIMIT 1",
						$term_id
					)
				);

				wp_cache_set( $cache_key, $term );
			}
			return $term;
		} else {
			$terms = get_terms(
				$this->get_taxonomies(),
				array(
					'hide_empty' => false,
					'include'    => array( $term_id ),
					'number'     => 1,
				)
			);
			return ! empty( $terms[0] ) ? $terms[0] : null;
		}
	}

	/**
	 * Get link to view a term.
	 *
	 * @param int $value Term ID.
	 * @return string HTML string.
	 */
	public function get_view_link( $value ) {
		$term_link = get_term_link( $this->get_term( $value ) );
		if ( is_string( $term_link ) ) {
			return sprintf(
				' <a target="_new" class="fm-autocomplete-view-link %s" href="%s">%s</a>',
				empty( $value ) ? 'fm-hidden' : '',
				empty( $value ) ? '#' : esc_url( $term_link ),
				esc_html__( 'View', 'fieldmanager' )
			);
		}
		return '';
	}

	/**
	 * Get link to edit a term.
	 *
	 * @param int $value Term ID.
	 * @return string HTML string.
	 */
	public function get_edit_link( $value ) {
		$term = $this->get_term( $value );
		return sprintf(
			'<a target="_new" class="fm-autocomplete-edit-link %s" href="%s">%s</a>',
			empty( $value ) ? 'fm-hidden' : '',
			empty( $value ) ? '#' : esc_url( get_edit_term_link( $term->term_id, $term->taxonomy ) ),
			esc_html__( 'Edit', 'fieldmanager' )
		);
	}

}
