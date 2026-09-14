<?php
/**
 * Auto-assign slugs while a post is saved.
 *
 * @package PermalinkRuleAssist
 */

namespace PermalinkRuleAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters wp_insert_post_data so new / poor slugs follow the rules.
 */
class Hooks {
	/**
	 * @var Generator
	 */
	private $generator;

	/**
	 * @var Options
	 */
	private $options;

	public function __construct( Generator $generator, Options $options ) {
		$this->generator = $generator;
		$this->options   = $options;
	}

	public function register(): void {
		add_filter( 'wp_insert_post_data', array( $this, 'filterInsertPostData' ), 20, 2 );
		add_action( 'save_post', array( $this, 'maybeLockAfterPublish' ), 20, 2 );
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 * @return array<string, mixed>
	 */
	public function filterInsertPostData( array $data, array $postarr ): array {
		if ( ! empty( $GLOBALS[ Applier::APPLYING ] ) ) {
			return $data;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}
		if ( (string) $this->options->get( 'autoOnSave', '1' ) !== '1' ) {
			return $data;
		}

		$postType = sanitize_key( (string) ( $data['post_type'] ?? '' ) );
		if ( ! in_array( $postType, $this->options->postTypes(), true ) ) {
			return $data;
		}
		$status = sanitize_key( (string) ( $data['post_status'] ?? '' ) );
		if ( in_array( $status, array( 'inherit', 'trash', 'auto-draft' ), true ) ) {
			return $data;
		}

		$postId = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $postId > 0 && $this->generator->isLocked( $postId ) ) {
			$incoming = (string) ( $data['post_name'] ?? '' );
			if ( ! $this->generator->isPoorSlug( $incoming ) ) {
				return $data;
			}
		}

		$incoming = (string) ( $data['post_name'] ?? '' );
		$title    = trim( wp_strip_all_tags( (string) ( $data['post_title'] ?? '' ) ) );
		if ( $title === '' ) {
			return $data;
		}
		$isPoor   = $this->generator->isPoorSlug( $incoming );
		$rewritePoor = (string) $this->options->get( 'rewritePoorSlugs', '1' ) === '1';
		if ( ! $isPoor && $status === 'publish' && $incoming !== '' ) {
			return $data;
		}
		if ( ! $isPoor && ! $rewritePoor && $incoming !== '' ) {
			return $data;
		}
		if ( $isPoor && ! $rewritePoor && $status === 'publish' ) {
			return $data;
		}

		$virtual = $this->virtualPost( $data, $postarr );
		$rule    = $this->generator->matchRule(
			$virtual,
			$this->categorySlugsFromPostarr( $postarr, $virtual->ID ),
			$this->tagSlugsFromPostarr( $postarr, $virtual->ID )
		);
		if ( $rule === null ) {
			error_log( 'Permalink Rule Assist: no rule matched while saving post ' . $postId );
			return $data;
		}
		$slug = $this->generator->expandTemplate( (string) $rule['template'], $virtual );
		if ( $slug === '' ) {
			error_log( 'Permalink Rule Assist: generated an empty slug for post ' . $postId );
			return $data;
		}
		$unique = wp_unique_post_slug(
			$slug,
			$postId,
			$status,
			$postType,
			isset( $data['post_parent'] ) ? (int) $data['post_parent'] : 0
		);
		$data['post_name'] = $unique;
		return $data;
	}

	public function maybeLockAfterPublish( int $postId, \WP_Post $post ): void {
		if ( ! empty( $GLOBALS[ Applier::APPLYING ] ) ) {
			return;
		}
		if ( (string) $this->options->get( 'lockAfterPublish', '1' ) !== '1' ) {
			return;
		}
		if ( $post->post_status !== 'publish' ) {
			return;
		}
		if ( ! in_array( $post->post_type, $this->options->postTypes(), true ) ) {
			return;
		}
		if ( $this->generator->isPoorSlug( (string) $post->post_name ) ) {
			return;
		}
		$existing = get_post_meta( $postId, '_permalink_rule_locked', true );
		if ( $existing === '0' || $existing === '1' ) {
			return;
		}
		update_post_meta( $postId, '_permalink_rule_locked', '1' );
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 */
	private function virtualPost( array $data, array $postarr ): \WP_Post {
		$post               = new \WP_Post( (object) array() );
		$post->ID           = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$post->post_title   = (string) ( $data['post_title'] ?? '' );
		$post->post_name    = (string) ( $data['post_name'] ?? '' );
		$post->post_status  = (string) ( $data['post_status'] ?? '' );
		$post->post_type    = (string) ( $data['post_type'] ?? 'post' );
		$post->post_parent  = isset( $data['post_parent'] ) ? (int) $data['post_parent'] : 0;
		$post->post_date    = (string) ( $data['post_date'] ?? '' );
		if ( $post->post_date === '' || strpos( $post->post_date, '0000-00-00' ) === 0 ) {
			$post->post_date = current_time( 'mysql' );
		}
		return $post;
	}

	/**
	 * @param array<string, mixed> $postarr
	 * @return array<int, string>|null
	 */
	private function categorySlugsFromPostarr( array $postarr, int $postId ): ?array {
		$ids = array();
		if ( isset( $postarr['post_category'] ) && is_array( $postarr['post_category'] ) ) {
			foreach ( $postarr['post_category'] as $termId ) {
				$ids[] = (int) $termId;
			}
		}
		if ( isset( $postarr['tax_input']['category'] ) ) {
			$ids = array_merge( $ids, $this->termIdsFromTaxInput( $postarr['tax_input']['category'], 'category' ) );
		}
		if ( $ids === array() ) {
			return null;
		}
		return $this->slugsFromTermIds( array_unique( $ids ), 'category', $postId );
	}

	/**
	 * @param array<string, mixed> $postarr
	 * @return array<int, string>|null
	 */
	private function tagSlugsFromPostarr( array $postarr, int $postId ): ?array {
		$slugs = array();
		if ( isset( $postarr['tags_input'] ) ) {
			$slugs = array_merge( $slugs, $this->slugsFromTagInput( $postarr['tags_input'] ) );
		}
		if ( isset( $postarr['tax_input']['post_tag'] ) ) {
			$fromTax = $postarr['tax_input']['post_tag'];
			if ( is_array( $fromTax ) ) {
				foreach ( $fromTax as $item ) {
					$key = sanitize_title( (string) $item );
					if ( $key !== '' ) {
						$slugs[] = $key;
					}
				}
			} elseif ( is_string( $fromTax ) && $fromTax !== '' ) {
				$slugs = array_merge( $slugs, $this->slugsFromTagInput( $fromTax ) );
			}
		}
		if ( $slugs === array() ) {
			return null;
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * @param mixed $input
	 * @return array<int, int>
	 */
	private function termIdsFromTaxInput( $input, string $taxonomy ): array {
		$ids = array();
		if ( is_string( $input ) ) {
			$input = preg_split( '/[,\s]+/', $input ) ?: array();
		}
		if ( ! is_array( $input ) ) {
			error_log( 'Permalink Rule Assist: tax_input for ' . $taxonomy . ' is not a list.' );
			return array();
		}
		foreach ( $input as $item ) {
			if ( is_numeric( $item ) ) {
				$ids[] = (int) $item;
				continue;
			}
			$term = get_term_by( 'slug', sanitize_title( (string) $item ), $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return $ids;
	}

	/**
	 * @param mixed $input
	 * @return array<int, string>
	 */
	private function slugsFromTagInput( $input ): array {
		if ( is_string( $input ) ) {
			$input = explode( ',', $input );
		}
		if ( ! is_array( $input ) ) {
			error_log( 'Permalink Rule Assist: tags_input is not a list.' );
			return array();
		}
		$slugs = array();
		foreach ( $input as $item ) {
			$key = sanitize_title( trim( (string) $item ) );
			if ( $key !== '' ) {
				$slugs[] = $key;
			}
		}
		return $slugs;
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<int, string>
	 */
	private function slugsFromTermIds( array $ids, string $taxonomy, int $postId ): array {
		$slugs = array();
		foreach ( $ids as $termId ) {
			if ( $termId <= 0 ) {
				continue;
			}
			$term = get_term( $termId, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				error_log( 'Permalink Rule Assist: missing ' . $taxonomy . ' term ' . $termId . ' while saving post ' . $postId );
				continue;
			}
			$slugs[] = (string) $term->slug;
		}
		return $slugs;
	}
}
