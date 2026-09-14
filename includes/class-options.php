<?php
/**
 * Plugin settings and default slug rules.
 *
 * @package PermalinkRuleAssist
 */

namespace PermalinkRuleAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the permalink_rule_assist option.
 */
class Options {
	public const OPTION_KEY = 'permalink_rule_assist';

	/**
	 * @var array<string, mixed>
	 */
	private $settings = array();

	/**
	 * Default settings for this blog's topic hubs.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'autoOnSave'        => '1',
			'lockAfterPublish'  => '1',
			'rewritePoorSlugs'  => '1',
			'postTypes'         => array( 'post' ),
			'maxLength'         => 60,
			'glossary'          => self::defaultGlossaryText(),
			'rules'             => self::defaultRules(),
		);
	}

	/**
	 * Phrase replacements applied before Latin transliteration.
	 */
	public static function defaultGlossaryText(): string {
		$lines = array(
			'台股盤勢=stock-watch',
			'臺股盤勢=stock-watch',
			'台股盤後=stock-watch',
			'臺股盤後=stock-watch',
			'台積電=tsmc',
			'聯發科=mediatek',
			'台達電=delta',
			'光聖=eoptolink',
			'歌詞賞析=',
			'歌曲推薦=',
			'周深=zhou-shen',
			'華晨宇=hua-chenyu',
			'告別旅行=gao-bie-lv-xing',
			'我以渺小愛你=wo-yi-miao-xiao-ai-ni',
			'好想愛這個世界啊=hao-xiang-ai-zhe-ge-shi-jie-a',
			'離開=li-kai',
			'llama.cpp=llama-cpp',
			'Jetson Orin NX=jetson-orin-nx',
			'Jetson=jetson',
			'CUDA=cuda',
			'CS:S=css',
			'死鬥=deathmatch',
			'伺服器=server',
			'安裝=install',
			'部署=deploy',
			'本地 AI=local-ai',
			'本地AI=local-ai',
		);
		return implode( "\n", $lines );
	}

	/**
	 * First matching rule wins.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function defaultRules(): array {
		return array(
			array(
				'name'       => '台股盤後',
				'enabled'    => '1',
				'categories' => 'stock-views',
				'tags'       => '',
				'titleRegex' => '(台股|臺股|盤勢|盤後)',
				'template'   => 'stock-watch-{date}',
			),
			array(
				'name'       => '歌曲推薦',
				'enabled'    => '1',
				'categories' => 'song-recommendations',
				'tags'       => '',
				'titleRegex' => '(歌詞賞析|歌曲推薦)',
				'template'   => '{ascii}',
			),
			array(
				'name'       => '遊戲伺服器',
				'enabled'    => '1',
				'categories' => 'game,fps',
				'tags'       => '',
				'titleRegex' => '',
				'template'   => '{ascii}',
			),
			array(
				'name'       => '技術部署',
				'enabled'    => '1',
				'categories' => 'tech,deploy',
				'tags'       => 'local-ai',
				'titleRegex' => '',
				'template'   => '{ascii}',
			),
			array(
				'name'       => '預設英文 slug',
				'enabled'    => '1',
				'categories' => '',
				'tags'       => '',
				'titleRegex' => '',
				'template'   => '{ascii}',
			),
		);
	}

	public function load(): void {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			error_log( 'Permalink Rule Assist: stored settings are not an array; using defaults.' );
			$stored = array();
		}
		$this->settings = array_merge( self::defaults(), $stored );
		if ( ! isset( $stored['rules'] ) || ! is_array( $stored['rules'] ) || $stored['rules'] === array() ) {
			$this->settings['rules'] = self::defaultRules();
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( $this->settings === array() ) {
			$this->load();
		}
		return $this->settings;
	}

	/**
	 * @param mixed $default Fallback.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$settings = $this->all();
		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}
		return $default;
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	public function save( array $settings ): bool {
		$this->settings = array_merge( self::defaults(), $settings );
		$updated        = update_option( self::OPTION_KEY, $this->settings, false );
		if ( false === $updated && get_option( self::OPTION_KEY ) !== $this->settings ) {
			error_log( 'Permalink Rule Assist: failed to save settings.' );
			return false;
		}
		return true;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function rules(): array {
		$rules = $this->get( 'rules', array() );
		if ( ! is_array( $rules ) ) {
			error_log( 'Permalink Rule Assist: rules setting is not an array.' );
			return self::defaultRules();
		}
		return $rules;
	}

	/**
	 * @return array<int, string>
	 */
	public function postTypes(): array {
		$types = $this->get( 'postTypes', array( 'post' ) );
		if ( ! is_array( $types ) ) {
			error_log( 'Permalink Rule Assist: postTypes is not an array.' );
			return array( 'post' );
		}
		$clean = array();
		foreach ( $types as $type ) {
			$key = sanitize_key( (string) $type );
			if ( $key !== '' ) {
				$clean[] = $key;
			}
		}
		return $clean !== array() ? $clean : array( 'post' );
	}

	/**
	 * @return array<string, string>
	 */
	public function glossaryMap(): array {
		$text = (string) $this->get( 'glossary', '' );
		$map  = array();
		foreach ( preg_split( '/\R/u', $text ) ?: array() as $line ) {
			$line = trim( (string) $line );
			if ( $line === '' || strpos( $line, '#' ) === 0 ) {
				continue;
			}
			$eq = strpos( $line, '=' );
			if ( $eq === false ) {
				error_log( 'Permalink Rule Assist: glossary line skipped (missing =): ' . $line );
				continue;
			}
			$from = trim( substr( $line, 0, $eq ) );
			$to   = trim( substr( $line, $eq + 1 ) );
			if ( $from === '' ) {
				error_log( 'Permalink Rule Assist: glossary line skipped (empty source): ' . $line );
				continue;
			}
			$map[ $from ] = $to;
		}
		uksort(
			$map,
			static function ( string $a, string $b ): int {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);
		return $map;
	}
}
