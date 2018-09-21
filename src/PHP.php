<?php

declare( ticks=1 );

namespace EE\Site\Type;

use EE\Model\Site;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;

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
	 * @var array $site_data Associative array containing essential site related information.
	 */
	private $site_data;

	/**
	 * @var string $cache_type Type of caching being used.
	 */
	private $cache_type;

	/**
	 * @var object $docker Object to access `\EE::docker()` functions.
	 */
	private $docker;

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

	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

	public function __construct() {

		parent::__construct();
		$this->level  = 0;
		$this->docker = \EE::docker();
		$this->logger = \EE::get_file_logger()->withName( 'site_php_command' );
		$this->fs     = new Filesystem();

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
	 * [--dbname=<dbname>]
	 * : Set the database name.
	 * ---
	 * default: php
	 * ---
	 *
	 * [--dbuser=<dbuser>]
	 * : Set the database user.
	 *
	 * [--dbpass=<dbpass>]
	 * : Set the database password.
	 *
	 * [--dbhost=<dbhost>]
	 * : Set the database host. Pass value only when remote dbhost is required.
	 * ---
	 * default: db
	 * ---
	 *
	 * [--skip-check]
	 * : If set, the database connection is not checked.
	 *
	 * [--skip-status-check]
	 * : Skips site status check.
	 *
	 * [--ssl=<value>]
	 * : Enables ssl on site.
	 *
	 * [--wildcard]
	 * : Gets wildcard SSL .
	 *
	 * [--force]
	 * : Resets the remote database if it is not empty.
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
	 *     # Create php site with remote database
	 *     $ ee site create example.com --type=php --with-db --dbhost=localhost --dbuser=username --dbpass=password
	 *
	 */
	public function create( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site create start' );
		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? array( 'NULL' ) : $assoc_args );
		$this->site_data['site_url'] = strtolower( \EE\Utils\remove_trailing_slash( $args[0] ) );

		if ( Site::find( $this->site_data['site_url'] ) ) {
			\EE::error( sprintf( "Site %1\$s already exists. If you want to re-create it please delete the older one using:\n`ee site delete %1\$s`", $this->site_data['site_url'] ) );
		}

		$this->cache_type                     = \EE\Utils\get_flag_value( $assoc_args, 'cache' );
		$this->site_data['site_ssl']          = \EE\Utils\get_flag_value( $assoc_args, 'ssl' );
		$this->site_data['site_ssl_wildcard'] = \EE\Utils\get_flag_value( $assoc_args, 'wildcard' );
		$this->site_data['app_sub_type']      = 'php';

		if ( ! empty( $assoc_args['with-db'] ) ) {
			$this->site_data['app_sub_type']     = 'mysql';
			$this->site_data['db_name']          = str_replace( [ '.', '-' ], '_', $this->site_data['site_url'] );
			$this->site_data['db_host']          = \EE\Utils\get_flag_value( $assoc_args, 'dbhost', 'db' );
			$this->site_data['db_port']          = '3306';
			$this->site_data['db_user']          = \EE\Utils\get_flag_value( $assoc_args, 'dbuser', $this->create_site_db_user( $this->site_data['site_url'] ) );
			$this->site_data['db_password']      = \EE\Utils\get_flag_value( $assoc_args, 'dbpass', \EE\Utils\random_password() );
			$this->site_data['db_root_password'] = \EE\Utils\random_password();

			// If user wants to connect to remote database.
			if ( 'db' !== $this->site_data['db_host'] ) {
				if ( ! isset( $assoc_args['dbuser'] ) || ! isset( $assoc_args['dbpass'] ) ) {
					\EE::error( '`--dbuser` and `--dbpass` are required for remote db host.' );
				}
				$arg_host_port              = explode( ':', $this->site_data['db_host'] );
				$this->site_data['db_host'] = $arg_host_port[0];
				$this->site_data['db_port'] = empty( $arg_host_port[1] ) ? '3306' : $arg_host_port[1];
			}
		}
		$this->site_data['app_admin_email'] = \EE\Utils\get_flag_value( $assoc_args, 'admin_email', strtolower( 'admin@' . $this->site_data['site_url'] ) );
		$this->skip_status_check            = \EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );
		$this->force                        = \EE\Utils\get_flag_value( $assoc_args, 'force' );

		\EE\Site\Utils\init_checks();

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
	 * ## EXAMPLES
	 *
	 *     # Display site info
	 *     $ ee site info example.com
	 *
	 */
	public function info( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site info start' );
		if ( ! isset( $this->site_data['site_url'] ) ) {
			$args             = auto_site_name( $args, 'php', __FUNCTION__ );
			$this->site_data  = get_site_info( $args, false );
			$this->cache_type = $this->site_data['cache_nginx_fullpage'];
		}
		$ssl    = $this->site_data['site_ssl'] ? 'Enabled' : 'Not Enabled';
		$prefix = ( $this->site_data['site_ssl'] ) ? 'https://' : 'http://';
		$info   = [ [ 'Site', $prefix . $this->site_data['site_url'] ] ];
		if ( ! empty( $this->site_data['admin_tools'] ) ) {
			$info[] = [ 'Access admin-tools', $prefix . $this->site_data['site_url'] . '/ee-admin/' ];
		}

		if ( 'mysql' === $this->site_data['app_sub_type'] ) {
			$info[] = [ 'DB Root Password', $this->site_data['db_root_password'] ];
			$info[] = [ 'DB Name', $this->site_data['db_name'] ];
			$info[] = [ 'DB User', $this->site_data['db_user'] ];
			$info[] = [ 'DB Password', $this->site_data['db_password'] ];
		}

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
		$site_docker_yml         = $this->site_data['site_fs_path'] . '/docker-compose.yml';
		$site_conf_env           = $this->site_data['site_fs_path'] . '/.env';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/main.conf';
		$site_php_ini            = $site_conf_dir . '/php-fpm/php.ini';
		$site_src_dir            = $this->site_data['site_fs_path'] . '/app/src';
		$server_name             = $this->site_data['site_url'];
		$custom_conf_dest        = $site_conf_dir . '/nginx/custom/user.conf';
		$custom_conf_source      = SITE_PHP_TEMPLATE_ROOT . '/config/nginx/user.conf.mustache';
		$process_user            = posix_getpwuid( posix_geteuid() );

		\EE::log( 'Creating PHP site ' . $this->site_data['site_url'] );
		\EE::log( 'Copying configuration files.' );

		$filter   = [];
		$filter[] = $this->cache_type ? 'redis' : 'none';

		$env_data = [
			'virtual_host' => $this->site_data['site_url'],
			'user_id'      => $process_user['uid'],
			'group_id'     => $process_user['gid'],
		];

		if ( 'mysql' === $this->site_data['app_sub_type'] ) {
			$filter[] = $this->site_data['db_host'];
			$local    = ( 'db' === $this->site_data['db_host'] ) ? true : false;
			$db_host  = $local ? $this->site_data['db_host'] : $this->site_data['db_host'] . ':' . $this->site_data['db_port'];

			$env_data['local']         = $local;
			$env_data['root_password'] = $this->site_data['db_root_password'];
			$env_data['database_name'] = $this->site_data['db_name'];
			$env_data['database_user'] = $this->site_data['db_user'];
			$env_data['user_password'] = $this->site_data['db_password'];
		}

		$site_docker            = new Site_PHP_Docker();
		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter );
		$default_conf_content   = $this->generate_default_conf( $this->cache_type, $server_name );

		$php_ini_data = [
			'admin_email' => $this->site_data['app_admin_email'],
		];

		$env_content     = \EE\Utils\mustache_render( SITE_PHP_TEMPLATE_ROOT . '/config/.env.mustache', $env_data );
		$php_ini_content = \EE\Utils\mustache_render( SITE_PHP_TEMPLATE_ROOT . '/config/php-fpm/php.ini.mustache', $php_ini_data );

		try {
			$this->fs->dumpFile( $site_docker_yml, $docker_compose_content );
			$this->fs->dumpFile( $site_conf_env, $env_content );
			$this->fs->dumpFile( $site_nginx_default_conf, $default_conf_content );
			$this->fs->copy( $custom_conf_source, $custom_conf_dest );
			$this->fs->dumpFile( $site_php_ini, $php_ini_content );

			\EE\Site\Utils\set_postfix_files( $this->site_data['site_url'], $site_conf_dir );

			$index_data = [
				'version'       => 'v' . EE_VERSION,
				'site_src_root' => $this->site_data['site_fs_path'] . '/app/src',
			];
			$index_html = \EE\Utils\mustache_render( SITE_PHP_TEMPLATE_ROOT . '/index.php.mustache', $index_data );
			$this->fs->dumpFile( $site_src_dir . '/index.php', $index_html );

			\EE::success( 'Configuration files copied.' );
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}
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
		$default_conf_data['include_php_conf']   = ! $cache_type;
		$default_conf_data['include_redis_conf'] = $cache_type;

		return \EE\Utils\mustache_render( SITE_PHP_TEMPLATE_ROOT . '/config/nginx/main.conf.mustache', $default_conf_data );
	}

	private function maybe_verify_remote_db_connection() {

		if ( 'db' === $this->site_data['db_host'] ) {
			return;
		}

		// Docker needs special handling if we want to connect to host machine.
		// The since we're inside the container and we want to access host machine,
		// we would need to replace localhost with default gateway.
		if ( '127.0.0.1' === $this->site_data['db_host'] || 'localhost' === $this->site_data['db_host'] ) {
			$launch = \EE::exec( sprintf( "docker network inspect %s --format='{{ (index .IPAM.Config 0).Gateway }}'", $this->site_data['site_url'] ), false, true );

			if ( ! $launch->return_code ) {
				$this->site_data['db_host'] = trim( $launch->stdout, "\n" );
			} else {
				throw new \Exception( 'There was a problem inspecting network. Please check the logs' );
			}
		}
		\EE::log( 'Verifying connection to remote database' );

		if ( ! \EE::exec( sprintf( "docker run -it --rm --network='%s' mysql sh -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='EXIT'\"", $this->site_data['site_url'], $this->site_data['db_host'], $this->site_data['db_port'], $this->site_data['db_user'], $this->site_data['db_password'] ) ) ) {
			throw new \Exception( 'Unable to connect to remote db' );
		}

		\EE::success( 'Connection to remote db verified' );
	}

	/**
	 * Function to create the site.
	 */
	private function create_site( $assoc_args ) {

		$this->site_data['site_fs_path'] = WEBROOT . $this->site_data['site_url'];
		$this->level                     = 1;
		try {
			\EE\Site\Utils\create_site_root( $this->site_data['site_fs_path'], $this->site_data['site_url'] );
			$this->level = 2;

			$containers = [ 'nginx', 'postfix' ];

			if ( ! empty( $assoc_args['with-db'] ) && 'db' === $this->site_data['db_host'] ) {
				$this->maybe_verify_remote_db_connection();
				$containers[] = 'db';
			}
			$this->level = 3;
			$this->configure_site_files();

			\EE\Site\Utils\start_site_containers( $this->site_data['site_fs_path'], $containers );
			\EE\Site\Utils\configure_postfix( $this->site_data['site_url'], $this->site_data['site_fs_path'] );

			\EE\Site\Utils\create_etc_hosts_entry( $this->site_data['site_url'] );
			if ( ! $this->skip_status_check ) {
				$this->level = 4;
				\EE\Site\Utils\site_status_check( $this->site_data['site_url'] );
			}

			\EE\Site\Utils\add_site_redirects( $this->site_data['site_url'], false, 'inherit' === $this->site_data['site_ssl'] );
			\EE\Site\Utils\reload_global_nginx_proxy();

			if ( $this->site_data['site_ssl'] ) {
				$wildcard = $this->site_data['site_ssl_wildcard'];
				\EE::debug( 'Wildcard in site php command: ' . $this->site_data['site_ssl_wildcard'] );
				$this->init_ssl( $this->site_data['site_url'], $this->site_data['site_fs_path'], $this->site_data['site_ssl'], $wildcard );

				\EE\Site\Utils\add_site_redirects( $this->site_data['site_url'], true, 'inherit' === $this->site_data['site_ssl'] );
				\EE\Site\Utils\reload_global_nginx_proxy();
			}
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

		if ( $this->site_data['site_ssl'] ) {
			$ssl = 'letsencrypt';
		}

		$data = [
			'site_url'             => $this->site_data['site_url'],
			'site_type'            => $this->site_data['site_type'],
			'app_admin_email'      => $this->site_data['app_admin_email'],
			'cache_nginx_browser'  => (int) $this->cache_type,
			'cache_nginx_fullpage' => (int) $this->cache_type,
			'cache_mysql_query'    => (int) $this->cache_type,
			'site_fs_path'         => $this->site_data['site_fs_path'],
			'site_ssl'             => $ssl,
			'site_ssl_wildcard'    => $this->site_data['site_ssl_wildcard'] ? 1 : 0,
			'php_version'          => '7.2',
			'created_on'           => date( 'Y-m-d H:i:s', time() ),
			'app_sub_type'         => $this->site_data['app_sub_type'],
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

		if ( 'mysql' === $this->site_data['app_sub_type'] ) {
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
		$reload_commands['php'] = 'kill -USR2 1';
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
		$this->delete_site( $this->level, $this->site_data['site_url'], $this->site_data['site_fs_path'] );
		\EE\Utils\delem_log( 'site cleanup end' );
		exit;
	}

	/**
	 * Roll back on interrupt.
	 */
	protected function rollback() {
		\EE::warning( 'Exiting gracefully after rolling back. This may take some time.' );
		if ( $this->level > 0 ) {
			$this->delete_site( $this->level, $this->site_data['site_url'], $this->site_data['site_fs_path'] );
		}
		\EE::success( 'Rollback complete. Exiting now.' );
		exit;
	}

}
