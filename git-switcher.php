<?php
/**
 * Plugin Name: Git Switcher
 * Description: Admin bar popover to inspect and switch branches for git-enabled plugins.
 * Version: 1.1.0
 * Text Domain: git-switcher
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package GitSwitcher
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_bar_menu', 'git_switcher_add_admin_bar_button', 100 );
add_action( 'admin_enqueue_scripts', 'git_switcher_enqueue_assets' );
add_action( 'wp_enqueue_scripts', 'git_switcher_enqueue_assets' );

add_action( 'wp_ajax_git_switcher_fetch_repositories', 'git_switcher_ajax_fetch_repositories' );
add_action( 'wp_ajax_git_switcher_checkout_branch', 'git_switcher_ajax_checkout_branch' );
add_action( 'wp_ajax_git_switcher_save_settings', 'git_switcher_ajax_save_settings' );
add_action( 'wp_ajax_git_switcher_fetch_repository', 'git_switcher_ajax_fetch_repository' );

/**
 * Add the Git Switcher entry to the admin bar.
 *
 * @param  WP_Admin_Bar $wp_admin_bar Admin bar instance.
 * @return void
 */
function git_switcher_add_admin_bar_button( $wp_admin_bar ) {
	if ( ! is_admin_bar_showing() || ! is_user_logged_in() ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$git_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="git-switcher-git-icon" viewBox="0 0 16 16">'
	. '<path d="M15.698 7.287 8.712.302a1.03 1.03 0 0 0-1.457 0l-1.45 1.45 1.84 1.84a1.223 1.223 0 0 1 1.55 1.56l1.773 1.774a1.224 1.224 0 0 1 1.267 2.025 1.226 1.226 0 0 1-2.002-1.334L8.58 5.963v4.353a1.226 1.226 0 1 1-1.008-.036V5.887a1.226 1.226 0 0 1-.666-1.608L5.093 2.465l-4.79 4.79a1.03 1.03 0 0 0 0 1.457l6.986 6.986a1.03 1.03 0 0 0 1.457 0l6.953-6.953a1.03 1.03 0 0 0 0-1.457"/>'
	. '</svg>';

	$title = '<span class="ab-icon">' . $git_svg . '</span><span class="ab-label">' . esc_html__( 'Git Switcher', 'git-switcher' ) . '</span>';

	$wp_admin_bar->add_node(
		array(
			'id'    => 'git-switcher',
			'title' => $title,
			'href'  => '#',
			'meta'  => array(
				'class' => 'git-switcher-node',
			),
		)
	);
}

/**
 * Enqueue scripts and styles for the popover UI.
 *
 * @return void
 */
function git_switcher_enqueue_assets() {
	if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$handle = 'git-switcher-admin';
	$src    = plugins_url( 'assets/admin.js', __FILE__ );

	// Use file modification time as version to avoid browser caching during development.
	$asset_dir = plugin_dir_path( __FILE__ ) . 'assets/';
	$js_file   = $asset_dir . 'admin.js';
	$css_file  = $asset_dir . 'admin.css';
	$ver       = '1.1.0';
	if ( file_exists( $js_file ) ) {
		$ver = (string) filemtime( $js_file );
	}

	wp_enqueue_style( 'wp-components' );
	wp_enqueue_style(
		'git-switcher-admin',
		plugins_url( 'assets/admin.css', __FILE__ ),
		array( 'wp-components' ),
		file_exists( $css_file ) ? (string) filemtime( $css_file ) : $ver
	);

	wp_enqueue_script(
		$handle,
		$src,
		array( 'wp-element', 'wp-components', 'wp-i18n' ),
		$ver,
		true
	);

	wp_localize_script(
		$handle,
		'gitSwitcherData',
		array(
			'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
			'nonce'              => wp_create_nonce( 'git_switcher_nonce' ),
			'gitBinary'          => get_option( 'git_switcher_git_binary', '' ),
			'shellExecAvailable' => git_switcher_shell_exec_available(),
			'i18n'               => array(
				'buttonLabel'          => __( 'Git Switcher', 'git-switcher' ),
				'tabPlugins'           => __( 'Plugins', 'git-switcher' ),
				'tabSettings'          => __( 'Settings', 'git-switcher' ),
				'loading'              => __( 'Loading repositories...', 'git-switcher' ),
				'noRepositories'       => __( 'No git-managed plugin folders found.', 'git-switcher' ),
				'branches'             => __( 'Branches', 'git-switcher' ),
				'switching'            => __( 'Switching...', 'git-switcher' ),
				'switched'             => __( 'Branch switched.', 'git-switcher' ),
				'gitBinaryLabel'       => __( 'Git binary path', 'git-switcher' ),
				'saveSettings'         => __( 'Save settings', 'git-switcher' ),
				'settingsSaved'        => __( 'Settings saved.', 'git-switcher' ),
				'manageHint'           => __( 'Use an absolute path, for example /usr/bin/git or /opt/homebrew/bin/git.', 'git-switcher' ),
				'activeBranch'         => __( 'Active', 'git-switcher' ),
				'current'              => __( 'Current', 'git-switcher' ),
				'shellExecUnavailable' => __( 'shell_exec is not available on this installation.', 'git-switcher' ),
			),
		)
	);
}

/**
 * AJAX: return git-enabled plugin repositories and branch data.
 *
 * @return void
 */
function git_switcher_ajax_fetch_repositories() {
	git_switcher_assert_ajax_permissions();

	// Return a shallow list quickly; branch details are loaded lazily.
	$repositories = git_switcher_get_git_plugin_repositories_shallow();
	wp_send_json_success(
		array(
			'repositories' => $repositories,
		)
	);
}


/**
 * AJAX: fetch branch details for a single repository (lazy-loaded).
 *
 * @return void
 */
function git_switcher_ajax_fetch_repository() {
	git_switcher_assert_ajax_permissions();

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in git_switcher_assert_ajax_permissions().
	$repo_slug = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
	if ( '' === $repo_slug ) {
		wp_send_json_error( array( 'message' => __( 'Missing repository.', 'git-switcher' ) ), 400 );
	}

	$repo_map = git_switcher_get_plugin_repo_map();
	if ( ! isset( $repo_map[ $repo_slug ] ) ) {
		wp_send_json_error( array( 'message' => __( 'Repository not found.', 'git-switcher' ) ), 404 );
	}

	$repo_path = $repo_map[ $repo_slug ]['path'];
	if ( ! git_switcher_is_git_repo( $repo_path ) ) {
		wp_send_json_error( array( 'message' => __( 'Selected plugin is not a git repository.', 'git-switcher' ) ), 400 );
	}

	// Refresh remote tracking refs to ensure ahead/behind is accurate.
	git_switcher_fetch_remote_for_repo( $repo_path );

	$branches       = array();
	$local_branches = git_switcher_get_local_branches( $repo_path );
	foreach ( $local_branches as $b ) {
		$info       = git_switcher_get_branch_last_commit_info( $repo_path, $b );
		$branches[] = array(
			'name'             => $b,
			'last_commit'      => isset( $info['timestamp'] ) ? $info['timestamp'] : '',
			'last_author'      => isset( $info['author'] ) ? $info['author'] : '',
			'last_commit_show' => isset( $info['show_stat'] ) ? $info['show_stat'] : '',
			'upstream'         => isset( $info['upstream_ref'] ) ? $info['upstream_ref'] : '',
			'upstream_track'   => isset( $info['upstream_raw'] ) ? $info['upstream_raw'] : '',
			'ahead'            => isset( $info['ahead'] ) ? (int) $info['ahead'] : 0,
			'behind'           => isset( $info['behind'] ) ? (int) $info['behind'] : 0,
			'in_sync'          => isset( $info['in_sync'] ) ? (bool) $info['in_sync'] : false,
		);
	}

	wp_send_json_success( array( 'branches' => array_values( $branches ) ) );
}


/**
 * Return a shallow list of git-enabled plugin repositories with current branch only.
 *
 * @return array<int, array<string, string>>
 */
function git_switcher_get_git_plugin_repositories_shallow() {
	$repos = array();
	$map   = git_switcher_get_plugin_repo_map();

	foreach ( $map as $slug => $plugin ) {
		$repo_path = $plugin['path'];
		if ( ! git_switcher_is_git_repo( $repo_path ) ) {
			continue;
		}

		$current_branch = git_switcher_get_current_branch( $repo_path );

		$repos[] = array(
			'slug'   => $slug,
			'name'   => $plugin['name'],
			'folder' => $plugin['folder'],
			'branch' => $current_branch,
		);
	}

	usort(
		$repos,
		static function ( $a, $b ) {
			return strcasecmp( $a['folder'], $b['folder'] );
		}
	);

	return $repos;
}

/**
 * AJAX: checkout a branch in a plugin repository.
 *
 * @return void
 */
function git_switcher_ajax_checkout_branch() {
	git_switcher_assert_ajax_permissions();

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in git_switcher_assert_ajax_permissions().
	$repo_slug = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in git_switcher_assert_ajax_permissions().
	$branch = isset( $_POST['branch'] ) ? sanitize_text_field( wp_unslash( $_POST['branch'] ) ) : '';

	if ( '' === $repo_slug || '' === $branch ) {
		wp_send_json_error( array( 'message' => __( 'Missing repository or branch.', 'git-switcher' ) ), 400 );
	}

	$repo_map = git_switcher_get_plugin_repo_map();
	if ( ! isset( $repo_map[ $repo_slug ] ) ) {
		wp_send_json_error( array( 'message' => __( 'Repository not found.', 'git-switcher' ) ), 404 );
	}

	$repo_path = $repo_map[ $repo_slug ]['path'];
	if ( ! git_switcher_is_git_repo( $repo_path ) ) {
		wp_send_json_error( array( 'message' => __( 'Selected plugin is not a git repository.', 'git-switcher' ) ), 400 );
	}

	$git_binary = git_switcher_get_git_binary();
	if ( '' === $git_binary ) {
		wp_send_json_error( array( 'message' => __( 'Git binary was not found. Configure it in Settings.', 'git-switcher' ) ), 400 );
	}

	$cmd          = escapeshellarg( $git_binary ) . ' -C ' . escapeshellarg( $repo_path ) . ' checkout ' . escapeshellarg( $branch ) . ' 2>&1';
	$output_lines = array();
	$exit_code    = 0;
	git_switcher_exec( $cmd, $output_lines, $exit_code );

	if ( 0 !== $exit_code ) {
		wp_send_json_error(
			array(
				'message' => __( 'Checkout failed.', 'git-switcher' ),
				'output'  => implode( "\n", $output_lines ),
			),
			500
		);
	}

	wp_send_json_success( array( 'message' => __( 'Branch switched.', 'git-switcher' ) ) );
}

/**
 * AJAX: save plugin settings.
 *
 * @return void
 */
function git_switcher_ajax_save_settings() {
	git_switcher_assert_ajax_permissions();

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in git_switcher_assert_ajax_permissions().
	$git_binary = isset( $_POST['git_binary'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['git_binary'] ) ) ) : '';
	update_option( 'git_switcher_git_binary', $git_binary );

	wp_send_json_success(
		array(
			'gitBinary' => $git_binary,
			'message'   => __( 'Settings saved.', 'git-switcher' ),
		)
	);
}

/**
 * Validate nonce and capability for Git Switcher AJAX routes.
 *
 * @return void
 */
function git_switcher_assert_ajax_permissions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'git-switcher' ) ), 403 );
	}

	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'git_switcher_nonce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'git-switcher' ) ), 403 );
	}
}

/**
 * Return git-enabled plugin repositories with current branch and local branches.
 *
 * @return array<int, array<string, mixed>>
 */
function git_switcher_get_git_plugin_repositories() {
	$repos = array();
	$map   = git_switcher_get_plugin_repo_map();

	foreach ( $map as $slug => $plugin ) {
		$repo_path = $plugin['path'];
		if ( ! git_switcher_is_git_repo( $repo_path ) ) {
			continue;
		}

		// Ensure remote tracking refs are up-to-date so ahead/behind counts
		// reflect the current remote (useful when merges happen on GitHub).
		git_switcher_fetch_remote_for_repo( $repo_path );

		$current_branch = git_switcher_get_current_branch( $repo_path );
		$branches       = git_switcher_get_local_branches( $repo_path );

		$branch_items = array();
		if ( empty( $branches ) ) {
			if ( $current_branch ) {
				$info           = git_switcher_get_branch_last_commit_info( $repo_path, $current_branch );
				$branch_items[] = array(
					'name'             => $current_branch,
					'last_commit'      => isset( $info['timestamp'] ) ? $info['timestamp'] : '',
					'last_author'      => isset( $info['author'] ) ? $info['author'] : '',
					'last_commit_show' => isset( $info['show_stat'] ) ? $info['show_stat'] : '',
					'upstream'         => isset( $info['upstream_ref'] ) ? $info['upstream_ref'] : '',
					'upstream_track'   => isset( $info['upstream_raw'] ) ? $info['upstream_raw'] : '',
					'ahead'            => isset( $info['ahead'] ) ? (int) $info['ahead'] : 0,
					'behind'           => isset( $info['behind'] ) ? (int) $info['behind'] : 0,
					'in_sync'          => isset( $info['in_sync'] ) ? (bool) $info['in_sync'] : false,
				);
			}
		} else {
			foreach ( $branches as $b ) {
				$info           = git_switcher_get_branch_last_commit_info( $repo_path, $b );
				$branch_items[] = array(
					'name'             => $b,
					'last_commit'      => isset( $info['timestamp'] ) ? $info['timestamp'] : '',
					'last_author'      => isset( $info['author'] ) ? $info['author'] : '',
					'last_commit_show' => isset( $info['show_stat'] ) ? $info['show_stat'] : '',
					'upstream'         => isset( $info['upstream_ref'] ) ? $info['upstream_ref'] : '',
					'upstream_track'   => isset( $info['upstream_raw'] ) ? $info['upstream_raw'] : '',
					'ahead'            => isset( $info['ahead'] ) ? (int) $info['ahead'] : 0,
					'behind'           => isset( $info['behind'] ) ? (int) $info['behind'] : 0,
					'in_sync'          => isset( $info['in_sync'] ) ? (bool) $info['in_sync'] : false,
				);
			}
		}

		$repos[] = array(
			'slug'     => $slug,
			'name'     => $plugin['name'],
			'folder'   => $plugin['folder'],
			'branch'   => $current_branch,
			'branches' => array_values( $branch_items ),
		);
	}

	usort(
		$repos,
		static function ( $a, $b ) {
			return strcasecmp( $a['folder'], $b['folder'] );
		}
	);

	return $repos;
}

/**
 * Build a map of plugin directory slug => path metadata.
 *
 * @return array<string, array<string, string>>
 */
function git_switcher_get_plugin_repo_map() {
	$map     = array();
	$plugins = glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR );

	if ( false === $plugins ) {
		return $map;
	}

	foreach ( $plugins as $plugin_dir ) {
		$realpath = realpath( $plugin_dir );
		if ( ! $realpath || ! is_dir( $realpath ) ) {
			continue;
		}

		$folder = basename( $realpath );
		$slug   = sanitize_key( $folder );

		$map[ $slug ] = array(
			'path'   => $realpath,
			'folder' => $folder,
			'name'   => $folder,
		);
	}

	return $map;
}

/**
 * Determine whether a path appears to be a git repository.
 *
 * @param  string $repo_path Repository path.
 * @return bool
 */
function git_switcher_is_git_repo( $repo_path ) {
	return '' !== git_switcher_get_git_dir( $repo_path );
}

/**
 * Resolve git directory for a repository (supports worktrees).
 *
 * @param  string $repo_path Repository path.
 * @return string Absolute git dir or empty string.
 */
function git_switcher_get_git_dir( $repo_path ) {
	$dot_git = $repo_path . '/.git';
	if ( ! file_exists( $dot_git ) ) {
		return '';
	}

	if ( is_dir( $dot_git ) ) {
		return $dot_git;
	}

	$contents = git_switcher_read_local_file( $dot_git );
	if ( false === $contents ) {
		return '';
	}

	if ( preg_match( '/gitdir:\s*(.+)/', $contents, $matches ) ) {
		$possible = trim( $matches[1] );
		$git_dir  = realpath( $repo_path . '/' . $possible );
		if ( ! $git_dir ) {
			$git_dir = realpath( $possible );
		}
		return $git_dir ? $git_dir : '';
	}

	return dirname( $dot_git );
}

/**
 * Get current branch (or short SHA for detached HEAD) for repository.
 *
 * @param  string $repo_path Repository path.
 * @return string
 */
function git_switcher_get_current_branch( $repo_path ) {
	$git_head = '';
	$git_dir  = git_switcher_get_git_dir( $repo_path );

	if ( '' !== $git_dir ) {
		$head_file = $git_dir . '/HEAD';
		if ( is_readable( $head_file ) ) {
			$head_content = git_switcher_read_local_file( $head_file );
			if ( false !== $head_content ) {
				$git_head = trim( $head_content );
			}
		}
	}

	if ( '' !== $git_head ) {
		if ( 0 === strpos( $git_head, 'ref: ' ) ) {
			$ref = trim( substr( $git_head, 5 ) );
			if ( 0 === strpos( $ref, 'refs/heads/' ) ) {
				return substr( $ref, strlen( 'refs/heads/' ) );
			}
			return $ref;
		}
		return substr( $git_head, 0, 7 );
	}

	return '';
}

/**
 * Get local branches for a repository.
 *
 * @param  string $repo_path Repository path.
 * @return string[]
 */
function git_switcher_get_local_branches( $repo_path ) {
	$branches = array();
	$git_dir  = git_switcher_get_git_dir( $repo_path );

	if ( '' !== $git_dir && is_dir( $git_dir . '/refs/heads' ) ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $git_dir . '/refs/heads' ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$rel        = str_replace( $git_dir . '/refs/heads/', '', $file->getPathname() );
				$rel        = str_replace( DIRECTORY_SEPARATOR, '/', $rel );
				$branches[] = ltrim( $rel, '/' );
			}
		}
	}

	if ( empty( $branches ) && '' !== $git_dir && is_readable( $git_dir . '/packed-refs' ) ) {
		$lines = file( $git_dir . '/packed-refs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false !== $lines ) {
			foreach ( $lines as $line ) {
				if ( isset( $line[0] ) && '#' === $line[0] ) {
					continue;
				}
				if ( false === strpos( $line, 'refs/heads/' ) ) {
					continue;
				}
				$parts = preg_split( '/\s+/', $line );
				$ref   = is_array( $parts ) ? end( $parts ) : '';
				if ( is_string( $ref ) && 0 === strpos( $ref, 'refs/heads/' ) ) {
					$branches[] = substr( $ref, strlen( 'refs/heads/' ) );
				}
			}
		}
	}

	$branches = array_values( array_unique( $branches ) );
	sort( $branches );

	return $branches;
}


/**
 * Get last commit unix timestamp and author name for a branch.
 *
 * Reads the most recent commit on the specified branch and returns the
 * commit timestamp and the committer's name. Returns an empty array on
 * failure.
 *
 * @param  string $repo_path Absolute filesystem path to the repository.
 * @param  string $branch    Branch name or ref to inspect.
 * @return array{timestamp:int|string,author:string}|array{} Array with
 *     'timestamp' (int unix timestamp) and 'author' (string committer name),
 *     or an empty array on failure.
 */


/**
 * Get commit details including `git show --stat` and shortstat summary line.
 *
 * @param  string $repo_path  Absolute path to repository.
 * @param  string $commit_ref Commit SHA or ref.
 * @return string Commit info and stat, or empty string on failure.
 */
function git_switcher_get_commit_show_stat( $repo_path, $commit_ref ) {
	if ( '' === $commit_ref ) {
		return '';
	}

	if ( ! function_exists( 'shell_exec' ) ) {
		return '';
	}

	$git_binary = git_switcher_get_git_binary();
	if ( '' === $git_binary ) {
		return '';
	}

	$cmd = escapeshellarg( $git_binary ) . ' -C ' . escapeshellarg( $repo_path ) . ' show --stat --no-patch --no-color ' . escapeshellarg( $commit_ref ) . ' 2>/dev/null';
	$raw = git_switcher_shell_exec( $cmd );
	if ( '' === $raw ) {
		return '';
	}

	$shortstat_cmd = escapeshellarg( $git_binary ) . ' -C ' . escapeshellarg( $repo_path ) . ' show --shortstat --format=' . escapeshellarg( '' ) . ' --no-color ' . escapeshellarg( $commit_ref ) . ' 2>/dev/null';
	$shortstat_raw = git_switcher_shell_exec( $shortstat_cmd );
	$shortstat     = trim( (string) $shortstat_raw );

	$out = trim( (string) $raw );
	if ( '' !== $shortstat && false === strpos( $out, $shortstat ) ) {
		$out .= "\n\n" . $shortstat;
	}

	return $out;
}


/**
 * Get tracking information for a branch (raw upstream:track and parsed counts).
 *
 * Uses git's "%(upstream:track)" format to obtain a short upstream tracking
 * description such as "[ahead 1]", "[behind 2]", or "[ahead 1, behind 2]".
 *
 * @return array{upstream_ref:string,raw:string,ahead:int,behind:int,in_sync:bool,gone:bool}
 */

// Removed: git_switcher_get_branch_track_counts() was unused; tracking
// counts are not currently computed without shell access.

/**
 * Read a git object from the repository.
 *
 * @param  string $repo_path Absolute path to repository.
 * @param  string $sha       SHA hash of the object.
 * @return string|false     Decompressed object content or false on failure.
 */
function git_switcher_read_git_object( $repo_path, $sha ) {
	$git_dir = git_switcher_get_git_dir( $repo_path );
	if ( '' === $git_dir ) {
		return false;
	}

	$path = $git_dir . '/objects/' . substr( $sha, 0, 2 ) . '/' . substr( $sha, 2 );
	if ( ! file_exists( $path ) ) {
		return false;
	}

	$data = git_switcher_read_local_file( $path );
	if ( false === $data ) {
		return false;
	}

	$decompressed = gzuncompress( $data );
	if ( false === $decompressed ) {
		return false;
	}

	return $decompressed;
}

/**
 * Get last commit unix timestamp and author name for a branch.
 *
 * Reads the most recent commit on the specified branch and returns the
 * commit timestamp and the committer's name. Returns an empty array on
 * failure.
 *
 * @param  string $repo_path Absolute filesystem path to the repository.
 * @param  string $branch    Branch name or ref to inspect.
 * @return array{timestamp:int|string,author:string}|array{} Array with
 *     'timestamp' (int unix timestamp) and 'author' (string committer name),
 *     or an empty array on failure.
 */
function git_switcher_get_branch_last_commit_info( $repo_path, $branch ) {
	$git_dir = git_switcher_get_git_dir( $repo_path );
	if ( '' === $git_dir ) {
		return array();
	}

	$ref_path = $git_dir . '/refs/heads/' . $branch;
	$sha      = '';
	if ( file_exists( $ref_path ) ) {
		$ref_contents = git_switcher_read_local_file( $ref_path );
		if ( false !== $ref_contents ) {
			$sha = trim( $ref_contents );
		}
	} else {
		// Check packed-refs.
		$packed_refs_path = $git_dir . '/packed-refs';
		if ( file_exists( $packed_refs_path ) ) {
			$lines = file( $packed_refs_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( false !== $lines ) {
				$ref = 'refs/heads/' . $branch;
				foreach ( $lines as $line ) {
					if ( isset( $line[0] ) && '#' === $line[0] ) {
						continue;
					}
					$parts = preg_split( '/\s+/', $line );
					if ( count( $parts ) >= 2 && $parts[1] === $ref ) {
						$sha = trim( $parts[0] );
						break;
					}
				}
			}
		}
	}

	if ( '' === $sha || ! ctype_xdigit( $sha ) ) {
		return array();
	}

	$object = git_switcher_read_git_object( $repo_path, $sha );
	if ( false === $object ) {
		return array();
	}

	$null_pos = strpos( $object, "\0" );
	if ( false === $null_pos ) {
		return array();
	}
	$content = substr( $object, $null_pos + 1 );
	$lines   = explode( "\n", $content );

	$author    = '';
	$timestamp = 0;

	foreach ( $lines as $line ) {
		if ( 0 === strpos( $line, 'author ' ) ) {
			// Format: author Name <email> 1234567890 +0000.
			if ( preg_match( '/^author (.+) <[^>]+> (\d+) .+$/', $line, $matches ) ) {
				$author    = trim( $matches[1] );
				$timestamp = (int) $matches[2];
			}
			break;
		}
	}

	// Default tracking values.
	$upstream_ref = '';
	$upstream_raw = '';
	$ahead        = 0;
	$behind       = 0;
	$in_sync      = false;
	$gone         = false;

	// If execution is available and a git binary is configured, try to
	// obtain upstream tracking info and compute ahead/behind counts
	// using git commands. Fall back to defaults on failure.
	$git_binary = git_switcher_get_git_binary();
	if ( git_switcher_shell_exec_available() && '' !== $git_binary ) {
		// upstream tracking description (e.g. "[ahead 1]").
		$for_each_cmd =
		escapeshellarg( $git_binary ) . ' -C ' . escapeshellarg( $repo_path ) .
		' for-each-ref --format=%(upstream:track) refs/heads/' . escapeshellarg( $branch ) . ' 2>/dev/null';
		$upstream_raw = trim( (string) git_switcher_shell_exec( $for_each_cmd ) );

		// resolve upstream ref (branch@{upstream}) to a human-friendly name.
		$revparse_cmd =
		escapeshellarg( $git_binary ) . ' -C ' . escapeshellarg( $repo_path ) .
		' rev-parse --abbrev-ref --symbolic-full-name ' . escapeshellarg( $branch . '@{u}' ) . ' 2>/dev/null';
		$upstream_ref = trim( (string) git_switcher_shell_exec( $revparse_cmd ) );

		if ( '' !== $upstream_ref ) {
			// Compute left/right commit counts: <local-only> <upstream-only>.
			$counts_cmd =
			escapeshellarg( $git_binary ) . ' -C ' . escapeshellarg( $repo_path ) .
			' rev-list --left-right --count ' . escapeshellarg( $branch . '...' . $branch . '@{u}' ) . ' 2>/dev/null';
			$counts_out = trim( (string) git_switcher_shell_exec( $counts_cmd ) );
			if ( preg_match( '/^(\d+)\s+(\d+)$/', $counts_out, $m ) ) {
				$ahead   = (int) $m[1];
				$behind  = (int) $m[2];
				$in_sync = ( 0 === $ahead && 0 === $behind );
			} else {
				// upstream likely gone or rev-list failed.
				$gone = true;
			}
		}
	}

	return array(
		'sha'          => $sha,
		'timestamp'    => $timestamp,
		'author'       => $author,
		'show_stat'    => git_switcher_get_commit_show_stat( $repo_path, $sha ),
		'upstream_ref' => $upstream_ref,
		'upstream_raw' => $upstream_raw,
		'ahead'        => $ahead,
		'behind'       => $behind,
		'in_sync'      => $in_sync,
		'gone'         => $gone,
	);
}

/**
 * Resolve git executable path.
 *
 * @return string
 */
function git_switcher_get_git_binary() {
	$configured = get_option( 'git_switcher_git_binary', '' );
	if ( is_string( $configured ) ) {
		$configured = trim( $configured );
		if ( '' !== $configured && is_executable( $configured ) ) {
			return $configured;
		}
	}

	$candidates = array(
		'/usr/bin/git',
		'/opt/homebrew/bin/git',
		'/usr/local/bin/git',
	);

	foreach ( $candidates as $candidate ) {
		if ( is_executable( $candidate ) ) {
			return $candidate;
		}
	}

	return '';
}


/**
 * Return whether any execution function is available for running commands.
 *
 * @return bool
 */
function git_switcher_shell_exec_available() {
	return function_exists( 'shell_exec' ) || function_exists( 'exec' );
}


/**
 * Execute a command and return its stdout as a string. Falls back to `exec`
 * when `shell_exec` is unavailable. Returns empty string on failure.
 *
 * Centralising system calls here allows graceful failure and a single
 * location for `phpcs` suppression.
 *
 * @param  string $cmd Shell command to execute.
 * @return string
 */
function git_switcher_shell_exec( $cmd ) {
	if ( function_exists( 'shell_exec' ) ) {
    	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- central wrapper.
		$out = shell_exec( $cmd );
		return null === $out ? '' : (string) $out;
	}

	if ( function_exists( 'exec' ) ) {
		$lines = array();
		$exit  = 0;
     // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- central wrapper.
		exec( $cmd, $lines, $exit );
		return implode( "\n", $lines );
	}

	return '';
}


/**
 * Execute a command using `exec` semantics (capture output array and exit code).
 * Falls back to `shell_exec` when `exec` is not available.
 *
 * @param  string $cmd          Command to run.
 * @param  array  $output_lines Output lines (by reference).
 * @param  int    $exit_code    Exit code (by reference).
 * @return int Exit code.
 */
function git_switcher_exec( $cmd, &$output_lines = array(), &$exit_code = 127 ) {
	$output_lines = array();
	$exit_code    = 127;

	if ( function_exists( 'exec' ) ) {
     // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- central wrapper.
		exec( $cmd, $output_lines, $exit_code );
		return $exit_code;
	}

	if ( function_exists( 'shell_exec' ) ) {
     // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- central wrapper.
		$raw = shell_exec( $cmd );
		if ( null === $raw ) {
			$output_lines = array();
			$exit_code    = 127;
			return $exit_code;
		}
		$output_lines = explode( "\n", trim( (string) $raw ) );
		$exit_code    = 0;
		return $exit_code;
	}

	return $exit_code;
}


/**
 * Fetch remote refs for a repository to refresh origin/* tracking refs.
 *
 * This runs a quiet `git fetch` using the configured git binary. Failures
 * are ignored to avoid breaking the UI if fetch cannot run.
 *
 * @param  string $repo_path Absolute path to repository.
 * @return void
 */
function git_switcher_fetch_remote_for_repo( $repo_path ) {
	$git_binary = git_switcher_get_git_binary();
	if ( '' === $git_binary ) {
		return;
	}

	// Fetch tags and prune deleted refs from origin; keep this quiet.
	$cmd = escapeshellarg( $git_binary ) . ' -C ' . escapeshellarg( $repo_path ) . ' fetch --tags --prune origin 2>/dev/null';
	git_switcher_shell_exec( $cmd );
}

/**
 * Read local file contents safely.
 *
 * @param  string $path Absolute local path.
 * @return string|false
 */
function git_switcher_read_local_file( $path ) {
	if ( ! is_readable( $path ) ) {
		return false;
	}

	global $wp_filesystem;

	if ( ! isset( $wp_filesystem ) || ! is_object( $wp_filesystem ) ) {
		include_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}

	if ( ! isset( $wp_filesystem ) || ! is_object( $wp_filesystem ) || ! method_exists( $wp_filesystem, 'get_contents' ) ) {
		return false;
	}

	$contents = $wp_filesystem->get_contents( $path );
	if ( false === $contents ) {
		return false;
	}

	return $contents;
}
