<?php
/**
 * Builds an ASCII slug from the first matching rule.
 *
 * @package PermalinkRuleAssist
 */

namespace PermalinkRuleAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule matching and token expansion.
 */
class Generator {
	/**
	 * @var Options
	 */
	private $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Whether a slug is percent-encoded, non-ASCII, empty, or otherwise unusable for SEO.
	 */
	public function isPoorSlug( string $slug ): bool {
		$slug = trim( $slug );
		if ( $slug === '' || $slug === 'auto-draft' ) {
			return true;
		}
		if ( strpos( $slug, '%' ) !== false ) {
			return true;
		}
		if ( preg_match( '/[^\x20-\x7E]/', $slug ) === 1 ) {
			return true;
		}
		if ( preg_match( '/^[0-9]+$/', $slug ) === 1 ) {
			return true;
		}
		return false;
	}

	/**
	 * Preview the slug a post would receive.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function preview( int $postId ) {
		$post = $this->requirePost( $postId );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$matched = $this->matchRule( $post );
		if ( $matched === null ) {
			return new \WP_Error( 'no_rule', '沒有符合的永久連結規則。' );
		}
		$slug = $this->expandTemplate( (string) $matched['template'], $post );
		if ( $slug === '' ) {
			return new \WP_Error( 'empty_slug', '規則產生了空白的 slug，請檢查詞彙表或標題。' );
		}
		$unique = wp_unique_post_slug(
			$slug,
			$post->ID,
			$post->post_status,
			$post->post_type,
			(int) $post->post_parent
		);
		return array(
			'id'           => $post->ID,
			'title'        => (string) $post->post_title,
			'currentSlug'  => (string) $post->post_name,
			'suggestedSlug'=> $unique,
			'ruleName'     => (string) ( $matched['name'] ?? '' ),
			'template'     => (string) $matched['template'],
			'isPoorSlug'   => $this->isPoorSlug( (string) $post->post_name ),
			'locked'       => $this->isLocked( $post->ID ),
			'permalink'    => $this->previewPermalink( $post, $unique ),
		);
	}

	/**
	 * @param array<int, string>|null $categorySlugs
	 * @param array<int, string>|null $tagSlugs
	 * @return array<string, mixed>|null
	 */
	public function matchRule( \WP_Post $post, ?array $categorySlugs = null, ?array $tagSlugs = null ): ?array {
		$categorySlugs = $categorySlugs ?? $this->termSlugs( $post->ID, 'category' );
		$tagSlugs      = $tagSlugs ?? $this->termSlugs( $post->ID, 'post_tag' );
		$title         = (string) $post->post_title;

		foreach ( $this->options->rules() as $rule ) {
			if ( ! is_array( $rule ) ) {
				error_log( 'Permalink Rule Assist: skipped a non-array rule.' );
				continue;
			}
			if ( (string) ( $rule['enabled'] ?? '' ) !== '1' ) {
				continue;
			}
			if ( $this->ruleMatches( $rule, $categorySlugs, $tagSlugs, $title ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Expand template tokens then sanitize to a slug.
	 */
	public function expandTemplate( string $template, \WP_Post $post ): string {
		$date = $this->resolveDate( $post );
		$parts = explode( '-', $date );
		$year  = $parts[0] ?? wp_date( 'Y' );
		$month = $parts[1] ?? wp_date( 'm' );
		$day   = $parts[2] ?? wp_date( 'd' );

		$replacements = array(
			'{date}'     => $date,
			'{year}'     => $year,
			'{month}'    => $month,
			'{day}'      => $day,
			'{ascii}'    => $this->asciiFromTitle( (string) $post->post_title ),
			'{category}' => $this->firstCategorySlug( $post->ID ),
		);
		$raw = strtr( $template, $replacements );
		return $this->sanitizeSlug( $raw );
	}

	public function isLocked( int $postId ): bool {
		return get_post_meta( $postId, '_permalink_rule_locked', true ) === '1';
	}

	/**
	 * @param array<string, mixed> $rule
	 * @param array<int, string>   $categorySlugs
	 * @param array<int, string>   $tagSlugs
	 */
	private function ruleMatches( array $rule, array $categorySlugs, array $tagSlugs, string $title ): bool {
		$categories = $this->csvList( (string) ( $rule['categories'] ?? '' ) );
		$tags       = $this->csvList( (string) ( $rule['tags'] ?? '' ) );
		$titleRegex = trim( (string) ( $rule['titleRegex'] ?? '' ) );

		$hasCondition = $categories !== array() || $tags !== array() || $titleRegex !== '';
		if ( ! $hasCondition ) {
			return true;
		}
		foreach ( $categories as $slug ) {
			if ( in_array( $slug, $categorySlugs, true ) ) {
				return true;
			}
		}
		foreach ( $tags as $slug ) {
			if ( in_array( $slug, $tagSlugs, true ) ) {
				return true;
			}
		}
		if ( $titleRegex !== '' ) {
			$pattern = '/' . $titleRegex . '/u';
			$set     = preg_match( $pattern, $title );
			if ( $set === false ) {
				error_log( 'Permalink Rule Assist: invalid titleRegex: ' . $titleRegex );
				return false;
			}
			if ( $set === 1 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prefer 年月日 in the title so daily stock posts stay aligned with the headline.
	 */
	private function resolveDate( \WP_Post $post ): string {
		$title = (string) $post->post_title;
		$year  = substr( (string) $post->post_date, 0, 4 );
		if ( $year === '' || $year === '0000' ) {
			$year = wp_date( 'Y' );
		}
		if ( preg_match( '/(\d{4})年(\d{1,2})月(\d{1,2})日/u', $title, $match ) === 1 ) {
			return sprintf( '%04d-%02d-%02d', (int) $match[1], (int) $match[2], (int) $match[3] );
		}
		if ( preg_match( '/(\d{1,2})月(\d{1,2})日/u', $title, $match ) === 1 ) {
			return sprintf( '%04d-%02d-%02d', (int) $year, (int) $match[1], (int) $match[2] );
		}
		$timestamp = strtotime( (string) $post->post_date );
		if ( is_int( $timestamp ) && $timestamp > 0 ) {
			return wp_date( 'Y-m-d', $timestamp );
		}
		return wp_date( 'Y-m-d' );
	}

	private function asciiFromTitle( string $title ): string {
		$title = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$cut = preg_replace( '/：.*/u', '', $title );
		if ( is_string( $cut ) && trim( $cut ) !== '' ) {
			$title = $cut;
		}
		foreach ( $this->options->glossaryMap() as $from => $to ) {
			$title = str_replace( $from, ' ' . $to . ' ', $title );
		}
		$title = str_replace( array( '《', '》', '「', '」', '『', '』', '（', '）', '(', ')' ), ' ', $title );

		$stripped = preg_replace( '/\p{Han}+/u', ' ', $title );
		if ( is_string( $stripped ) ) {
			$englishSlug = $this->sanitizeSlug( $stripped );
			if ( $this->slugLooksUsable( $englishSlug ) ) {
				return $englishSlug;
			}
		}

		if ( class_exists( \Transliterator::class ) ) {
			$transliterated = transliterator_transliterate( 'Any-Latin; Latin-ASCII', $title );
			if ( is_string( $transliterated ) && $transliterated !== '' ) {
				return $this->sanitizeSlug( $transliterated );
			}
			error_log( 'Permalink Rule Assist: Transliterator returned an empty string; falling back to stripped title.' );
		}

		$fallback = $this->sanitizeSlug( is_string( $stripped ) ? $stripped : $title );
		return $fallback;
	}

	private function slugLooksUsable( string $slug ): bool {
		if ( $slug === '' ) {
			return false;
		}
		$parts = explode( '-', $slug );
		return count( $parts ) >= 2 || strlen( $slug ) >= 12;
	}

	private function sanitizeSlug( string $raw ): string {
		$raw = strtolower( $raw );
		$raw = preg_replace( '/[^a-z0-9]+/', '-', $raw );
		if ( ! is_string( $raw ) ) {
			error_log( 'Permalink Rule Assist: slug sanitize preg_replace failed.' );
			return '';
		}
		$raw = trim( $raw, '-' );
		$max = (int) $this->options->get( 'maxLength', 60 );
		if ( $max < 8 ) {
			$max = 8;
		}
		if ( $max > 120 ) {
			$max = 120;
		}
		if ( strlen( $raw ) > $max ) {
			$trimmed = substr( $raw, 0, $max );
			$cut     = preg_replace( '/-[^-]*$/', '', $trimmed );
			$raw     = is_string( $cut ) && $cut !== '' ? $cut : rtrim( $trimmed, '-' );
		}
		return $raw;
	}

	private function firstCategorySlug( int $postId ): string {
		$slugs = $this->termSlugs( $postId, 'category' );
		foreach ( $slugs as $slug ) {
			if ( $slug !== 'uncategorized' ) {
				return $slug;
			}
		}
		return $slugs[0] ?? 'post';
	}

	/**
	 * @return array<int, string>
	 */
	private function termSlugs( int $postId, string $taxonomy ): array {
		if ( $postId <= 0 ) {
			return array();
		}
		$terms = wp_get_post_terms( $postId, $taxonomy, array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $terms ) ) {
			error_log( 'Permalink Rule Assist: failed to load ' . $taxonomy . ' for post ' . $postId . ': ' . $terms->get_error_message() );
			return array();
		}
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values( array_map( 'strval', $terms ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function csvList( string $csv ): array {
		$out = array();
		foreach ( explode( ',', $csv ) as $item ) {
			$key = sanitize_key( trim( $item ) );
			if ( $key !== '' ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	private function previewPermalink( \WP_Post $post, string $slug ): string {
		$cloned            = clone $post;
		$cloned->post_name = $slug;
		$link              = get_permalink( $cloned );
		if ( ! is_string( $link ) || $link === '' ) {
			error_log( 'Permalink Rule Assist: get_permalink returned empty during preview for post ' . $post->ID );
			return '';
		}
		return $link;
	}

	/**
	 * @return \WP_Post|\WP_Error
	 */
	private function requirePost( int $postId ) {
		if ( $postId <= 0 ) {
			return new \WP_Error( 'invalid_id', '文章 ID 必須是正整數。' );
		}
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'not_found', '找不到這篇文章。' );
		}
		return $post;
	}
}
