<?php
/**
 * 从源码提取可翻译字符串，生成 languages/at8-site-accelerator.pot。
 *
 * 为什么自己写而不是用 `wp i18n make-pot`：
 * 发布环境不一定装了 WP-CLI。这个脚本只用 PHP 标准库，`php tools/make-pot.php`
 * 就能跑，让"改完文案顺手更新 .pot"变成零成本动作——否则 .pot 一定会过期。
 *
 * 用法：
 *   php tools/make-pot.php
 *
 * @package AT8\SiteAccelerator\Tools
 */

// phpcs:disable

$at8sa_root     = dirname( __DIR__ );
$at8sa_domain   = 'at8-site-accelerator';
$at8sa_output   = $at8sa_root . '/languages/' . $at8sa_domain . '.pot';
$at8sa_scan     = array( 'at8-site-accelerator.php', 'uninstall.php', 'includes', 'templates' );

/**
 * 收集待扫描的 PHP 文件。
 *
 * @param string $root 插件根。
 * @param array  $dirs 相对路径列表。
 * @return array 绝对路径 => 相对路径。
 */
function at8sa_collect( $root, array $dirs ) {
	$files = array();

	foreach ( $dirs as $dir ) {
		$path = $root . '/' . $dir;

		if ( is_file( $path ) ) {
			$files[ $path ] = $dir;
			continue;
		}

		if ( ! is_dir( $path ) ) {
			continue;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' === strtolower( $file->getExtension() ) ) {
				// 引用路径统一用正斜杠：.pot 是跨平台产物，
				// Windows 上生成的反斜杠会让 Linux 侧的翻译工具找不到源文件。
				$relative = str_replace( '\\', '/', str_replace( $root . '/', '', $file->getPathname() ) );

				$files[ $file->getPathname() ] = $relative;
			}
		}
	}

	ksort( $files );

	return $files;
}

/**
 * 从一段源码里提取翻译调用。
 *
 * @param string $source 源码。
 * @param string $domain 文本域。
 * @return array msgid => array( 'refs' => array, 'plural' => string|null )
 */
function at8sa_extract( $source, $domain ) {
	$found = array();

	// 单数形式：__() / _e() / esc_html__() / esc_attr__() / esc_html_e() / esc_attr_e() / _x()
	$single = '/(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e|_x)\s*\(\s*'
		. '([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*'
		. '([\'"])' . preg_quote( $domain, '/' ) . '\3/';

	preg_match_all( $single, $source, $matches, PREG_SET_ORDER );

	foreach ( $matches as $match ) {
		$msgid = at8sa_unescape( $match[2] );

		if ( ! isset( $found[ $msgid ] ) ) {
			$found[ $msgid ] = array( 'plural' => null );
		}
	}

	// 复数形式：_n() / _nx()
	$plural = '/_n(?:x)?\s*\(\s*'
		. '([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*'
		. '([\'"])((?:\\\\.|(?!\3).)*)\3\s*,[^,]+,\s*'
		. '([\'"])' . preg_quote( $domain, '/' ) . '\5/';

	preg_match_all( $plural, $source, $matches, PREG_SET_ORDER );

	foreach ( $matches as $match ) {
		$msgid = at8sa_unescape( $match[2] );

		if ( ! isset( $found[ $msgid ] ) ) {
			$found[ $msgid ] = array( 'plural' => null );
		}

		$found[ $msgid ]['plural'] = at8sa_unescape( $match[4] );
	}

	return $found;
}

/**
 * 还原 PHP 字符串字面量里的转义。
 *
 * @param string $value 原始字面量内容。
 * @return string
 */
function at8sa_unescape( $value ) {
	return str_replace( array( "\\'", '\\"', '\\\\' ), array( "'", '"', '\\' ), $value );
}

/**
 * 转义为 .pot 的 msgid 字面量。
 *
 * @param string $value 文本。
 * @return string
 */
function at8sa_escape( $value ) {
	return str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\\n', '\\t' ), $value );
}

/* ------------------------------------------------------------------------- */

$entries = array();

foreach ( at8sa_collect( $at8sa_root, $at8sa_scan ) as $abs => $rel ) {
	$source = (string) file_get_contents( $abs );

	foreach ( at8sa_extract( $source, $at8sa_domain ) as $msgid => $data ) {
		if ( ! isset( $entries[ $msgid ] ) ) {
			$entries[ $msgid ] = array(
				'plural' => $data['plural'],
				'refs'   => array(),
			);
		}

		$entries[ $msgid ]['refs'][] = $rel;
	}
}

ksort( $entries );

$pot = "# Copyright (C) 2026 AT8\n"
	. "# This file is distributed under the GPL-2.0-or-later license.\n"
	. "msgid \"\"\n"
	. "msgstr \"\"\n"
	. "\"Project-Id-Version: AT8 Site Accelerator 3.0.0\\n\"\n"
	. "\"Report-Msgid-Bugs-To: https://www.at8.fun/\\n\"\n"
	. "\"MIME-Version: 1.0\\n\"\n"
	. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
	. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
	. "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n"
	. "\"X-Domain: {$at8sa_domain}\\n\"\n"
	. "\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n"
	. "\"X-Generator: tools/make-pot.php\\n\"\n";

foreach ( $entries as $msgid => $data ) {
	$refs = array_unique( $data['refs'] );
	sort( $refs );

	$pot .= "\n#: " . implode( ' ', $refs ) . "\n";

	if ( null !== $data['plural'] ) {
		$pot .= 'msgid "' . at8sa_escape( $msgid ) . "\"\n";
		$pot .= 'msgid_plural "' . at8sa_escape( $data['plural'] ) . "\"\n";
		$pot .= "msgstr[0] \"\"\n";
		$pot .= "msgstr[1] \"\"\n";
	} else {
		$pot .= 'msgid "' . at8sa_escape( $msgid ) . "\"\n";
		$pot .= "msgstr \"\"\n";
	}
}

if ( ! is_dir( dirname( $at8sa_output ) ) ) {
	mkdir( dirname( $at8sa_output ), 0777, true );
}

file_put_contents( $at8sa_output, $pot );

echo '已写入 ' . $at8sa_output . "\n";
echo '可翻译字符串：' . count( $entries ) . " 条\n";
