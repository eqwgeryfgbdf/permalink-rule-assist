<?php
/**
 * WordPress Abilities / MCP tools for slug rules.
 *
 * @package PermalinkRuleAssist
 */

namespace PermalinkRuleAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers preview / apply / repair abilities for AI agents.
 */
class Abilities {
	/**
	 * @var Generator
	 */
	private $generator;

	/**
	 * @var Applier
	 */
	private $applier;

	public function __construct( Generator $generator, Applier $applier ) {
		$this->generator = $generator;
		$this->applier   = $applier;
	}

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'registerCategory' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'registerAbilities' ) );
	}

	public function registerCategory(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'permalink-rule',
			array(
				'label'       => 'Permalink rules',
				'description' => 'Generate ASCII post slugs from category and title rules without changing the site permalink structure.',
			)
		);
	}

	public function registerAbilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			error_log( 'Permalink Rule Assist: wp_register_ability is unavailable; MCP tools were not registered.' );
			return;
		}

		$mcpMeta = array(
			'mcp'          => array( 'public' => true ),
			'show_in_rest' => true,
		);

		wp_register_ability(
			'permalink-rule/preview',
			array(
				'label'               => 'Preview permalink slug',
				'description'         => 'Show the ASCII slug a post would get from the first matching rule. Does not write anything.',
				'category'            => 'permalink-rule',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Post ID',
						),
					),
					'required'   => array( 'id' ),
				),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => function ( $input ) {
					$postId = (int) ( $input['id'] ?? 0 );
					if ( $postId > 0 && ! current_user_can( 'edit_post', $postId ) ) {
						return new \WP_Error( 'forbidden', '你沒有編輯這篇文章的權限。' );
					}
					return $this->generator->preview( $postId );
				},
				'meta'                => $mcpMeta,
			)
		);

		wp_register_ability(
			'permalink-rule/apply',
			array(
				'label'               => 'Apply permalink slug',
				'description'         => 'Write the generated slug onto the post. WordPress keeps the old slug for a 301 redirect. Set force=true to override a locked permalink.',
				'category'            => 'permalink-rule',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer', 'description' => 'Post ID' ),
						'force'  => array( 'type' => 'boolean', 'default' => false ),
						'dryRun' => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'   => array( 'id' ),
				),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => function ( $input ) {
					$postId = (int) ( $input['id'] ?? 0 );
					if ( $postId > 0 && ! current_user_can( 'edit_post', $postId ) ) {
						return new \WP_Error( 'forbidden', '你沒有編輯這篇文章的權限。' );
					}
					return $this->applier->apply( $postId, ! empty( $input['force'] ), ! empty( $input['dryRun'] ) );
				},
				'meta'                => $mcpMeta,
			)
		);

		wp_register_ability(
			'permalink-rule/repair-poor',
			array(
				'label'               => 'Repair poor permalinks',
				'description'         => 'Scan recent posts and rewrite percent-encoded or non-ASCII slugs using the rules.',
				'category'            => 'permalink-rule',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array(
							'type'        => 'integer',
							'description' => 'How many recent posts to scan (max 50)',
							'default'     => 20,
						),
					),
				),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => function ( $input ) {
					$limit = (int) ( $input['limit'] ?? 20 );
					return $this->applier->repairPoorSlugs( $limit );
				},
				'meta'                => $mcpMeta,
			)
		);
	}
}
