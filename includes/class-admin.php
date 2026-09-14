<?php
/**
 * Settings screen.
 *
 * @package PermalinkRuleAssist
 */

namespace PermalinkRuleAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings under Settings → Permalink Rules.
 */
class Admin {
	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @var Applier
	 */
	private $applier;

	public function __construct( Options $options, Applier $applier ) {
		$this->options = $options;
		$this->applier = $applier;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addPage' ) );
		add_action( 'admin_post_permalink_rule_assist_save', array( $this, 'handleSave' ) );
		add_action( 'admin_post_permalink_rule_assist_repair', array( $this, 'handleRepair' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PERMALINK_RULE_ASSIST_FILE ), array( $this, 'actionLinks' ) );
		add_filter( 'manage_posts_columns', array( $this, 'addColumn' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'renderColumn' ), 10, 2 );
	}

	/**
	 * @param array<int, string> $links Plugin links.
	 * @return array<int, string>
	 */
	public function actionLinks( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=permalink-rule-assist' ) ) . '">設定</a>';
		return $links;
	}

	public function addPage(): void {
		add_options_page(
			'Permalink Rules',
			'Permalink Rules',
			'manage_options',
			'permalink-rule-assist',
			array( $this, 'renderPage' )
		);
	}

	public function handleSave(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( '你沒有權限修改這些設定。' ) );
		}
		check_admin_referer( 'permalink_rule_assist_save' );
		$posted = wp_unslash( $_POST );

		$saved = $this->options->save(
			array(
				'autoOnSave'       => empty( $posted['autoOnSave'] ) ? '' : '1',
				'lockAfterPublish' => empty( $posted['lockAfterPublish'] ) ? '' : '1',
				'rewritePoorSlugs' => empty( $posted['rewritePoorSlugs'] ) ? '' : '1',
				'postTypes'        => array( 'post' ),
				'maxLength'        => absint( $posted['maxLength'] ?? 60 ),
				'glossary'         => sanitize_textarea_field( (string) ( $posted['glossary'] ?? '' ) ),
				'rules'            => $this->sanitizeRules( $posted['rules'] ?? array() ),
			)
		);
		if ( $saved !== true ) {
			wp_die( esc_html( '儲存設定失敗，請再試一次。' ) );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=permalink-rule-assist&saved=1' ) );
		exit;
	}

	public function handleRepair(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( '你沒有權限修正永久連結。' ) );
		}
		check_admin_referer( 'permalink_rule_assist_repair' );
		$result = $this->applier->repairPoorSlugs( 50 );
		$fixed  = is_array( $result['repaired'] ?? null ) ? count( $result['repaired'] ) : 0;
		$failed = is_array( $result['errors'] ?? null ) ? count( $result['errors'] ) : 0;
		wp_safe_redirect(
			admin_url(
				'options-general.php?page=permalink-rule-assist&repaired=' . $fixed . '&failed=' . $failed
			)
		);
		exit;
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( '你沒有權限查看這個頁面。' ) );
		}
		$settings = $this->options->all();
		$rules    = $this->options->rules();
		while ( count( $rules ) < 6 ) {
			$rules[] = array(
				'name'       => '',
				'enabled'    => '',
				'categories' => '',
				'tags'       => '',
				'titleRegex' => '',
				'template'   => '',
			);
		}
		?>
		<div class="wrap">
			<h1>Permalink Rules</h1>
			<p>依分類、標籤或標題規則自動產生英文 slug。網站的永久連結結構（含 <code>/index.php/</code>）不會被改掉。</p>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['repaired'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								'已修正 %d 篇不良永久連結，失敗 %d 篇。',
								absint( $_GET['repaired'] ),
								absint( $_GET['failed'] ?? 0 )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'permalink_rule_assist_save' ); ?>
				<input type="hidden" name="action" value="permalink_rule_assist_save">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">自動產生</th>
						<td>
							<label>
								<input type="checkbox" name="autoOnSave" value="1" <?php checked( (string) $settings['autoOnSave'] === '1' ); ?>>
								儲存草稿或新文章時，依第一條符合的規則產生 slug
							</label>
							<br>
							<label>
								<input type="checkbox" name="rewritePoorSlugs" value="1" <?php checked( (string) $settings['rewritePoorSlugs'] === '1' ); ?>>
								已發布但 slug 是中文／percent-encoded 時，儲存仍會重寫
							</label>
							<br>
							<label>
								<input type="checkbox" name="lockAfterPublish" value="1" <?php checked( (string) $settings['lockAfterPublish'] === '1' ); ?>>
								發布後鎖定，避免之後改標題又改網址
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="maxLength">slug 最長字元</label></th>
						<td>
							<input type="number" id="maxLength" name="maxLength" min="8" max="120" value="<?php echo esc_attr( (string) (int) $settings['maxLength'] ); ?>">
						</td>
					</tr>
				</table>
				<h2>規則（由上而下，第一條符合的生效）</h2>
				<p class="description">可用代碼：<code>{date}</code> <code>{year}</code> <code>{month}</code> <code>{day}</code> <code>{ascii}</code> <code>{category}</code>。<code>{date}</code> 會優先讀標題裡的「9月2日」。</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>啟用</th>
							<th>名稱</th>
							<th>分類 slug</th>
							<th>標籤 slug</th>
							<th>標題正則</th>
							<th>模板</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rules as $index => $rule ) : ?>
							<?php if ( ! is_array( $rule ) ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<tr>
								<td>
									<input type="checkbox" name="rules[<?php echo esc_attr( (string) $index ); ?>][enabled]" value="1" <?php checked( (string) ( $rule['enabled'] ?? '' ) === '1' ); ?>>
								</td>
								<td><input type="text" class="regular-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][name]" value="<?php echo esc_attr( (string) ( $rule['name'] ?? '' ) ); ?>"></td>
								<td><input type="text" class="regular-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][categories]" value="<?php echo esc_attr( (string) ( $rule['categories'] ?? '' ) ); ?>" placeholder="stock-views"></td>
								<td><input type="text" class="regular-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][tags]" value="<?php echo esc_attr( (string) ( $rule['tags'] ?? '' ) ); ?>" placeholder="local-ai"></td>
								<td><input type="text" class="regular-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][titleRegex]" value="<?php echo esc_attr( (string) ( $rule['titleRegex'] ?? '' ) ); ?>" placeholder="(台股|盤勢)"></td>
								<td><input type="text" class="regular-text" name="rules[<?php echo esc_attr( (string) $index ); ?>][template]" value="<?php echo esc_attr( (string) ( $rule['template'] ?? '' ) ); ?>" placeholder="stock-watch-{date}"></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<h2>詞彙表</h2>
				<p class="description">一行一組 <code>原文=slug</code>，較長的詞會優先取代，再把剩下的中文轉成拼音。</p>
				<textarea class="large-text code" rows="12" name="glossary"><?php echo esc_textarea( (string) $settings['glossary'] ); ?></textarea>
				<?php submit_button( '儲存設定' ); ?>
			</form>
			<h2>修正現有不良連結</h2>
			<p>掃描最近文章，把 percent-encoded 中文 slug 改成規則產生的英文 slug。WordPress 會留下舊 slug 做 301。</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'permalink_rule_assist_repair' ); ?>
				<input type="hidden" name="action" value="permalink_rule_assist_repair">
				<?php submit_button( '掃描並修正不良永久連結', 'secondary' ); ?>
			</form>
			<h2>給 AI 的工具</h2>
			<ol>
				<li><code>permalink-rule/preview</code> 預覽會產生的 slug</li>
				<li><code>permalink-rule/apply</code> 寫入 slug（可 dryRun / force）</li>
				<li><code>permalink-rule/repair-poor</code> 批次修正不良 slug</li>
			</ol>
		</div>
		<?php
	}

	/**
	 * @param mixed $raw Posted rules.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitizeRules( $raw ): array {
		if ( ! is_array( $raw ) ) {
			error_log( 'Permalink Rule Assist: posted rules were not an array.' );
			return Options::defaultRules();
		}
		$rules = array();
		foreach ( $raw as $rule ) {
			if ( ! is_array( $rule ) ) {
				error_log( 'Permalink Rule Assist: skipped a non-array posted rule.' );
				continue;
			}
			$name     = sanitize_text_field( (string) ( $rule['name'] ?? '' ) );
			$template = sanitize_text_field( (string) ( $rule['template'] ?? '' ) );
			if ( $name === '' && $template === '' ) {
				continue;
			}
			$titleRegex = (string) ( $rule['titleRegex'] ?? '' );
			if ( $titleRegex !== '' && @preg_match( '/' . $titleRegex . '/u', '' ) === false ) {
				error_log( 'Permalink Rule Assist: rejected invalid titleRegex: ' . $titleRegex );
				$titleRegex = '';
			}
			$rules[] = array(
				'name'       => $name,
				'enabled'    => empty( $rule['enabled'] ) ? '' : '1',
				'categories' => sanitize_text_field( (string) ( $rule['categories'] ?? '' ) ),
				'tags'       => sanitize_text_field( (string) ( $rule['tags'] ?? '' ) ),
				'titleRegex' => sanitize_text_field( $titleRegex ),
				'template'   => $template,
			);
		}
		if ( $rules === array() ) {
			error_log( 'Permalink Rule Assist: no usable rules posted; keeping defaults.' );
			return Options::defaultRules();
		}
		return $rules;
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function addColumn( array $columns ): array {
		$columns['permalink_rule'] = 'Slug';
		return $columns;
	}

	public function renderColumn( string $column, int $postId ): void {
		if ( $column !== 'permalink_rule' ) {
			return;
		}
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			echo '<span class="description">找不到文章</span>';
			return;
		}
		$slug = (string) $post->post_name;
		if ( $this->applierIsPoor( $slug ) ) {
			echo '<span style="color:#d63638">' . esc_html( rawurldecode( $slug ) ) . '</span>';
			return;
		}
		echo '<code>' . esc_html( $slug ) . '</code>';
	}

	private function applierIsPoor( string $slug ): bool {
		$generator = new Generator( $this->options );
		return $generator->isPoorSlug( $slug );
	}
}
