<?php
/**
 * Post editor metabox.
 *
 * @package PermalinkRuleAssist
 */

namespace PermalinkRuleAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preview and apply a rule-generated slug from the editor.
 */
class Metabox {
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
		add_action( 'add_meta_boxes', array( $this, 'addBox' ) );
		add_action( 'save_post', array( $this, 'saveLock' ), 10, 2 );
		add_action( 'admin_post_permalink_rule_assist_apply', array( $this, 'handleApply' ) );
	}

	public function addBox(): void {
		add_meta_box(
			'permalink-rule-assist',
			'永久連結規則',
			array( $this, 'render' ),
			array( 'post' ),
			'side',
			'high'
		);
	}

	public function render( \WP_Post $post ): void {
		$preview = $this->generator->preview( $post->ID );
		$locked  = $this->generator->isLocked( $post->ID );
		wp_nonce_field( 'permalink_rule_lock_' . $post->ID, 'permalink_rule_lock_nonce' );
		?>
		<?php if ( isset( $_GET['permalink_rule'] ) ) : ?>
			<p class="description">
				<?php
				$flag = sanitize_key( (string) wp_unslash( $_GET['permalink_rule'] ) );
				$map  = array(
					'applied' => '已依規則寫入永久連結。',
				);
				echo esc_html( $map[ $flag ] ?? '完成。' );
				?>
			</p>
		<?php endif; ?>
		<?php if ( is_wp_error( $preview ) ) : ?>
			<p><?php echo esc_html( $preview->get_error_message() ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<p>
			目前：<code><?php echo esc_html( (string) $preview['currentSlug'] ); ?></code>
			<?php if ( ! empty( $preview['isPoorSlug'] ) ) : ?>
				<span style="color:#d63638">需修正</span>
			<?php endif; ?>
		</p>
		<p>
			規則「<?php echo esc_html( (string) $preview['ruleName'] ); ?>」→
			<code><?php echo esc_html( (string) $preview['suggestedSlug'] ); ?></code>
		</p>
		<?php if ( (string) $preview['permalink'] !== '' ) : ?>
			<p class="description"><?php echo esc_html( (string) $preview['permalink'] ); ?></p>
		<?php endif; ?>
		<p>
			<label>
				<input type="checkbox" name="permalink_rule_locked" value="1" <?php checked( $locked ); ?>>
				鎖定永久連結
			</label>
		</p>
		<?php if ( (string) $preview['currentSlug'] !== (string) $preview['suggestedSlug'] ) : ?>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $this->actionUrl( $post->ID ) ); ?>">依規則寫入</a>
			</p>
		<?php endif; ?>
		<?php
	}

	public function saveLock( int $postId, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( $post->post_type !== 'post' ) {
			return;
		}
		if ( ! isset( $_POST['permalink_rule_lock_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( (string) wp_unslash( $_POST['permalink_rule_lock_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'permalink_rule_lock_' . $postId ) ) {
			error_log( 'Permalink Rule Assist: lock nonce failed for post ' . $postId );
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			error_log( 'Permalink Rule Assist: lock save forbidden for post ' . $postId );
			return;
		}
		$locked = empty( $_POST['permalink_rule_locked'] ) ? '0' : '1';
		update_post_meta( $postId, '_permalink_rule_locked', $locked );
	}

	public function handleApply(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html( '你沒有權限執行這個動作。' ) );
		}
		$postId = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		check_admin_referer( 'permalink_rule_assist_post_' . $postId );
		if ( $postId <= 0 || ! current_user_can( 'edit_post', $postId ) ) {
			wp_die( esc_html( '無效的文章，或你沒有編輯權限。' ) );
		}
		$result = $this->applier->apply( $postId, true, false );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		$url = get_edit_post_link( $postId, 'raw' );
		if ( ! is_string( $url ) || $url === '' ) {
			wp_die( esc_html( '無法回到文章編輯頁。' ) );
		}
		wp_safe_redirect( add_query_arg( 'permalink_rule', 'applied', $url ) );
		exit;
	}

	private function actionUrl( int $postId ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=permalink_rule_assist_apply&post_id=' . $postId ),
			'permalink_rule_assist_post_' . $postId
		);
	}
}
