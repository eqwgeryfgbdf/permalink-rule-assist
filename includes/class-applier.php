<?php
/**
 * Writes a generated slug onto a post.
 *
 * @package PermalinkRuleAssist
 */

namespace PermalinkRuleAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies a generated post_name and optional lock.
 */
class Applier {
	public const APPLYING = 'permalink_rule_assist_applying';

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

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function apply( int $postId, bool $force, bool $dryRun ) {
		if ( $postId <= 0 ) {
			return new \WP_Error( 'invalid_id', '文章 ID 必須是正整數。' );
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return new \WP_Error( 'forbidden', '你沒有編輯這篇文章的權限。' );
		}
		$preview = $this->generator->preview( $postId );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$current = (string) $preview['currentSlug'];
		$next    = (string) $preview['suggestedSlug'];
		if ( $next === '' ) {
			return new \WP_Error( 'empty_slug', '無法產生永久連結。' );
		}
		if ( ! $force && $this->generator->isLocked( $postId ) && ! $this->generator->isPoorSlug( $current ) ) {
			return new \WP_Error( 'locked', '這篇文章的永久連結已鎖定。請先解鎖再套用。' );
		}
		if ( $current === $next ) {
			return array(
				'id'        => $postId,
				'changed'   => false,
				'slug'      => $next,
				'permalink' => (string) $preview['permalink'],
				'ruleName'  => (string) $preview['ruleName'],
				'dryRun'    => $dryRun,
			);
		}
		if ( $dryRun ) {
			$preview['changed'] = true;
			$preview['dryRun']  = true;
			return $preview;
		}

		$GLOBALS[ self::APPLYING ] = true;
		$result                    = wp_update_post(
			array(
				'ID'        => $postId,
				'post_name' => $next,
			),
			true
		);
		$GLOBALS[ self::APPLYING ] = false;

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'update_failed', '寫入永久連結失敗：' . $result->get_error_message() );
		}
		if ( (int) $result <= 0 ) {
			return new \WP_Error( 'update_failed', '寫入永久連結失敗，wp_update_post 沒有回傳文章 ID。' );
		}

		if ( (string) $this->options->get( 'lockAfterPublish', '1' ) === '1' ) {
			$post = get_post( $postId );
			if ( $post instanceof \WP_Post && $post->post_status === 'publish' ) {
				update_post_meta( $postId, '_permalink_rule_locked', '1' );
			}
		}

		$link = get_permalink( $postId );
		if ( ! is_string( $link ) || $link === '' ) {
			error_log( 'Permalink Rule Assist: permalink empty after applying slug to post ' . $postId );
			$link = '';
		}

		return array(
			'id'        => $postId,
			'changed'   => true,
			'from'      => $current,
			'slug'      => $next,
			'permalink' => $link,
			'ruleName'  => (string) $preview['ruleName'],
			'dryRun'    => false,
		);
	}

	/**
	 * Rewrite published/draft posts whose current slug is encoded or non-ASCII.
	 *
	 * @return array<string, mixed>
	 */
	public function repairPoorSlugs( int $limit ): array {
		$limit = max( 1, min( 50, $limit ) );
		$query = new \WP_Query(
			array(
				'post_type'              => $this->options->postTypes(),
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'         => $limit,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => true,
			)
		);

		$repaired = array();
		$skipped  = array();
		$errors   = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			if ( ! $this->generator->isPoorSlug( (string) $post->post_name ) ) {
				continue;
			}
			$result = $this->apply( $post->ID, true, false );
			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'id'      => $post->ID,
					'message' => $result->get_error_message(),
				);
				continue;
			}
			if ( ! empty( $result['changed'] ) ) {
				$repaired[] = $result;
			} else {
				$skipped[] = $post->ID;
			}
		}

		return array(
			'repaired' => $repaired,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}
}
