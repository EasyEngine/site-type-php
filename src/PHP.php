<?php

declare( ticks=1 );

namespace EE\Site\Type;

use EE;
use EE\Model\Site;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;
use function EE\Site\Utils\get_public_dir;
use function EE\Site\Utils\get_webroot;
use function EE\Site\Utils\check_alias_in_db;
use function EE\Utils\get_flag_value;
use function EE\Utils\get_value_if_flag_isset;

/**
 * Creates a simple PHP Website.
 *
 * ## EXAMPLES
 *
 *     # Create simple PHP site
 *     $ ee site create example.com --type=php
 *
 * @package ee-cli
 */
class PHP extends EE_Site_Command {

	/**
	 * @var string $cache_type Type of caching being used.
	 */
	private $cache_type;

	/**
	 * @var int $level The level of creation in progress. Essential for rollback in case of failure.
	 */
	private $level;

	/**
	 * @var object $logger Object of logger.
	 */
	private $logger;

	/**
	 * @var bool $skip_status_check To skip site status check pre-installation.
	 */
	private $skip_status_check;

	/**
	 * @var bool $force To reset remote database.
	 */
	private $force;

	public function __construct() {

		parent::__construct();
		$this->level  = 0;
		$this->logger = \EE::get_file_logger()->withName( 'site_php_command' );

		$this->site_data['site_type'] = 'php';
	}

	/**
	 * Runs the standard PHP Site installation.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--cache]
	 * : Use redis cache for PHP.
	 *
	 * [--admin-email=<admin-email>]
	 * : E-Mail of the administrator.
	 *
	 *  [--with-db]
	 * : Create database for php site.
	 *
	 * [--local-db]
	 * : Create separate db container instead of using global db.
	 *
	 * [--php=<php-version>]
	 * : PHP version for site. Currently only supports PHP 5.6, 7.0, 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 and latest.
	 * ---
	 * default: 8.3
	 * options:
	 *	- 5.6
	 *	- 7.0
	 *	- 7.2
	 *	- 7.3
	 *	- 7.4
	 *	- 8.0
	 *	- 8.1
	 *	- 8.2
	 *	- 8.3
	 *	- 8.4
	 *	- latest
	 * ---
	 *
	 * [--alias-domains=<domains>]
	 * : Comma separated list of alias domains for the site.
	 *
	 * [--dbname=<dbname>]
	 * : Set the database name.
	 *
	 * [--dbuser=<dbuser>]
	 * : Set the database user.
	 *
	 * [--dbpass=<dbpass>]
	 * : Set the database password.
	 *
	 * [--dbhost=<dbhost>]
	 * : Set the database host. Pass value only when remote dbhost is required.
	 *
	 * [--with-local-redis]
	 * : Enable cache with local redis container.
	 *
	 * [--skip-check]
	 * : If set, the database connection is not checked.
	 *
	 * [--skip-status-check]
	 * : Skips site status check.
	 *
	 * [--ssl]
	 * : Enables ssl on site.
	 * ---
	 * options:
	 *      - le
	 *      - self
	 *      - inherit
	 *      - custom
	 * ---
	 *
	 * [--ssl-key=<ssl-key-path>]
	 * : Path to the SSL key file.
	 *
	 * [--ssl-crt=<ssl-crt-path>]
	 * : Path to the SSL crt file.
	 *
	 * [--wildcard]
	 * : Gets wildcard SSL .
	 *
	 * [--force]
	 * : Resets the remote database if it is not empty.
	 *
	 * [--public-dir]
	 * : Set custom source directory for site inside htdocs.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create php site (without db)
	 *     $ ee site create example.com --type=php
	 *
	 *     # Create php site with db
	 *     $ ee site create example.com --type=php --with-db
	 *
	 *     # Create php site with ssl from letsencrypt
	 *     $ ee site create example.com --type=php --ssl=le
	 *
	 *     # Create php site with wildcard ssl
	 *     $ ee site create example.com --type=php --ssl=le --wildcard
	 *
	 *     # Create site with alias domains and ssl
	 *     $ ee site create example.com --type=html --alias-domains='a.com,*.a.com,b.com' --ssl=le
	 *
	 *     # Create php site with self signed certificate
	 *     $ ee site create example.com --type=php --ssl=self
	 *
	 *     # Create php site with remote database
	 *     $ ee site create example.com --type=php --with-db --dbhost=localhost --dbuser=username --dbpass=password
	 *
	 *     # Create php site with custom source directory inside htdocs ( SITE_ROOT/app/htdocs/public )
	 *     $ ee site create example.com --type=php --public-dir=public
	 *
	 *     # Create PHP site with custom ssl certs
	 *     $ ee site create example.com --ssl=custom  --ssl-key='/path/to/example.com.key' --ssl-crt='/path/to/example.com.crt'
	 *
	 */
	public function create( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site create start' );
		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? array( 'NULL' ) : $assoc_args );
		$this->site_data['site_url']  = strtolower( \EE\Utils\remove_trailing_slash( $args[0] ) );

		if ( Site::find( $this->site_data['site_url'] ) ) {
			\EE::error( sprintf( "Site %1\$s already exists. If you want to re-create it please delete the older one using:\n`ee site delete %1\$s`", $this->site_data['site_url'] ) );
		}

		$alias_domains = \EE\Utils\get_flag_value( $assoc_args, 'alias-domains', '' );

		$alias_domain_to_check   = explode( ',', $alias_domains );
		$alias_domain_to_check[] = $this->site_data['site_url'];
		check_alias_in_db( $alias_domain_to_check );

		$this->site_data['site_fs_path']      = WEBROOT . $this->site_data['site_url'];
		$this->cache_type                     = \EE\Utils\get_flag_value( $assoc_args, 'cache' );
		$this->site_data['site_ssl_wildcard'] = \EE\Utils\get_flag_value( $assoc_args, 'wildcard' );
		$this->site_data['php_version']       = \EE\Utils\get_flag_value( $assoc_args, 'php', 'latest' );
		$this->site_data['app_sub_type']      = 'php';

		$this->site_data['site_container_fs_path'] = get_public_dir( $assoc_args );

		$local_cache                   = \EE\Utils\get_flag_value( $assoc_args, 'with-local-redis' );
		$this->site_data['cache_host'] = '';

		if ( $this->cache_type ) {
			$this->site_data['cache_host'] = $local_cache ? 'redis' : 'global-redis';
		}

		$this->site_data['site_ssl'] = get_value_if_flag_isset( $assoc_args, 'ssl', 'le' );
		if ( 'custom' === $this->site_data['site_ssl'] ) {
			try {
				$this->validate_site_custom_ssl( get_flag_value( $assoc_args, 'ssl-key' ), get_flag_value( $assoc_args, 'ssl-crt' ) );
			} catch ( \Exception $e ) {
				$this->catch_clean( $e );
			}
		}

		$this->site_data['alias_domains'] = $this->site_data['site_url'];
		$this->site_data['alias_domains'] .= ',';
		if ( ! empty( $alias_domains ) ) {
			$comma_seprated_domains = explode( ',', $alias_domains );
			foreach ( $comma_seprated_domains as $domain ) {
				$trimmed_domain                   = trim( $domain );
				$this->site_data['alias_domains'] .= $trimmed_domain . ',';
			}
		}
		$this->site_data['alias_domains'] = substr( $this->site_data['alias_domains'], 0, - 1 );

		$supported_php_versions = [ 5.6, 7.0, 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 'latest' ];
		if ( ! in_array( $this->site_data['php_version'], $supported_php_versions ) ) {
			$old_version = $this->site_data['php_version'];
			$floor       = (int) floor( $this->site_data['php_version'] );
			if ( 5 === $floor ) {
				$this->site_data['php_version'] = 5.6;
			} elseif ( 7 === $floor ) {
				$this->site_data['php_version'] = 7.4;
				$old_version                    .= ' yet';
			} elseif ( 8 === $floor ) {
				$this->site_data['php_version'] = 8.3;
				$old_version                    .= ' yet';
			} else {
				EE::error( 'Unsupported PHP version: ' . $this->site_data['php_version'] );
			}
			\EE::confirm( sprintf( 'EEv4 does not support PHP %s. Continue with PHP %s?', $old_version, $this->site_data['php_version'] ) );
		}

		$this->site_data['php_version'] = ( 8.0 === (double) $this->site_data['php_version'] ) ? 'latest' : $this->site_data['php_version'];

		if ( $this->cache_type && ! $local_cache ) {
			\EE\Service\Utils\init_global_container( GLOBAL_REDIS );
		}

		\EE\Service\Utils\nginx_proxy_check();

		$this->site_data['db_host'] = '';
		if ( ! empty( $assoc_args['with-db'] ) ) {
			$this->site_data['app_sub_type'] = 'mysql';
			$this->site_data['db_name']      = \EE\Utils\get_flag_value( $assoc_args, 'dbname', str_replace( [
				'.',
				'-'
			], '_', $this->site_data['site_url'] ) );
			$this->site_data['db_host']      = \EE\Utils\get_flag_value( $assoc_args, 'dbhost', GLOBAL_DB );
			$this->site_data['db_port']      = '3306';
			$this->site_data['db_user']      = \EE\Utils\get_flag_value( $assoc_args, 'dbuser', $this->create_site_db_user( $this->site_data['site_url'] ) );
			$this->site_data['db_password']  = \EE\Utils\get_flag_value( $assoc_args, 'dbpass', \EE\Utils\random_password() );

			if ( $this->cache_type && ! $local_cache ) {
				\EE\Service\Utils\init_global_container( GLOBAL_REDIS );
			}

			if ( \EE\Utils\get_flag_value( $assoc_args, 'local-db' ) ) {
				$this->site_data['db_host'] = 'db';
			}

			$this->site_data['db_root_password'] = ( 'db' === $this->site_data['db_host'] ) ? \EE\Utils\random_password() : '';

			if ( GLOBAL_DB === $this->site_data['db_host'] ) {
				\EE\Service\Utils\init_global_container( GLOBAL_DB );
				try {
					$user_data = \EE\Site\Utils\create_user_in_db( GLOBAL_DB, $this->site_data['db_name'], $this->site_data['db_user'], $this->site_data['db_password'] );
					if ( ! $user_data ) {
						throw new \Exception( sprintf( 'Could not create user %s. Please check logs.', $this->site_data['db_user'] ) );
					}
				} catch ( \Exception $e ) {
					$this->catch_clean( $e );
				}
				$this->site_data['db_name']     = $user_data['db_name'];
				$this->site_data['db_user']     = $user_data['db_user'];
				$this->site_data['db_password'] = $user_data['db_pass'];
			} elseif ( 'db' !== $this->site_data['db_host'] ) {
				// If user wants to connect to remote database.
				if ( ! isset( $assoc_args['dbuser'] ) || ! isset( $assoc_args['dbpass'] ) ) {
					\EE::error( '`--dbuser` and `--dbpass` are required for remote db host.' );
				}
				$arg_host_port              = explode( ':', $this->site_data['db_host'] );
				$this->site_data['db_host'] = $arg_host_port[0];
				$this->site_data['db_port'] = empty( $arg_host_port[1] ) ? '3306' : $arg_host_port[1];
			}
		}
		$this->site_data['app_admin_email'] = \EE\Utils\get_flag_value( $assoc_args, 'admin-email', strtolower( 'admin@' . $this->site_data['site_url'] ) );
		$this->skip_status_check            = \EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );
		$this->force                        = \EE\Utils\get_flag_value( $assoc_args, 'force' );

		\EE::log( 'Configuring project.' );

		$this->create_site( $assoc_args );

		\EE\Utils\delem_log( 'site create end' );
	}

	/**
	 * Creates database user for a site
	 *
	 * @param string $site_url URL of site.
	 *
	 * @return string Generated db user.
	 */
	private function create_site_db_user( string $site_url ): string {
		if ( strlen( $site_url ) > 53 ) {
			$site_url = substr( $site_url, 0, 53 );
		}

		return $site_url . '-' . \EE\Utils\random_password( 6 );
	}

	/**
	 * Display all the relevant site information, credentials and useful links.
	 *
	 * [<site-name>]
	 * : Name of the website whose info is required.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Display site info
	 *     $ ee site info example.com
	 *
	 */
	public function info( $args, $assoc_args ) {

		$format = \EE\Utils\get_flag_value( $assoc_args, 'format' );

		\EE\Utils\delem_log( 'site info start' );
		if ( ! isset( $this->site_data['site_url'] ) ) {
			$args             = auto_site_name( $args, 'php', __FUNCTION__ );
			$this->site_data  = get_site_info( $args, false );
			$this->cache_type = $this->site_data['cache_nginx_fullpage'];
		}

		if ( 'json' === $format ) {
			$site = (array) Site::find( $this->site_data['site_url'] );
			$site = reset( $site );
			EE::log( json_encode( $site ) );

			return;
		}

		$ssl    = $this->site_data['site_ssl'] ? 'Enabled' : 'Not Enabled';
		$prefix = ( $this->site_data['site_ssl'] ) ? 'https://' : 'http://';
		$info   = [ [ 'Site', $prefix . $this->site_data['site_url'] ] ];
		if ( ! empty( $this->site_data['admin_tools'] ) ) {
			$info[] = [ 'Access admin-tools', $prefix . $this->site_data['site_url'] . '/ee-admin/' ];
		}
		$info[] = [ 'Site Root', $this->site_data['site_fs_path'] ];
		if ( 'mysql' === $this->site_data['app_sub_type'] ) {
			$info[] = [ 'DB Host', $this->site_data['db_host'] ];
			if ( ! empty( $this->site_data['db_root_password'] ) ) {
				$info[] = [ 'DB Root Password', $this->site_data['db_root_password'] ];
			}
			$info[] = [ 'DB Name', $this->site_data['db_name'] ];
			$info[] = [ 'DB User', $this->site_data['db_user'] ];
			$info[] = [ 'DB Password', $this->site_data['db_password'] ];
		}

		$alias_domains            = implode( ',', array_diff( explode( ',', $this->site_data['alias_domains'] ), [ $this->site_data['site_url'] ] ) );
		$info_alias_domains_value = empty( $alias_domains ) ? 'None' : $alias_domains;

		$info[] = [ 'Alias Domains', $info_alias_domains_value ];
		$info[] = [ 'E-Mail', $this->site_data['app_admin_email'] ];
		$info[] = [ 'SSL', $ssl ];

		if ( $this->site_data['site_ssl'] ) {
			$info[] = [ 'SSL Wildcard', $this->site_data['site_ssl_wildcard'] ? 'Yes' : 'No' ];
		}
		$info[] = [ 'Cache', $this->cache_type ? 'Enabled' : 'None' ];

		\EE\Utils\format_table( $info );

		\EE\Utils\delem_log( 'site info end' );
	}

	/**
	 * Function to configure site and copy all the required files.
	 */
	private function configure_site_files() {

		$site_conf_dir           = $this->site_data['site_fs_path'] . '/config';
		$site_conf_env           = $this->site_data['site_fs_path'] . '/.env';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/conf.d/main.conf';
		$site_php_ini            = $site_conf_dir . '/php/php/conf.d/custom.ini';
		$site_src_dir            = $this->site_data['site_fs_path'] . '/app/htdocs';
		$custom_conf_dest        = $site_conf_dir . '/nginx/custom/user.conf';
		$custom_conf_source      = SITE_PHP_TEMPLATE_ROOT . '/config/nginx/user.conf.mustache';
		$admin_tools_conf_dest   = $site_conf_dir . '/nginx/custom/admin-tools.conf';
		$admin_tools_conf_source = SITE_PHP_TEMPLATE_ROOT . '/config/nginx/admin-tools.conf.mustache';
		$process_user            = posix_getpwuid( posix_geteuid() );

		\EE::log( 'Creating PHP site ' . $this->site_data['site_url'] );
		\EE::log( 'Copying configuration files.' );

		$env_data = [
			'virtual_host' => $this->site_data['site_url'],
			'user_id'      => $process_user['uid'],
			'group_id'     => $process_user['gid'],
		];

		if ( 'mysql' === $this->site_data['app_sub_type'] ) {
			$local   = ( 'db' === $this->site_data['db_host'] ) ? true : false;
			$db_host = $local ? $this->site_data['db_host'] : $this->site_data['db_host'] . ':' . $this->site_data['db_port'];

			$env_data['local']         = $local;
			$env_data['root_password'] = $this->site_data['db_root_password'];
			$env_data['database_name'] = $this->site_data['db_name'];
			$env_data['database_user'] = $this->site_data['db_user'];
			$env_data['user_password'] = $this->site_data['db_password'];
		}

		$server_name          = implode( ' ', explode( ',', $this->site_data['alias_domains'] ) );
		$default_conf_content = $this->generate_default_conf( $this->cache_type, $server_name );

		$custom_ini      = '5.6' === (string) $this->site_data['php_version'] ? 'php.ini-56.mustache' : 'php.ini.mustache';
		$env_content     = \EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/config/.env.mustache', $env_data );
		$php_ini_content = file_get_contents( SITE_WP_TEMPLATE_ROOT . '/config/php-fpm/' . $custom_ini );

		try {
			$this->dump_docker_compose_yml( [ 'nohttps' => true ] );
			$this->fs->dumpFile( $site_conf_env, $env_content );
			if ( ! IS_DARWIN ) {
				\EE\Site\Utils\start_site_containers( $this->site_data['site_fs_path'], [ 'nginx', 'postfix' ] );
			}
			\EE\Site\Utils\set_postfix_files( $this->site_data['site_url'], $this->site_data['site_fs_path'] . '/services' );
			$this->fs->dumpFile( $site_nginx_default_conf, $default_conf_content );
			$this->fs->copy( $custom_conf_source, $custom_conf_dest );
			$this->fs->copy( $admin_tools_conf_source, $admin_tools_conf_dest );
			$this->fs->remove( $this->site_data['site_fs_path'] . '/app/html' );
			$this->fs->dumpFile( $site_php_ini, $php_ini_content );
			if ( IS_DARWIN ) {
				if ( ! empty( $this->site_data['db_host'] ) && 'db' === $this->site_data['db_host'] ) {
					$db_conf_file = $this->site_data['site_fs_path'] . '/services/mariadb/conf/my.cnf';
					$this->fs->copy( SERVICE_TEMPLATE_ROOT . '/my.cnf.mustache', $db_conf_file );
				}
				\EE\Site\Utils\start_site_containers( $this->site_data['site_fs_path'], [ 'nginx', 'php', 'postfix' ] );
			} else {
				\EE\Site\Utils\restart_site_containers( $this->site_data['site_fs_path'], [ 'nginx', 'php' ] );
			}

			$site_src_dir = get_webroot( $site_src_dir, $this->site_data['site_container_fs_path'] );

			$index_data = [
				'version'       => 'v' . EE_VERSION,
				'site_src_root' => $site_src_dir,
			];

			$index_html = \EE\Utils\mustache_render( SITE_PHP_TEMPLATE_ROOT . '/index.php.mustache', $index_data );
			$this->fs->dumpFile( $site_src_dir . '/index.php', $index_html );

			// Assign www-data user ownership.
			chdir( $this->site_data['site_fs_path'] );
			\EE_DOCKER::docker_compose_exec( 'chown -R www-data: /var/www/', 'php', 'bash', 'root' );

			\EE::success( 'Configuration files copied.' );
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Generate and place docker-compose.yml file.
	 *
	 * @param array $additional_filters Filters to alter docker-compose file.
	 *
	 * @ignorecommand
	 */
	public function dump_docker_compose_yml( $additional_filters = [] ) {

		$site_conf_dir           = $this->site_data['site_fs_path'] . '/config';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/conf.d/main.conf';
		$site_php_ini            = $site_conf_dir . '/php/php/conf.d/custom.ini';

		$volumes = [
			'nginx'   => [
				[
					'name'            => 'htdocs',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/app',
					'container_path'  => '/var/www',
				],
				[
					'name'            => 'config_nginx',
					'path_to_symlink' => dirname( dirname( $site_nginx_default_conf ) ),
					'container_path'  => '/usr/local/openresty/nginx/conf',
					'skip_darwin'     => true,
				],
				[
					'name'            => 'config_nginx',
					'path_to_symlink' => $site_nginx_default_conf,
					'container_path'  => '/usr/local/openresty/nginx/conf/conf.d/main.conf',
					'skip_linux'      => true,
					'skip_volume'     => true,
				],
				[
					'name'            => 'log_nginx',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/logs/nginx',
					'container_path'  => '/var/log/nginx',
				],
			],
			'php'     => [
				[
					'name'            => 'htdocs',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/app',
					'container_path'  => '/var/www',
				],
				[
					'name'            => 'config_php',
					'path_to_symlink' => $site_conf_dir . '/php',
					'container_path'  => '/usr/local/etc',
					'skip_darwin'     => true,
				],
				[
					'name'            => 'config_php',
					'path_to_symlink' => $site_php_ini,
					'container_path'  => '/usr/local/etc/php/php/conf.d/custom.ini',
					'skip_linux'      => true,
					'skip_volume'     => true,
				],
				[
					'name'            => 'log_php',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/logs/php',
					'container_path'  => '/var/log/php',
				],
			],
			'postfix' => [
				[
					'name'            => '/dev/log',
					'path_to_symlink' => '/dev/log',
					'container_path'  => '/dev/log',
					'skip_volume'     => true,
					'skip_darwin'     => true,
				],
				[
					'name'            => 'data_postfix',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/services/postfix/spool',
					'container_path'  => '/var/spool/postfix',
				],
				[
					'name'            => 'ssl_postfix',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/services/postfix/ssl',
					'container_path'  => '/etc/ssl/postfix',
				],
				[
					'name'            => 'config_postfix',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/config/postfix',
					'container_path'  => '/etc/postfix',
					'skip_darwin'     => true,
				],
			],
		];

		if ( ! empty( $this->site_data['db_host'] ) && 'db' === $this->site_data['db_host'] ) {
			$volumes['db'] = [
				[
					'name'            => 'db_data',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/services/mariadb/data',
					'container_path'  => '/var/lib/mysql',
				],
				[
					'name'            => 'db_conf',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/services/mariadb/conf',
					'container_path'  => '/etc/mysql',
					'skip_darwin'     => true,
				],
				[
					'name'            => 'db_conf',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/services/mariadb/conf/my.cnf',
					'container_path'  => '/etc/mysql/my.cnf',
					'skip_linux'      => true,
					'skip_volume'     => true,
				],
				[
					'name'            => 'db_logs',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/services/mariadb/logs',
					'container_path'  => '/var/log/mysql',
				],
			];
		}

		if ( ! IS_DARWIN && empty( \EE_DOCKER::get_volumes_by_label( $this->site_data['site_url'] ) ) ) {
			foreach ( $volumes as $volume ) {
				\EE_DOCKER::create_volumes( $this->site_data['site_url'], $volume );
			}
		}

		// Add newrelic volume later on as it is not required to be created. There is a global volume for it.
		$volumes['php'][] = [
			'name'            => 'newrelic_sock',
			'path_to_symlink' => '',
			'container_path'  => '/run/newrelic',
			'skip_darwin'     => true,
		];

		$site_docker_yml = $this->site_data['site_fs_path'] . '/docker-compose.yml';

		$filter                  = [];
		$filter[]                = $this->site_data['cache_host'];
		$filter['site_url']      = $this->site_data['site_url'];
		$filter['site_prefix']   = \EE_DOCKER::get_docker_style_prefix( $this->site_data['site_url'] );
		$filter['is_ssl']        = $this->site_data['site_ssl'];
		$filter['php_version']   = ( string ) $this->site_data['php_version'];
		$filter['alias_domains'] = implode( ',', array_diff( explode( ',', $this->site_data['alias_domains'] ), [ $this->site_data['site_url'] ] ) );

		if ( 'mysql' === $this->site_data['app_sub_type'] ) {
			$filter[] = $this->site_data['db_host'];
		}

		foreach ( $additional_filters as $key => $addon_filter ) {
			$filter[ $key ] = $addon_filter;
		}

		$site_docker            = new Site_PHP_Docker();
		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter, $volumes );
		$this->fs->dumpFile( $site_docker_yml, $docker_compose_content );
	}


	/**
	 * Function to generate main.conf from mustache templates.
	 *
	 * @param boolean $cache_type Cache enabled or not.
	 * @param string $server_name Name of server to use in virtual_host.
	 *
	 * @return string Parsed mustache template string output.
	 */
	private function generate_default_conf( $cache_type, $server_name ) {

		$default_conf_data['server_name']        = $server_name;
		$default_conf_data['document_root']      = rtrim( $this->site_data['site_container_fs_path'], '/' );
		$default_conf_data['site_url']           = $this->site_data['site_url'];
		$default_conf_data['include_php_conf']   = ! $cache_type;
		$default_conf_data['include_redis_conf'] = $cache_type;
		$default_conf_data['cache_host']         = $this->site_data['cache_host'];

		return \EE\Utils\mustache_render( SITE_PHP_TEMPLATE_ROOT . '/config/nginx/main.conf.mustache', $default_conf_data );
	}

	/**
	 * Verify if the passed database credentials are working or not.
	 *
	 * @throws \Exception
	 */
	private function maybe_verify_remote_db_connection() {

		if ( in_array( $this->site_data['db_host'], [ 'db', GLOBAL_DB ], true ) ) {
			return;
		}
		$db_host        = $this->site_data['db_host'];
		$img_versions   = \EE\Utils\get_image_versions();
		$container_name = \EE\Utils\random_password();
		$network        = ( GLOBAL_DB === $this->site_data['db_host'] ) ? "--network='" . GLOBAL_FRONTEND_NETWORK . "'" : '';

		$run_temp_container = sprintf(
			'docker run --name %s %s -e MYSQL_ROOT_PASSWORD=%s -d --restart always easyengine/mariadb:%s',
			$container_name,
			$network,
			\EE\Utils\random_password(),
			$img_versions['easyengine/mariadb']
		);
		if ( ! \EE::exec( $run_temp_container ) ) {
			\EE::exec( "docker rm -f $container_name" );
			throw new \Exception( 'There was a problem creating container to test mysql connection. Please check the logs' );
		}

		// Docker needs special handling if we want to connect to host machine.
		// The since we're inside the container and we want to access host machine,
		// we would need to replace localhost with default gateway.
		if ( '127.0.0.1' === $db_host || 'localhost' === $db_host ) {
			$launch = \EE::launch( sprintf( "docker exec %s bash -c \"ip route show default | cut -d' ' -f3\"", $container_name ) );

			if ( ! $launch->return_code ) {
				$db_host = trim( $launch->stdout, "\n" );
			} else {
				\EE::exec( "docker rm -f $container_name" );
				throw new \Exception( 'There was a problem in connecting to the database. Please check the logs' );
			}
		}

		\EE::log( 'Verifying connection to remote database' );

		$check_db_connection = sprintf(
			"docker exec %s sh -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='EXIT'\"",
			$container_name,
			$db_host,
			$this->site_data['db_port'],
			$this->site_data['db_user'],
			$this->site_data['db_password']
		);
		if ( ! \EE::exec( $check_db_connection ) ) {
			\EE::exec( "docker rm -f $container_name" );
			throw new \Exception( 'Unable to connect to remote db' );
		}
		\EE::success( 'Connection to remote db verified' );

		$name            = str_replace( '_', '\_', $this->site_data['db_name'] );
		$check_db_exists = sprintf( "docker exec %s bash -c \"mysqlshow --user='%s' --password='%s' --host='%s' --port='%s' '%s'\"", $container_name, $this->site_data['db_user'], $this->site_data['db_password'], $db_host, $this->site_data['db_port'], $name );

		if ( ! \EE::exec( $check_db_exists ) ) {
			\EE::log( sprintf( 'Database `%s` does not exist. Attempting to create it.', $this->site_data['db_name'] ) );
			$create_db_command = sprintf(
				"docker exec %s bash -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='CREATE DATABASE %s;'\"",
				$container_name,
				$db_host,
				$this->site_data['db_port'],
				$this->site_data['db_user'],
				$this->site_data['db_password'],
				$this->site_data['db_name']
			);

			if ( ! \EE::exec( $create_db_command ) ) {
				\EE::exec( "docker rm -f $container_name" );
				throw new \Exception( sprintf(
					'Could not create database `%s` on `%s:%s`. Please check if %s has rights to create database or manually create a database and pass with `--dbname` parameter.',
					$this->site_data['db_name'],
					$this->site_data['db_host'],
					$this->site_data['db_port'],
					$this->site_data['db_user']
				) );
			}
		} else {
			if ( $this->force ) {
				\EE::exec(
					sprintf(
						"docker exec %s bash -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='DROP DATABASE %s;'\"",
						$container_name,
						$db_host,
						$this->site_data['db_port'],
						$this->site_data['db_user'],
						$this->site_data['db_password'],
						$this->site_data['db_name']
					)
				);
				\EE::exec(
					sprintf(
						"docker exec %s bash -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='CREATE DATABASE %s;'\"",
						$container_name,
						$db_host,
						$this->site_data['db_port'],
						$this->site_data['db_user'],
						$this->site_data['db_password'],
						$this->site_data['db_name']
					)
				);
			}
			$check_tables = sprintf(
				"docker exec %s bash -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='USE %s; show tables;'\"",
				$container_name,
				$db_host,
				$this->site_data['db_port'],
				$this->site_data['db_user'],
				$this->site_data['db_password'],
				$this->site_data['db_name']
			);

			$launch = \EE::launch( $check_tables );
			if ( ! $launch->return_code ) {
				$tables = trim( $launch->stdout, "\n" );
				if ( ! empty( $tables ) ) {
					\EE::exec( "docker rm -f $container_name" );
					throw new \Exception( sprintf( 'Some database tables seem to exist in database %s. Please backup and reset the database or use `--force` in the site create command to reset it.', $this->site_data['db_name'] ) );
				}
			} else {
				\EE::exec( "docker rm -f $container_name" );
				throw new \Exception( 'There was a problem in connecting to the database. Please check the logs' );
			}
		}
		\EE::exec( "docker rm -f $container_name" );
	}

	/**
	 * Function to create the site.
	 */
	private function create_site( $assoc_args ) {

		$this->level = 1;
		try {
			if ( 'inherit' === $this->site_data['site_ssl'] ) {
				$this->check_parent_site_certs( $this->site_data['site_url'] );
			}

			\EE\Site\Utils\create_site_root( $this->site_data['site_fs_path'], $this->site_data['site_url'] );
			$this->level = 2;

			$containers = [ 'nginx', 'postfix' ];

			if ( ! empty( $this->site_data['db_host'] ) && 'db' === $this->site_data['db_host'] ) {
				$this->maybe_verify_remote_db_connection();
				$containers[] = 'db';
			}
			$this->level = 3;
			$this->configure_site_files();

			\EE\Site\Utils\configure_postfix( $this->site_data['site_url'], $this->site_data['site_fs_path'] );

			if ( ! $this->site_data['site_ssl'] || 'self' === $this->site_data['site_ssl'] ) {
				\EE\Site\Utils\create_etc_hosts_entry( $this->site_data['site_url'] );
			}
			if ( ! $this->skip_status_check ) {
				$this->level = 4;
				\EE\Site\Utils\site_status_check( $this->site_data['site_url'] );
			}

			if ( 'custom' === $this->site_data['site_ssl'] ) {
				$this->custom_site_ssl();
			}

			$this->www_ssl_wrapper( [ 'nginx' ] );
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}

		$this->info( [ $this->site_data['site_url'] ], [] );
		$this->create_site_db_entry();
	}

	/**
	 * Function to save the site configuration entry into database.
	 */
	private function create_site_db_entry() {
		$ssl = null;

		$data = [
			'site_url'               => $this->site_data['site_url'],
			'site_type'              => $this->site_data['site_type'],
			'app_admin_email'        => $this->site_data['app_admin_email'],
			'cache_nginx_browser'    => (int) $this->cache_type,
			'cache_nginx_fullpage'   => (int) $this->cache_type,
			'cache_mysql_query'      => (int) $this->cache_type,
			'cache_host'             => $this->site_data['cache_host'],
			'alias_domains'          => $this->site_data['alias_domains'],
			'site_fs_path'           => $this->site_data['site_fs_path'],
			'site_ssl'               => $this->site_data['site_ssl'],
			'site_ssl_wildcard'      => $this->site_data['site_ssl_wildcard'] ? 1 : 0,
			'php_version'            => $this->site_data['php_version'],
			'created_on'             => date( 'Y-m-d H:i:s', time() ),
			'app_sub_type'           => $this->site_data['app_sub_type'],
			'site_container_fs_path' => rtrim( $this->site_data['site_container_fs_path'], '/' ),
		];

		if ( 'mysql' === $this->site_data['app_sub_type'] ) {
			$data['db_name']          = $this->site_data['db_name'];
			$data['db_user']          = $this->site_data['db_user'];
			$data['db_host']          = $this->site_data['db_host'];
			$data['db_port']          = isset( $this->site_data['db_port'] ) ? $this->site_data['db_port'] : '';
			$data['db_password']      = $this->site_data['db_password'];
			$data['db_root_password'] = $this->site_data['db_root_password'];
		}

		try {
			if ( Site::create( $data ) ) {
				\EE::log( 'Site entry created.' );
			} else {
				throw new \Exception( 'Error creating site entry in database.' );
			}
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Restarts containers associated with site.
	 * When no service(--nginx etc.) is specified, all site containers will be restarted.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Restart all containers of site.
	 *
	 * [--nginx]
	 * : Restart nginx container of site.
	 *
	 * [--php]
	 * : Restart php container of site.
	 *
	 * [--db]
	 * : Restart db container of site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Restart all containers of site
	 *     $ ee site restart example.com
	 *
	 *     # Restart single container of site
	 *     $ ee site restart example.com --nginx
	 *
	 */
	public function restart( $args, $assoc_args, $whitelisted_containers = [] ) {

		$args            = auto_site_name( $args, 'php', __FUNCTION__ );
		$this->site_data = get_site_info( $args, false );

		$whitelisted_containers = [ 'nginx', 'php' ];

		if ( 'mysql' === $this->site_data['app_sub_type'] && 'db' === $this->site_data['db_host'] ) {
			$whitelisted_containers[] = 'db';
		}
		parent::restart( $args, $assoc_args, $whitelisted_containers );
	}

	/**
	 * Reload services in containers without restarting container(s) associated with site.
	 * When no service(--nginx etc.) is specified, all services will be reloaded.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Reload all services of site(which are supported).
	 *
	 * [--nginx]
	 * : Reload nginx service in container.
	 *
	 * [--php]
	 * : Reload php container of site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Reload all containers of site
	 *     $ ee site reload example.com
	 *
	 *     # Reload single containers of site
	 *     $ ee site reload example.com --nginx
	 *
	 */
	public function reload( $args, $assoc_args, $whitelisted_containers = [], $reload_commands = [] ) {
		$whitelisted_containers = [ 'nginx', 'php' ];
		$reload_commands['php'] = "php bash -c 'kill -USR2 1'";
		parent::reload( $args, $assoc_args, $whitelisted_containers, $reload_commands );
	}


	/**
	 * Catch and clean exceptions.
	 *
	 * @param \Exception $e
	 */
	private function catch_clean( $e ) {
		\EE\Utils\delem_log( 'site cleanup start' );
		\EE::warning( $e->getMessage() );
		\EE::warning( 'Initiating clean-up.' );
		$db_data = ( empty( $this->site_data['db_host'] ) || 'db' === $this->site_data['db_host'] ) ? [] : [
			'db_host' => $this->site_data['db_host'],
			'db_user' => $this->site_data['db_user'],
			'db_name' => $this->site_data['db_name'],
		];
		$this->delete_site( $this->level, $this->site_data['site_url'], $this->site_data['site_fs_path'], $db_data );
		\EE\Utils\delem_log( 'site cleanup end' );
		\EE::log( 'Report bugs here: https://github.com/EasyEngine/site-type-php' );
		exit( 1 );
	}

	/**
	 * Roll back on interrupt.
	 */
	protected function rollback() {
		\EE::warning( 'Exiting gracefully after rolling back. This may take some time.' );
		if ( $this->level > 0 ) {
			$db_data = ( empty( $this->site_data['db_host'] ) || 'db' === $this->site_data['db_host'] ) ? [] : [
				'db_host' => $this->site_data['db_host'],
				'db_user' => $this->site_data['db_user'],
				'db_name' => $this->site_data['db_name'],
			];
			$this->delete_site( $this->level, $this->site_data['site_url'], $this->site_data['site_fs_path'], $db_data );
		}
		\EE::success( 'Rollback complete. Exiting now.' );
		exit( 1 );
	}

}
