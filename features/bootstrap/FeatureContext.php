<?php
/**
 * Behat tests.
 *
 * @package ee-cli
 */

/* Start: Loading required files to enable EE::launch() in tests. */
define( 'EE_ROOT', __DIR__ . '/../..' );

include_once( EE_ROOT . '/php/class-ee.php' );
include_once( EE_ROOT . '/php/EE/Runner.php' );
include_once( EE_ROOT . '/php/utils.php' );

defined( 'EE' ) || define( 'EE', true );
defined( 'EE_VERSION' ) || define( 'EE_VERSION', trim( file_get_contents( EE_ROOT . '/VERSION' ) ) );

// php/init-ee.php may already have defined it (through the includes above).
if ( ! defined( 'EE_ROOT_DIR' ) ) {
	$root_alias = '/opt';
	if ( 'Darwin' === php_uname( 's' ) ) {
		$sys_name   = posix_getpwuid(posix_geteuid())['name'];
		if ( ! empty( $sys_name ) ) {
			$root_alias = '/Users/' . $sys_name;
		}
	}
	define( 'EE_ROOT_DIR', $root_alias . '/easyengine' );
}

require_once EE_ROOT . '/php/bootstrap.php';

if ( ! class_exists( 'EE\Runner' ) ) {
	require_once EE_ROOT . '/php/EE/Runner.php';
}

if ( ! class_exists( 'EE\Configurator' ) ) {
	require_once EE_ROOT . '/php/EE/Configurator.php';
}

$logger_dir = EE_ROOT . '/php/EE/Loggers';
$iterator   = new \DirectoryIterator( $logger_dir );

// Make sure the base class is declared first.
include_once "$logger_dir/Base.php";

foreach ( $iterator as $filename ) {
	if ( '.php' !== pathinfo( $filename, PATHINFO_EXTENSION ) ) {
		continue;
	}

	include_once "$logger_dir/$filename";
}
$runner = \EE::get_runner();
$runner->init_logger();
$runner->init_config();
/* End. Loading required files to enable EE::launch() in tests. */

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Behat\Testwork\Hook\Scope\AfterSuiteScope;

use Behat\Gherkin\Node\PyStringNode,
	Behat\Gherkin\Node\TableNode;

define( 'EE_SITE_ROOT', EE_ROOT_DIR . '/sites' );

class FeatureContext implements Context
{
	/**
	 * Sites owned by this suite: `bh-site-type-php-<n>.<tld>` and their subdomains. Cleanup only ever touches these.
	 */
	const SITE_PATTERN = '/(^|\.)bh-site-type-php-/';

	/**
	 * Global-db databases and users created by this suite (explicit `bh_stp_*` names, or derived from the site name).
	 */
	const DB_NAME_LIKE = [ 'bh\\_stp\\_%', 'bh\\_site\\_type\\_php\\_%' ];
	const DB_USER_LIKE = [ 'bh\\_stp\\_%', 'bh-site-type-php-%' ];

	const TMP_DIR      = '/var/tmp/bh-site-type-php';
	const HOSTS_MARKER = '# behat:site-type-php';

	const GLOBAL_DB_CONTAINER    = 'services_global-db_1';
	const GLOBAL_REDIS_CONTAINER = 'services_global-redis_1';
	const PROXY_CONTAINER        = 'services_global-nginx-proxy_1';

	public $command;
	public $commands;
	public $webroot_path;
	public $ee_path;

	/** @var array|null Last HTTP response: code, headers, body, error. */
	private $response;

	/** @var array Container start times noted by `I note the start time of ...`. */
	private $start_times = [];

	/**
	 * Initializes context.
	 */
	public function __construct()
	{
		$this->commands = [];
		$this->ee_path = getcwd();
	}

	/**
	 * @Given ee phar is generated
	 */
	public function eePharIsPresent()
	{
		// Checks if phar already exists, replaces it
		if(file_exists('ee-old.phar')) {
			// Below exec call is required as currenly `ee cli update` is ran with root
			// which updates ee.phar with root privileges.
			exec("sudo rm ee.phar");
			copy('ee-old.phar','ee.phar');
			return 0;
		}
		exec("php -dphar.readonly=0 utils/make-phar.php ee.phar", $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception("Unable to generate phar" . $return_status);
		}

		// Cache generaed phar as it is expensive to generate one
		copy('ee.phar','ee-old.phar');
	}

	/**
	 * @Given :command is installed
	 */
	public function isInstalled($command)
	{
		exec("type " . $command, $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception($command . " is not installed! Exit code is:" . $return_status);
		}
	}

	/**
	 * Two anchored patterns: the old unanchored `/I run '(.*)'|"(.*)"/` matched every step containing a double-quoted string.
	 *
	 * @When /^I run '(.*)'$/
	 * @When /^I run "(.*)"$/
	 */
	public function iRun($command)
	{
		$this->commands[] = self::run( $command );
	}

	/**
	 * @When I try :command
	 */
	public function iTry( $command )
	{
		$this->commands[] = self::run( $command );
	}

	/**
	 * @Then return value should be 0
	 */
	public function returnValueShouldBe0()
	{
		$this->assertReturnCode( 0 );
	}

	/**
	 * @Then return value of command :index should be 0
	 */
	public function returnValueOfCommandShouldBe0($index)
	{
		$result = $this->commandAt( $index );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Return code of command $index is {$result->return_code}, not 0.\n" . $this->describe( $result ) );
		}
	}

	/**
	 * @Then After delay of :time seconds
	 */
	public function afterDelayOfSeconds( $time )
	{
		sleep( $time );
	}

	/**
	 * @Then /^(STDOUT|STDERR) should return exactly$/
	 */
	public function stdoutShouldReturnExactly($output_stream, PyStringNode $expected_output)
	{
		$command_output = $this->streamOf( $this->lastCommand(), $output_stream );

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) of command (\d+) should return exactly$/
	 */
	public function stdoutOfCommandShouldReturnExactly($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $this->streamOf( $this->commandAt( $index ), $output_stream );

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should return something like$/
	 */
	public function stdoutShouldReturnSomethingLike($output_stream, PyStringNode $expected_output)
	{
		$command_output = $this->streamOf( $this->lastCommand(), $output_stream );

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) of command (\d+) should return something like$/
	 */
	public function stdoutOfCommandShouldReturnSomethingLike($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $this->streamOf( $this->commandAt( $index ), $output_stream );

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then The :site db entry should be removed
	 */
	public function theDbEntryShouldBeRemoved($site)
	{
		if ( null !== $this->siteRow( $site ) ) {
			throw new Exception("$site db entry not been removed!");
		}
	}

	/**
	 * @Then The :site webroot should be removed
	 */
	public function theWebrootShouldBeRemoved($site)
	{
		if ( file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been removed!");
		}
	}

	/**
	 * Looks containers up by label: compose v2 names them `<project>-<service>-1`, not `<site>_<service>_1`.
	 *
	 * @Then Following containers of site :site should be removed:
	 */
	public function followingContainersOfSiteShouldBeRemoved($site, TableNode $table)
	{
		foreach ( $table->getHash() as $container ) {
			$id = $this->serviceContainerId( $site, $container['container'], false );
			if ( '' !== $id ) {
				throw new Exception( "The {$container['container']} container of $site ($id) has not been removed!" );
			}
		}
	}

	/**
	 * @Then The site :site should have webroot
	 */
	public function theSiteShouldHaveWebroot($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been created!");
		}
	}

	/**
	 * @Then The site :site should have index file
	 */
	public function theSiteShouldHaveIndexFile( $site )
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site . "/app/htdocs/index.php" ) ) {
			throw new Exception( "PHP site data not found!" );
		}
	}

	/**
	 * @Then Request on :site should contain following headers:
	 */
	public function requestOnShouldContainFollowingHeaders($site, TableNode $table)
	{
		$url = 'http://' . $site;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with header :header should contain following headers:
	 */
	public function requestOnWithHeaderShouldContainFollowingHeaders($host, $header, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [ $header ]);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with resolve option :resolve should contain following headers:
	 */
	public function requestOnWithResolveOptionShouldContainFollowingHeaders($host, $resolve, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RESOLVE, [ $resolve ]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$headers = curl_exec($ch);
		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * The old version threw when the count matched, so it passed for any other count.
	 *
	 * @Then There should be :expected_running_containers containers with labels
	 */
	public function thereShouldBeContainersWithLabel($expected_running_containers, PyStringNode $pyStringNode)
	{
		$filters = '';
		foreach ( $pyStringNode->getStrings() as $label ) {
			$filters .= ' -f ' . escapeshellarg( 'label=' . trim( $label ) );
		}

		$result             = self::run( "docker ps -q $filters" );
		$running_containers = count( array_filter( explode( "\n", trim( $result->stdout ) ) ) );

		if ( (int) $expected_running_containers !== $running_containers ) {
			throw new Exception("Expected $expected_running_containers running containers. Found: $running_containers");
		}
	}

	/**
	 * No `-it`: without a TTY `docker exec -it` fails, and under the harness's nested ptys it can hang.
	 *
	 * @Then Check global redis cache for :site
	 */
	public function checkGlobalRedisCacheOfSite($site)
	{
		$key = 'behat-' . $site;
		$this->globalRedis( sprintf( 'set %s rocks', escapeshellarg( $key ) ) );
		$output = trim( $this->globalRedis( sprintf( 'get %s', escapeshellarg( $key ) ) )->stdout );
		$this->globalRedis( sprintf( 'del %s', escapeshellarg( $key ) ) );
		if ( 'rocks' !== $output ) {
			throw new Exception("Global redis not working for $site site. Getting '$output' instead of 'rocks'");
		}
	}

	/**
	 * @Then Check local redis cache for :site
	 */
	public function checkLocalRedisCacheOfSite($site)
	{
		$this->execInService( $site, 'redis', "redis-cli set easyengine rocks" );
		$output = trim( $this->execInService( $site, 'redis', "redis-cli get easyengine" )->stdout );
		$this->execInService( $site, 'redis', "redis-cli del easyengine" );
		if ( 'rocks' !== $output ) {
			throw new Exception("Local redis not working for $site site. Getting '$output' instead of 'rocks'.");
		}
	}

	/**
	 * Uses the mysql CLI of the php image; on php 8.2+ it fails against global-db, whose MariaDB doesn't offer the TLS that client demands.
	 *
	 * @Then Check local mysql database connection for :site
	 */
	public function checkLocalDbConnection(string $site)
	{
		$this->checkMysqlConnection( $site, 'db' );
	}

	/**
	 * @Then Check global mysql database connection for :site
	 */
	public function checkGlobalDbConnection(string $site)
	{
		$this->checkMysqlConnection( $site, 'global-db' );
	}

	/**
	 * Checks the site's DB credentials with the mysql CLI inside its php container.
	 *
	 * The old version passed whenever `ee shell` printed nothing to stdout, even on failure.
	 *
	 * @param string $site
	 * @param string $db_type
	 *
	 * @return void
	 */
	public function checkMysqlConnection(string $site, string $db_type)
	{
		$site_info = $this->siteRow( $site );

		if ( empty( $site_info['db_user'] ) || empty( $site_info['db_password'] )  ) {
			throw new Exception( 'Database credentials not found.' );
		}

		$result = $this->execInService(
			$site,
			'php',
			sprintf( 'mysql -h%s -u%s -p%s -e exit', escapeshellarg( $db_type ), escapeshellarg( $site_info['db_user'] ), escapeshellarg( $site_info['db_password'] ) )
		);

		if ( 0 !== $result->return_code ) {
			throw new Exception( "mysql CLI in the php container of $site could not connect to $db_type.\n" . $this->describe( $result ) );
		}
	}

	/**
	 * @AfterScenario
	 */
	public function cleanupScenario(AfterScenarioScope $scope)
	{
		$this->commands    = [];
		$this->response    = null;
		$this->start_times = [];
		chdir($this->ee_path);

		// Marker file the shell-syntax site name payload may create in the working directory.
		if ( file_exists( EE_ROOT . '/bhstpsec1' ) ) {
			unlink( EE_ROOT . '/bhstpsec1' );
		}
	}

	/**
	 * @BeforeSuite
	 */
	public static function prepareSuite( BeforeSuiteScope $scope )
	{
		// Volume prefixes drop "." and "-" and default db names map them to "_", and the create rollback drops
		// colliding databases and users. Refuse to run next to a foreign site whose volumes, db or db user could collide.
		foreach ( self::sqliteRows( 'SELECT site_url, db_name, db_user FROM sites' ) as $row ) {
			if ( 1 === preg_match( self::SITE_PATTERN, $row['site_url'] ) ) {
				continue;
			}
			$collides = 0 === strpos( str_replace( [ '.', '-' ], '', $row['site_url'] ), 'bhsitetypephp' )
				|| 0 === strpos( str_replace( [ '.', '-' ], '_', $row['site_url'] ), 'bh_site_type_php_' )
				|| 1 === preg_match( '/^(bh_stp_|bh_site_type_php_)/', (string) $row['db_name'] )
				|| 1 === preg_match( '/^(bh_stp_|bh-site-type-php-)/', (string) $row['db_user'] );
			if ( $collides ) {
				throw new Exception( "Refusing to run: the volumes or database of site {$row['site_url']} could collide with this suite's names." );
			}
		}
		self::cleanupTestState();
		if ( ! is_dir( self::TMP_DIR ) ) {
			mkdir( self::TMP_DIR, 0700, true );
		}
	}

	/**
	 * On CI, EE's hardcoded Let's Encrypt host must point at Pebble or a blackhole (.github/behat/pebble-setup.sh), never the real service.
	 *
	 * @BeforeSuite
	 */
	public static function refuseRealAcmeOnCi()
	{
		if ( getenv( 'CI' ) && ! self::acme_host_is_local() ) {
			throw new Exception( 'acme-v02.api.letsencrypt.org resolves to ' . gethostbyname( 'acme-v02.api.letsencrypt.org' ) . ' on CI; run `.github/behat/pebble-setup.sh setup` (le lanes) or `block` (every other lane) first.' );
		}
	}

	/**
	 * @le issues certificates: only against Pebble, or on a real test host with EE_BEHAT_LE_REAL=1 (ignored on CI).
	 *
	 * @BeforeScenario @le
	 */
	public function requireTestAcme()
	{
		if ( getenv( 'EE_BEHAT_LE_ISSUER' ) && self::acme_host_is_local() ) {
			return;
		}
		if ( '1' === getenv( 'EE_BEHAT_LE_REAL' ) && ! getenv( 'CI' ) ) {
			return;
		}
		throw new Exception( '@le needs Pebble (.github/behat/pebble-setup.sh setup), or EE_BEHAT_LE_REAL=1 on a host with real DNS for *.ee4-test.rt.gw.' );
	}

	/**
	 * True when the LE host resolves to a private, loopback or TEST-NET address (Pebble's front proxy or the blackhole).
	 */
	private static function acme_host_is_local()
	{
		$ip = gethostbyname( 'acme-v02.api.letsencrypt.org' );

		return (bool) preg_match( '/^(10\.|127\.|192\.168\.|192\.0\.2\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip );
	}

	/**
	 * Deletes only this suite's sites; never "all sites" like the old context did.
	 *
	 * @AfterFeature
	 */
	public static function cleanup(AfterFeatureScope $scope)
	{
		self::cleanupTestState();

		if(file_exists('ee.phar')) {
			unlink('ee.phar');
		}
		if(file_exists('ee-old.phar')) {
			unlink('ee-old.phar');
		}
	}

	/**
	 * @AfterSuite
	 */
	public static function finishSuite( AfterSuiteScope $scope )
	{
		self::cleanupTestState();
		if ( is_dir( self::TMP_DIR ) ) {
			self::run( 'rm -rf ' . escapeshellarg( self::TMP_DIR ) );
		}
	}

	/*
	 * =====================================================================================
	 * Shared steps (added for site-type-php coverage). Generic on purpose so other packages
	 * can reuse them. All assertions act on the LAST command / response of the scenario.
	 * =====================================================================================
	 */

	/**
	 * Runs a command with `bin/ee` resolved to the absolute path, from another working directory.
	 *
	 * @When I change to the directory :dir and run :command
	 */
	public function iChangeToTheDirectoryAndRun( $dir, $command )
	{
		$ee = realpath( EE_ROOT ) . '/bin/ee';
		$command = preg_replace( '#^bin/ee\b#', escapeshellarg( $ee ), $command );
		$this->commands[] = self::run( $command, $dir );
	}

	/**
	 * @When I execute :command in the :service service of site :site
	 */
	public function iExecuteInTheServiceOfSite( $command, $service, $site )
	{
		$this->commands[] = $this->execInService( $site, $service, $command );
	}

	/**
	 * @When I wait until the command :command succeeds within :seconds seconds
	 */
	public function iWaitUntilTheCommandSucceeds( $command, $seconds )
	{
		$deadline = time() + (int) $seconds;
		do {
			$result = self::run( $command );
			if ( 0 === $result->return_code ) {
				return;
			}
			sleep( 2 );
		} while ( time() < $deadline );

		throw new Exception( "`$command` did not succeed within $seconds seconds.\n" . $this->describe( $result ) );
	}

	/**
	 * @Then the command should succeed
	 */
	public function theCommandShouldSucceed()
	{
		$this->assertReturnCode( 0 );
	}

	/**
	 * @Then the command should fail
	 */
	public function theCommandShouldFail()
	{
		$result = $this->lastCommand();
		if ( 0 === $result->return_code ) {
			throw new Exception( "Expected a non-zero return code.\n" . $this->describe( $result ) );
		}
	}

	/**
	 * @Then the return code should be :code
	 */
	public function theReturnCodeShouldBe( $code )
	{
		$this->assertReturnCode( (int) $code );
	}

	/**
	 * @Then /^(STDOUT|STDERR) should( not|) contain:$/
	 */
	public function streamShouldContainBlock( $stream, $not, PyStringNode $text )
	{
		$this->assertStreamContains( $stream, $text->getRaw(), '' !== $not );
	}

	/**
	 * @Then /^(STDOUT|STDERR) should( not|) contain "([^"]*)"$/
	 */
	public function streamShouldContainText( $stream, $not, $text )
	{
		$this->assertStreamContains( $stream, $text, '' !== $not );
	}

	/**
	 * @Then /^(STDOUT|STDERR) should match:$/
	 */
	public function streamShouldMatch( $stream, PyStringNode $regex )
	{
		$output = $this->streamOf( $this->lastCommand(), $stream );
		if ( 1 !== preg_match( trim( $regex->getRaw() ), $output ) ) {
			throw new Exception( "$stream does not match {$regex->getRaw()}.\n" . $this->describe( $this->lastCommand() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should be empty$/
	 */
	public function streamShouldBeEmpty( $stream )
	{
		if ( '' !== trim( $this->streamOf( $this->lastCommand(), $stream ) ) ) {
			throw new Exception( "$stream is not empty.\n" . $this->describe( $this->lastCommand() ) );
		}
	}

	/**
	 * @Then STDOUT should be valid json
	 */
	public function stdoutShouldBeValidJson()
	{
		$this->decodedStdout();
	}

	/**
	 * Dot paths are supported (`a.b`). Scalars are compared as strings; null and false compare as "".
	 *
	 * @Then the JSON in STDOUT should have :key set to :value
	 */
	public function theJsonInStdoutShouldHaveKeySetTo( $key, $value )
	{
		$data   = $this->decodedStdout();
		$actual = $this->jsonPath( $data, $key );
		if ( $this->scalarToString( $actual ) !== $value ) {
			throw new Exception( sprintf( "JSON key %s is %s, expected %s.\nSTDOUT: %s", $key, var_export( $actual, true ), $value, $this->lastCommand()->stdout ) );
		}
	}

	/**
	 * @Then the JSON list in STDOUT should contain an item with :key set to :value
	 */
	public function theJsonListShouldContainItem( $key, $value )
	{
		foreach ( (array) $this->decodedStdout() as $item ) {
			if ( is_array( $item ) && array_key_exists( $key, $item ) && $this->scalarToString( $item[ $key ] ) === $value ) {
				return;
			}
		}
		throw new Exception( "No item with $key=$value in:\n" . $this->lastCommand()->stdout );
	}

	/**
	 * @Then the JSON list in STDOUT should contain an item with :key set to :value and :key2 set to :value2
	 */
	public function theJsonListShouldContainItemWithBoth( $key, $value, $key2, $value2 )
	{
		foreach ( (array) $this->decodedStdout() as $item ) {
			if ( is_array( $item ) && array_key_exists( $key, $item ) && array_key_exists( $key2, $item )
				&& $this->scalarToString( $item[ $key ] ) === $value && $this->scalarToString( $item[ $key2 ] ) === $value2 ) {
				return;
			}
		}
		throw new Exception( "No item with $key=$value and $key2=$value2 in:\n" . $this->lastCommand()->stdout );
	}

	/**
	 * @Then the JSON list in STDOUT should not contain an item with :key set to :value
	 */
	public function theJsonListShouldNotContainItem( $key, $value )
	{
		foreach ( (array) $this->decodedStdout() as $item ) {
			if ( is_array( $item ) && array_key_exists( $key, $item ) && $this->scalarToString( $item[ $key ] ) === $value ) {
				throw new Exception( "Unexpected item with $key=$value in:\n" . $this->lastCommand()->stdout );
			}
		}
	}

	/**
	 * @Then the site :site should exist
	 */
	public function theSiteShouldExist( $site )
	{
		if ( null === $this->siteRow( $site ) ) {
			throw new Exception( "Site $site has no row in the sites table." );
		}
	}

	/**
	 * @Then the site :site should not exist
	 */
	public function theSiteShouldNotExist( $site )
	{
		if ( null !== $this->siteRow( $site ) ) {
			throw new Exception( "Site $site still has a row in the sites table." );
		}
	}

	/**
	 * @Then /^the site "([^"]*)" should be (enabled|disabled)$/
	 */
	public function theSiteShouldBeEnabledOrDisabled( $site, $state )
	{
		$row = $this->requireSiteRow( $site );
		if ( (int) $row['site_enabled'] !== ( 'enabled' === $state ? 1 : 0 ) ) {
			throw new Exception( "Site $site has site_enabled={$row['site_enabled']}, expected it to be $state." );
		}
	}

	/**
	 * @Then /^the site "([^"]*)" should( not|) have the field "([^"]*)" set to "([^"]*)"$/
	 */
	public function theSiteShouldHaveFieldSetTo( $site, $not, $field, $value )
	{
		$row   = $this->requireSiteRow( $site, $field );
		$equal = $this->scalarToString( $row[ $field ] ) === $value;
		if ( $equal === ( '' !== $not ) ) {
			throw new Exception( sprintf( 'Site %s has %s=%s, expected %s%s.', $site, $field, var_export( $row[ $field ], true ), $not ? 'anything but ' : '', $value ) );
		}
	}

	/**
	 * @Then /^the site "([^"]*)" should have the field "([^"]*)" (containing|not containing) "([^"]*)"$/
	 */
	public function theSiteShouldHaveFieldContaining( $site, $field, $mode, $text )
	{
		$row   = $this->requireSiteRow( $site, $field );
		$found = false !== strpos( $this->scalarToString( $row[ $field ] ), $text );
		if ( $found !== ( 'containing' === $mode ) ) {
			throw new Exception( sprintf( 'Site %s has %s=%s, expected it %s %s.', $site, $field, var_export( $row[ $field ], true ), $mode, $text ) );
		}
	}

	/**
	 * @Then the site :site should have the field :field matching :regex
	 */
	public function theSiteShouldHaveFieldMatching( $site, $field, $regex )
	{
		$row = $this->requireSiteRow( $site, $field );
		if ( 1 !== preg_match( $regex, $this->scalarToString( $row[ $field ] ) ) ) {
			throw new Exception( sprintf( 'Site %s has %s=%s, which does not match %s.', $site, $field, var_export( $row[ $field ], true ), $regex ) );
		}
	}

	/**
	 * "Empty" is PHP-falsy, the way ee reads these columns: a boolean false is saved as "0" (e.g. site_ssl after --ssl=off).
	 *
	 * @Then /^the site "([^"]*)" should have an? (empty|non-empty) "([^"]*)"$/
	 */
	public function theSiteShouldHaveEmptyField( $site, $state, $field )
	{
		$row   = $this->requireSiteRow( $site, $field );
		$empty = in_array( $this->scalarToString( $row[ $field ] ), [ '', '0' ], true );
		if ( $empty !== ( 'empty' === $state ) ) {
			throw new Exception( sprintf( 'Site %s has %s=%s, expected it to be %s.', $site, $field, var_export( $row[ $field ], true ), $state ) );
		}
	}

	/**
	 * @Then /^the (file|directory) "([^"]*)" should( not|) exist$/
	 */
	public function thePathShouldExist( $type, $path, $not )
	{
		clearstatcache();
		$exists = 'file' === $type ? is_file( $path ) : is_dir( $path );
		if ( $exists === ( '' !== $not ) ) {
			throw new Exception( sprintf( 'The %s %s %s.', $type, $path, $exists ? 'exists' : 'does not exist' ) );
		}
	}

	/**
	 * @Then the path :path should be a symlink
	 */
	public function thePathShouldBeASymlink( $path )
	{
		clearstatcache();
		if ( ! is_link( $path ) ) {
			throw new Exception( "$path is not a symlink." );
		}
	}

	/**
	 * @Then /^the file "([^"]*)" should( not|) contain:$/
	 */
	public function theFileShouldContain( $path, $not, PyStringNode $text )
	{
		$this->assertFileContains( $path, $text->getRaw(), '' !== $not );
	}

	/**
	 * @Then /^the file "([^"]*)" should( not|) contain "([^"]*)"$/
	 */
	public function theFileShouldContainText( $path, $not, $text )
	{
		$this->assertFileContains( $path, $text, '' !== $not );
	}

	/**
	 * @Then the file :path should match :regex
	 */
	public function theFileShouldMatch( $path, $regex )
	{
		$content = $this->readFile( $path );
		if ( 1 !== preg_match( $regex, $content ) ) {
			throw new Exception( "$path does not match $regex. Content:\n$content" );
		}
	}

	/**
	 * @Then the files :first and :second should be identical
	 */
	public function theFilesShouldBeIdentical( $first, $second )
	{
		if ( $this->readFile( $first ) !== $this->readFile( $second ) ) {
			throw new Exception( "$first and $second differ." );
		}
	}

	/**
	 * @Given I append :text to the file :path
	 */
	public function iAppendToTheFile( $text, $path )
	{
		if ( false === file_put_contents( $path, PHP_EOL . $text . PHP_EOL, FILE_APPEND ) ) {
			throw new Exception( "Could not append to $path." );
		}
	}

	/**
	 * @Given I write :text to the file :path
	 */
	public function iWriteToTheFile( $text, $path )
	{
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0755, true );
		}
		if ( false === file_put_contents( $path, $text ) ) {
			throw new Exception( "Could not write $path." );
		}
	}

	/**
	 * @Then the docker container :name should be running
	 */
	public function theDockerContainerShouldBeRunning( $name )
	{
		$result = self::run( 'docker inspect -f {{.State.Running}} ' . escapeshellarg( $name ) );
		if ( 'true' !== trim( $result->stdout ) ) {
			throw new Exception( "Container $name is not running.\n" . $this->describe( $result ) );
		}
	}

	/**
	 * @Then /^the "([^"]*)" service of site "([^"]*)" should( not|) be running$/
	 */
	public function theServiceOfSiteShouldBeRunning( $service, $site, $not )
	{
		$running = '' !== $this->serviceContainerId( $site, $service );
		if ( $running === ( '' !== $not ) ) {
			throw new Exception( sprintf( "The %s service of %s is %s.\n%s", $service, $site, $running ? 'running' : 'not running', $this->siteContainersSummary( $site ) ) );
		}
	}

	/**
	 * @Then the :service service of site :site should not exist
	 */
	public function theServiceOfSiteShouldNotExist( $service, $site )
	{
		$id = $this->serviceContainerId( $site, $service, false );
		if ( '' !== $id ) {
			throw new Exception( "The $service container of $site still exists ($id)." );
		}
	}

	/**
	 * @Then the site :site should have :count running containers
	 */
	public function theSiteShouldHaveRunningContainers( $site, $count )
	{
		$result = self::run( 'docker ps -q -f ' . escapeshellarg( 'label=io.easyengine.site=' . $site ) );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Unable to list site containers.\n" . $this->describe( $result ) );
		}
		$ids = $this->lines( $result->stdout );
		if ( count( $ids ) !== (int) $count ) {
			throw new Exception( sprintf( "Expected %d running containers for %s, found %d.\n%s", $count, $site, count( $ids ), $this->siteContainersSummary( $site ) ) );
		}
	}

	/**
	 * @Then the site :site should have :count docker volumes
	 */
	public function theSiteShouldHaveDockerVolumes( $site, $count )
	{
		$volumes = $this->siteVolumes( $site );
		if ( count( $volumes ) !== (int) $count ) {
			throw new Exception( sprintf( "Expected %d volumes labelled for %s, found %d:\n%s", $count, $site, count( $volumes ), implode( "\n", $volumes ) ) );
		}
	}

	/**
	 * @Then /^the docker network "([^"]*)" should( not|) exist$/
	 */
	public function theDockerNetworkShouldExist( $network, $not )
	{
		$result = self::run( 'docker network ls --format ' . escapeshellarg( '{{.Name}}' ) );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Unable to list Docker networks.\n" . $this->describe( $result ) );
		}
		$exists = in_array( $network, $this->lines( $result->stdout ), true );
		if ( $exists === ( '' !== $not ) ) {
			throw new Exception( "Docker network $network " . ( $exists ? 'exists.' : 'does not exist.' ) );
		}
	}

	/**
	 * @Then the docker network :network should have the label :label
	 */
	public function theDockerNetworkShouldHaveTheLabel( $network, $label )
	{
		list( $key, $value ) = array_pad( explode( '=', $label, 2 ), 2, '' );
		$result = self::run( 'docker network inspect -f ' . escapeshellarg( '{{index .Labels "' . $key . '"}}' ) . ' ' . escapeshellarg( $network ) );
		if ( 0 !== $result->return_code || trim( $result->stdout ) !== $value ) {
			throw new Exception( "Docker network $network does not have the label $label.\n" . $this->describe( $result ) );
		}
	}

	/**
	 * @Then /^the "([^"]*)" service of site "([^"]*)" should( not|) be attached to the docker network "([^"]*)"$/
	 */
	public function theServiceShouldBeAttachedToNetwork( $service, $site, $not, $network )
	{
		$id       = $this->requireServiceContainer( $site, $service );
		$result   = self::run( 'docker inspect -f ' . escapeshellarg( '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}' ) . ' ' . $id );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Unable to inspect the $service container of $site.\n" . $this->describe( $result ) );
		}
		$networks = $this->lines( $result->stdout );
		$attached = in_array( $network, $networks, true );
		if ( $attached === ( '' !== $not ) ) {
			throw new Exception( sprintf( "The %s service of %s is %s to %s. Networks: %s", $service, $site, $attached ? 'attached' : 'not attached', $network, implode( ', ', $networks ) ) );
		}
	}

	/**
	 * @Then the :service service of site :site should use the image :image
	 */
	public function theServiceShouldUseTheImage( $service, $site, $image )
	{
		$id     = $this->requireServiceContainer( $site, $service );
		$actual = trim( self::run( 'docker inspect -f {{.Config.Image}} ' . $id )->stdout );
		if ( $actual !== $image ) {
			throw new Exception( "The $service service of $site runs $actual, expected $image." );
		}
	}

	/**
	 * Expects `<image>:<tag>` with the tag pinned in core's img-versions.json.
	 *
	 * @Then the :service service of site :site should use the pinned :image image
	 */
	public function theServiceShouldUseThePinnedImage( $service, $site, $image )
	{
		$this->theServiceShouldUseTheImage( $service, $site, $image . ':' . $this->pinnedTag( $image ) );
	}

	/**
	 * @Then the docker-compose file of site :site should use the pinned :image image
	 */
	public function theComposeFileShouldUseThePinnedImage( $site, $image )
	{
		$this->assertFileContains( EE_SITE_ROOT . "/$site/docker-compose.yml", 'image: ' . $image . ':' . $this->pinnedTag( $image ), false );
	}

	/**
	 * @Then the :service service of site :site should have the restart policy :policy
	 */
	public function theServiceShouldHaveRestartPolicy( $service, $site, $policy )
	{
		$id     = $this->requireServiceContainer( $site, $service );
		$actual = trim( self::run( 'docker inspect -f {{.HostConfig.RestartPolicy.Name}} ' . $id )->stdout );
		if ( $actual !== $policy ) {
			throw new Exception( "The $service service of $site has restart policy '$actual', expected '$policy'." );
		}
	}

	/**
	 * @Given I note the start time of the :service service of site :site
	 */
	public function iNoteTheStartTimeOfTheService( $service, $site )
	{
		$this->start_times[ "$site/$service" ] = $this->containerStartedAt( $this->requireServiceContainer( $site, $service ) );
	}

	/**
	 * @Then /^the "([^"]*)" service of site "([^"]*)" should( not|) have been restarted$/
	 */
	public function theServiceShouldHaveBeenRestarted( $service, $site, $not )
	{
		if ( ! isset( $this->start_times[ "$site/$service" ] ) ) {
			throw new Exception( "Note the start time of the $service service of $site first." );
		}
		$before    = $this->start_times[ "$site/$service" ];
		$after     = $this->containerStartedAt( $this->requireServiceContainer( $site, $service ) );
		$restarted = $before !== $after;
		if ( $restarted === ( '' !== $not ) ) {
			throw new Exception( "The $service service of $site started at $before and now $after." );
		}
	}

	/**
	 * @Then the php service of site :site should run PHP :version
	 */
	public function thePhpServiceShouldRunPhp( $site, $version )
	{
		$result = $this->execInService( $site, 'php', 'php -r \'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;\'' );
		if ( trim( $result->stdout ) !== $version ) {
			throw new Exception( "The php service of $site runs PHP " . trim( $result->stdout ) . ", expected $version.\n" . $this->describe( $result ) );
		}
	}

	/**
	 * Connects the way site code would (mysqli), with the credentials ee stored for the site.
	 *
	 * @Then /^the php service of site "([^"]*)" should( not|) be able to connect to the site database$/
	 */
	public function thePhpServiceShouldConnectToTheSiteDatabase( $site, $not )
	{
		$row    = $this->requireSiteRow( $site );
		$code   = sprintf(
			'<?php mysqli_report(MYSQLI_REPORT_OFF); $m = @new mysqli(%s, %s, %s, %s, %d); echo $m->connect_errno ? "ERR " . $m->connect_error : "OK";',
			var_export( (string) $row['db_host'], true ),
			var_export( (string) $row['db_user'], true ),
			var_export( (string) $row['db_password'], true ),
			var_export( (string) $row['db_name'], true ),
			empty( $row['db_port'] ) ? 3306 : (int) $row['db_port']
		);
		$id       = $this->requireServiceContainer( $site, 'php' );
		$deadline = time() + ( '' === $not ? 60 : 0 );
		// A fresh local MariaDB refuses connections until its first-start init is done.
		do {
			$result = self::run( sprintf( 'printf %%s %s | docker exec -i %s php', escapeshellarg( $code ), $id ) );
			$ok     = 'OK' === trim( $result->stdout );
			if ( $ok || time() >= $deadline ) {
				break;
			}
			sleep( 2 );
		} while ( true );
		if ( $ok === ( '' !== $not ) ) {
			throw new Exception( sprintf( "The php service of %s %s connect to %s@%s/%s.\n%s", $site, $ok ? 'could' : 'could not', $row['db_user'], $row['db_host'], $row['db_name'], $this->describe( $result ) ) );
		}
	}

	/**
	 * @Then /^the global database "([^"]*)" should( not|) exist$/
	 */
	public function theGlobalDatabaseShouldExist( $db, $not )
	{
		$found  = trim( self::globalDbQuery( sprintf( "SELECT schema_name FROM information_schema.schemata WHERE schema_name = '%s'", addslashes( $db ) ), true )->stdout );
		$exists = $found === $db;
		if ( $exists === ( '' !== $not ) ) {
			throw new Exception( "Global database $db " . ( $exists ? 'exists.' : 'does not exist.' ) );
		}
	}

	/**
	 * @Then /^the global database user "([^"]*)" should( not|) exist$/
	 */
	public function theGlobalDatabaseUserShouldExist( $user, $not )
	{
		$found  = trim( self::globalDbQuery( sprintf( "SELECT user FROM mysql.user WHERE user = '%s'", addslashes( $user ) ), true )->stdout );
		$exists = $found === $user;
		if ( $exists === ( '' !== $not ) ) {
			throw new Exception( "Global database user $user " . ( $exists ? 'exists.' : 'does not exist.' ) );
		}
	}

	/**
	 * @Given the global database :db with user :user identified by :password exists
	 */
	public function theGlobalDatabaseWithUserExists( $db, $user, $password )
	{
		self::assertSuiteDbNames( $db, $user );
		$sql = sprintf(
			"CREATE DATABASE IF NOT EXISTS `%1\$s`; CREATE USER IF NOT EXISTS '%2\$s'@'%%' IDENTIFIED BY '%3\$s'; GRANT ALL PRIVILEGES ON `%1\$s`.* TO '%2\$s'@'%%'; FLUSH PRIVILEGES;",
			str_replace( '`', '', $db ),
			addslashes( $user ),
			addslashes( $password )
		);
		self::globalDbQuery( $sql, true );
	}

	/**
	 * @Given the global database user :user identified by :password exists
	 */
	public function theGlobalDatabaseUserExists( $user, $password )
	{
		self::assertSuiteDbNames( null, $user );
		self::globalDbQuery( sprintf( "CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s'; FLUSH PRIVILEGES;", addslashes( $user ), addslashes( $password ) ), true );
	}

	/**
	 * @Given the global database :db contains the table :table
	 */
	public function theGlobalDatabaseContainsTheTable( $db, $table )
	{
		self::assertSuiteDbNames( $db, null );
		self::globalDbQuery( sprintf( 'CREATE TABLE IF NOT EXISTS `%s`.`%s` (id INT PRIMARY KEY)', str_replace( '`', '', $db ), str_replace( '`', '', $table ) ), true );
	}

	/**
	 * @Then /^the global database "([^"]*)" should( not|) contain the table "([^"]*)"$/
	 */
	public function theGlobalDatabaseShouldContainTheTable( $db, $not, $table )
	{
		$found  = trim( self::globalDbQuery( sprintf( "SELECT table_name FROM information_schema.tables WHERE table_schema = '%s' AND table_name = '%s'", addslashes( $db ), addslashes( $table ) ), true )->stdout );
		$exists = $found === $table;
		if ( $exists === ( '' !== $not ) ) {
			throw new Exception( "Table $db.$table " . ( $exists ? 'exists.' : 'does not exist.' ) );
		}
	}

	/**
	 * @Then /^the database "([^"]*)" should( not|) exist in the db service of site "([^"]*)"$/
	 */
	public function theDatabaseShouldExistInTheDbService( $db, $not, $site )
	{
		$query    = sprintf( 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e %s', escapeshellarg( sprintf( "SELECT schema_name FROM information_schema.schemata WHERE schema_name = '%s'", addslashes( $db ) ) ) );
		$deadline = time() + 60;
		// Until its first-start init is done, a fresh local MariaDB denies root with the configured password.
		while ( 0 !== ( $result = $this->execInService( $site, 'db', $query ) )->return_code && time() < $deadline ) {
			sleep( 2 );
		}
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not query the db service of $site.\n" . $this->describe( $result ) );
		}
		$exists = trim( $result->stdout ) === $db;
		if ( $exists === ( '' !== $not ) ) {
			throw new Exception( "Database $db " . ( $exists ? 'exists' : 'does not exist' ) . " in the db service of $site." );
		}
	}

	/**
	 * @Then /^the global redis should( not|) have keys matching "([^"]*)"$/
	 */
	public function theGlobalRedisShouldHaveKeysMatching( $not, $pattern )
	{
		$result = $this->globalRedis( '--scan --pattern ' . escapeshellarg( $pattern ) );
		$this->assertKeys( 'global redis', $result, $pattern, '' !== $not );
	}

	/**
	 * @Then /^the redis service of site "([^"]*)" should( not|) have keys matching "([^"]*)"$/
	 */
	public function theSiteRedisShouldHaveKeysMatching( $site, $not, $pattern )
	{
		$result = $this->execInService( $site, 'redis', 'redis-cli --scan --pattern ' . escapeshellarg( $pattern ) );
		$this->assertKeys( "redis service of $site", $result, $pattern, '' !== $not );
	}

	/**
	 * @When /^I send a GET request to "([^"]*)"$/
	 */
	public function iSendAGetRequestTo( $url )
	{
		$this->response = $this->httpRequest( $url );
	}

	/**
	 * @When /^I send a GET request to "([^"]*)" via "([^"]*)"$/
	 */
	public function iSendAGetRequestVia( $url, $ip )
	{
		$this->response = $this->httpRequest( $url, [ 'resolve' => $ip ] );
	}

	/**
	 * @When /^I send a GET request to "([^"]*)" with Host "([^"]*)"$/
	 */
	public function iSendAGetRequestWithHost( $url, $host )
	{
		$this->response = $this->httpRequest( $url, [ 'host' => $host ] );
	}

	/**
	 * @Then /^a GET request to "([^"]*)" should return (\d{3})$/
	 */
	public function aGetRequestShouldReturn( $url, $code )
	{
		$this->response = $this->httpRequest( $url );
		// ee returns before nginx-proxy's last `nginx -s reload` takes effect, so the first request can still hit the old config (500/502).
		$first    = $this->response['code'];
		$started  = time();
		$deadline = $started + 15;
		while ( (int) $code < 500 && ( $this->response['code'] >= 500 || 0 === $this->response['code'] ) && time() < $deadline ) {
			sleep( 1 );
			$this->response = $this->httpRequest( $url );
		}
		if ( $first !== $this->response['code'] ) {
			echo "First answer was HTTP $first; HTTP {$this->response['code']} after " . ( time() - $started ) . " s.\n";
		}
		$this->theResponseCodeShouldBe( $code );
	}

	/**
	 * @Then /^a GET request to "([^"]*)" via "([^"]*)" should( not|) return (\d{3})$/
	 */
	public function aGetRequestViaShouldReturn( $url, $ip, $not, $code )
	{
		$this->response = $this->httpRequest( $url, [ 'resolve' => $ip ] );
		if ( '' === $not ) {
			$this->theResponseCodeShouldBe( $code );
		} elseif ( $this->noHttpResponse() || (int) $code === $this->response['code'] ) {
			throw new Exception( "Expected anything but HTTP $code.\n" . $this->describeResponse() );
		}
	}

	/**
	 * @Then /^a GET request to "([^"]*)" with Host "([^"]*)" should return (\d{3})$/
	 */
	public function aGetRequestWithHostShouldReturn( $url, $host, $code )
	{
		$this->response = $this->httpRequest( $url, [ 'host' => $host ] );
		$this->theResponseCodeShouldBe( $code );
	}

	/**
	 * @Then /^a GET request to "([^"]*)" with certificate verification should return (\d{3})$/
	 */
	public function aVerifiedGetRequestShouldReturn( $url, $code )
	{
		$this->response = $this->httpRequest( $url, [ 'verify' => true ] );
		$this->theResponseCodeShouldBe( $code );
	}

	/**
	 * @When /^I wait until a GET request to "([^"]*)"(?: via "([^"]*)")? (returns|no longer returns) (\d{3}) within (\d+) seconds$/
	 */
	public function iWaitUntilAGetRequestReturns( $url, $ip, $mode, $code, $seconds )
	{
		$options  = '' === $ip ? [] : [ 'resolve' => $ip ];
		$want     = 'returns' === $mode;
		$deadline = time() + (int) $seconds;
		do {
			$this->response = $this->httpRequest( $url, $options );
			// A transport error doesn't prove "no longer N"; keep polling.
			if ( ( $want || ! $this->noHttpResponse() ) && ( (int) $code === $this->response['code'] ) === $want ) {
				return;
			}
			sleep( 2 );
		} while ( time() < $deadline );

		throw new Exception( "GET $url still did not satisfy '$mode $code' after $seconds seconds.\n" . $this->describeResponse() );
	}

	/**
	 * @Then the response code should be :code
	 */
	public function theResponseCodeShouldBe( $code )
	{
		if ( null === $this->response ) {
			throw new Exception( 'No request was sent.' );
		}
		if ( (int) $code !== $this->response['code'] ) {
			throw new Exception( "Expected HTTP $code.\n" . $this->describeResponse() );
		}
	}

	/**
	 * @Then /^the response should( not|) contain "([^"]*)"$/
	 */
	public function theResponseShouldContain( $not, $text )
	{
		if ( '' !== $not && $this->noHttpResponse() ) {
			throw new Exception( "No HTTP response to check.\n" . $this->describeResponse() );
		}
		$found = false !== strpos( $this->requireResponse()['body'], $text );
		if ( $found === ( '' !== $not ) ) {
			throw new Exception( sprintf( "The response body %s %s.\n%s", $found ? 'contains' : 'does not contain', $text, $this->describeResponse() ) );
		}
	}

	/**
	 * @Then /^the response header "([^"]*)" should( not|) contain "([^"]*)"$/
	 */
	public function theResponseHeaderShouldContain( $header, $not, $text )
	{
		if ( '' !== $not && $this->noHttpResponse() ) {
			throw new Exception( "No HTTP response to check.\n" . $this->describeResponse() );
		}
		$value = $this->responseHeader( $header );
		$found = null !== $value && false !== strpos( $value, $text );
		if ( $found === ( '' !== $not ) ) {
			throw new Exception( sprintf( "Header %s is %s.\n%s", $header, var_export( $value, true ), $this->describeResponse() ) );
		}
	}

	/**
	 * @Then /^the response should( not|) have the header "([^"]*)"$/
	 */
	public function theResponseShouldHaveTheHeader( $not, $header )
	{
		if ( '' !== $not && $this->noHttpResponse() ) {
			throw new Exception( "No HTTP response to check.\n" . $this->describeResponse() );
		}
		$present = null !== $this->responseHeader( $header );
		if ( $present === ( '' !== $not ) ) {
			throw new Exception( sprintf( "Header %s is %s.\n%s", $header, $present ? 'present' : 'missing', $this->describeResponse() ) );
		}
	}

	/**
	 * @Given a self-signed certificate for :domain exists in :dir
	 */
	public function aSelfSignedCertificateExists( $domain, $dir )
	{
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		$cmd    = sprintf(
			'openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj %s -addext %s -keyout %s -out %s',
			escapeshellarg( '/CN=' . $domain ),
			escapeshellarg( 'subjectAltName=DNS:' . $domain ),
			escapeshellarg( "$dir/$domain.key" ),
			escapeshellarg( "$dir/$domain.crt" )
		);
		$result = self::run( $cmd );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not create a certificate for $domain.\n" . $this->describe( $result ) );
		}
	}

	/**
	 * @Then the certificate served for :host should match the file :path
	 */
	public function theCertificateServedShouldMatchTheFile( $host, $path )
	{
		$served   = openssl_x509_fingerprint( $this->servedCertificate( $host ), 'sha256' );
		$expected = openssl_x509_fingerprint( $this->readFile( $path ), 'sha256' );
		if ( $served !== $expected ) {
			throw new Exception( "The certificate served for $host ($served) is not the one in $path ($expected)." );
		}
	}

	/**
	 * @Then the certificate served for :host should cover :name
	 */
	public function theCertificateServedShouldCover( $host, $name )
	{
		$info = openssl_x509_parse( $this->servedCertificate( $host ) );
		$sans = isset( $info['extensions']['subjectAltName'] ) ? $info['extensions']['subjectAltName'] : '';
		$names = array_map( function ( $entry ) {
			return trim( str_replace( 'DNS:', '', $entry ) );
		}, explode( ',', $sans ) );
		if ( ! in_array( $name, $names, true ) ) {
			throw new Exception( "The certificate served for $host covers " . implode( ', ', $names ) . ", not $name." );
		}
	}

	/**
	 * CI runs @le against Pebble and sets EE_BEHAT_LE_ISSUER to its intermediate CN.
	 *
	 * @Then the certificate served for :host should be issued by the ACME CA
	 */
	public function theCertificateServedShouldBeIssuedByTheAcmeCa( $host )
	{
		$this->theCertificateServedShouldBeIssuedBy( $host, getenv( 'EE_BEHAT_LE_ISSUER' ) ?: "Let's Encrypt" );
	}

	/**
	 * @Then the certificate served for :host should be issued by :issuer
	 */
	public function theCertificateServedShouldBeIssuedBy( $host, $issuer )
	{
		$info   = openssl_x509_parse( $this->servedCertificate( $host ) );
		$actual = isset( $info['issuer'] ) ? implode( ', ', (array) $info['issuer'] ) : '';
		if ( false === stripos( $actual, $issuer ) ) {
			throw new Exception( "The certificate served for $host is issued by $actual, not $issuer." );
		}
	}

	/**
	 * @Then /^the hosts file should( not|) have an entry for "([^"]*)"$/
	 */
	public function theHostsFileShouldHaveAnEntryFor( $not, $host )
	{
		$found = 1 === preg_match( '/^\s*[0-9a-f:.]+\s+(.*\s)?' . preg_quote( $host, '/' ) . '(\s|$)/mi', $this->readFile( '/etc/hosts' ) );
		if ( $found === ( '' !== $not ) ) {
			throw new Exception( "/etc/hosts " . ( $found ? 'has' : 'has no' ) . " entry for $host." );
		}
	}

	/**
	 * Adds a marked line that the suite cleanup removes again.
	 *
	 * @Given the hosts file maps :host to :ip
	 */
	public function theHostsFileMaps( $host, $ip )
	{
		$hosts = $this->readFile( '/etc/hosts' );
		if ( 1 !== preg_match( '/^\s*' . preg_quote( $ip, '/' ) . '\s+(.*\s)?' . preg_quote( $host, '/' ) . '(\s|$)/m', $hosts ) ) {
			file_put_contents( '/etc/hosts', rtrim( $hosts, "\n" ) . "\n$ip\t$host " . self::HOSTS_MARKER . "\n" );
		}
	}

	/**
	 * EE_BEHAT_LE_DNS_SERVER overrides the resolver (CI points the test names at Pebble's DNS).
	 *
	 * @Given the domain :domain resolves publicly
	 */
	public function theDomainResolvesPublicly( $domain )
	{
		$server = getenv( 'EE_BEHAT_LE_DNS_SERVER' ) ?: '1.1.1.1';
		$result = self::run( 'dig +short ' . escapeshellarg( $domain ) . ' ' . escapeshellarg( '@' . $server ) );
		if ( '' === trim( $result->stdout ) ) {
			throw new Exception( "$domain does not resolve publicly; Let's Encrypt scenarios need the ee4-test.rt.gw DNS records." );
		}
	}

	/**
	 * Commands run with stdin on /dev/null, so a value ee would prompt for (e.g. le-mail) must already be configured.
	 *
	 * @Given the ee config has a :key value
	 */
	public function theEeConfigHasAValue( $key )
	{
		$config = $this->readFile( EE_ROOT_DIR . '/config/config.yml' );
		if ( 1 !== preg_match( '/^' . preg_quote( $key, '/' ) . ':[ \t]*\S/m', $config ) ) {
			throw new Exception( "Set $key in " . EE_ROOT_DIR . '/config/config.yml first (ee would prompt for it).' );
		}
	}

	/**
	 * Creates the site only if it does not exist yet, so scenarios can run alone or reuse a site made earlier in the feature.
	 *
	 * @Given a php site :site exists
	 */
	public function aPhpSiteExists( $site )
	{
		$this->aPhpSiteCreatedWithFlagsExists( $site, '' );
	}

	/**
	 * @Given a php site :site created with :flags exists
	 */
	public function aPhpSiteCreatedWithFlagsExists( $site, $flags )
	{
		if ( null !== $this->siteRow( $site ) ) {
			return;
		}
		$result = self::run( sprintf( 'bin/ee site create %s --type=php %s', escapeshellarg( $site ), $flags ) );
		if ( 0 !== $result->return_code || null === $this->siteRow( $site ) ) {
			throw new Exception( "Could not create php site $site ($flags).\n" . $this->describe( $result ) );
		}
	}

	/**
	 * @Given the site :site has the alias domain :alias
	 */
	public function theSiteHasTheAliasDomain( $site, $alias )
	{
		$row = $this->requireSiteRow( $site );
		if ( in_array( $alias, explode( ',', (string) $row['alias_domains'] ), true ) ) {
			return;
		}
		$result = self::run( sprintf( 'bin/ee site update %s --add-alias-domains=%s', escapeshellarg( $site ), escapeshellarg( $alias ) ) );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not add alias $alias to $site.\n" . $this->describe( $result ) );
		}
	}

	/**
	 * @Given the site :site is disabled
	 */
	public function theSiteIsDisabled( $site )
	{
		$row = $this->requireSiteRow( $site );
		if ( 0 === (int) $row['site_enabled'] ) {
			return;
		}
		$result = self::run( 'bin/ee site disable ' . escapeshellarg( $site ) );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not disable $site.\n" . $this->describe( $result ) );
		}
	}

	/**
	 * @Given the site :site does not exist
	 */
	public function theSiteDoesNotExist( $site )
	{
		if ( null === $this->siteRow( $site ) ) {
			return;
		}
		if ( 1 !== preg_match( self::SITE_PATTERN, $site ) ) {
			throw new Exception( "Refusing to delete $site: it is not a site of this suite." );
		}
		$result = self::run( 'bin/ee site delete ' . escapeshellarg( $site ) . ' --yes' );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not delete $site.\n" . $this->describe( $result ) );
		}
	}

	/*
	 * ----- helpers -----
	 */

	/**
	 * Runs a shell command with stdin on /dev/null, so docker/compose never allocate a TTY: under the harness's
	 * nested ptys a TTY exec can hang, and without one `docker exec -it` fails fast instead.
	 *
	 * @return object command, stdout, stderr, return_code (same fields as EE's ProcessRun).
	 */
	private static function run( $command, $cwd = null )
	{
		$out  = tempnam( sys_get_temp_dir(), 'bhstp' );
		$err  = tempnam( sys_get_temp_dir(), 'bhstp' );
		$proc = proc_open( $command, [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'file', $out, 'w' ], 2 => [ 'file', $err, 'w' ] ], $pipes, $cwd );
		$code = is_resource( $proc ) ? proc_close( $proc ) : -1;
		$result = (object) [
			'command'     => $command,
			'stdout'      => (string) file_get_contents( $out ),
			'stderr'      => (string) file_get_contents( $err ),
			'return_code' => $code,
		];
		unlink( $out );
		unlink( $err );

		return $result;
	}

	private function lastCommand()
	{
		if ( empty( $this->commands ) ) {
			throw new Exception( 'No command was run in this scenario.' );
		}

		return end( $this->commands );
	}

	private function commandAt( $index )
	{
		if ( ! isset( $this->commands[ $index - 1 ] ) ) {
			throw new Exception( "Command $index was not run in this scenario." );
		}

		return $this->commands[ $index - 1 ];
	}

	private function streamOf( $result, $stream )
	{
		return 'STDOUT' === $stream ? $result->stdout : $result->stderr;
	}

	private function describe( $result )
	{
		return sprintf( "Command: %s\nReturn code: %s\nSTDOUT:\n%s\nSTDERR:\n%s", $result->command, $result->return_code, $result->stdout, $result->stderr );
	}

	private function assertReturnCode( $code )
	{
		$result = $this->lastCommand();
		if ( $code !== $result->return_code ) {
			throw new Exception( "Expected return code $code.\n" . $this->describe( $result ) );
		}
	}

	private function assertStreamContains( $stream, $text, $negate )
	{
		$result = $this->lastCommand();
		$output = str_replace( [ "\033[1;31m", "\033[0m" ], '', $this->streamOf( $result, $stream ) );
		$found  = false !== strpos( $output, $text );
		if ( $found === $negate ) {
			throw new Exception( sprintf( "%s %s:\n%s\n\n%s", $stream, $negate ? 'unexpectedly contains' : 'does not contain', $text, $this->describe( $result ) ) );
		}
	}

	private function decodedStdout()
	{
		$stdout = trim( $this->lastCommand()->stdout );
		$data   = json_decode( $stdout, true );
		if ( null === $data && 'null' !== $stdout ) {
			throw new Exception( 'STDOUT is not valid JSON (' . json_last_error_msg() . "):\n" . $stdout );
		}

		return $data;
	}

	private function jsonPath( $data, $path )
	{
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $data ) || ! array_key_exists( $part, $data ) ) {
				throw new Exception( "JSON key $path not found in:\n" . $this->lastCommand()->stdout );
			}
			$data = $data[ $part ];
		}

		return $data;
	}

	private function scalarToString( $value )
	{
		if ( null === $value || false === $value ) {
			return '';
		}
		if ( true === $value ) {
			return '1';
		}

		return (string) $value;
	}

	private function lines( $text )
	{
		return array_values( array_filter( array_map( 'trim', explode( "\n", trim( $text ) ) ), 'strlen' ) );
	}

	private function readFile( $path )
	{
		clearstatcache();
		if ( ! is_file( $path ) ) {
			throw new Exception( "File $path does not exist." );
		}

		return file_get_contents( $path );
	}

	private function assertFileContains( $path, $text, $negate )
	{
		$content = $this->readFile( $path );
		$found   = false !== strpos( $content, $text );
		if ( $found === $negate ) {
			throw new Exception( sprintf( "%s %s:\n%s\n\nContent:\n%s", $path, $negate ? 'unexpectedly contains' : 'does not contain', $text, $content ) );
		}
	}

	/**
	 * @return array|null The site's row from ee.sqlite, read-only.
	 */
	private function siteRow( $site )
	{
		$rows = self::sqliteRows( 'SELECT * FROM sites WHERE site_url = :site', [ ':site' => $site ] );

		return empty( $rows ) ? null : $rows[0];
	}

	private function requireSiteRow( $site, $field = null )
	{
		$row = $this->siteRow( $site );
		if ( null === $row ) {
			throw new Exception( "Site $site has no row in the sites table." );
		}
		if ( null !== $field && ! array_key_exists( $field, $row ) ) {
			throw new Exception( "The sites table has no column $field." );
		}

		return $row;
	}

	private static function sqliteRows( $sql, array $params = [] )
	{
		$path = EE_ROOT_DIR . '/db/ee.sqlite';
		if ( ! file_exists( $path ) ) {
			return [];
		}
		$db = new SQLite3( $path, SQLITE3_OPEN_READONLY );
		$db->busyTimeout( 5000 );
		$stmt = $db->prepare( $sql );
		foreach ( $params as $key => $value ) {
			$stmt->bindValue( $key, $value, SQLITE3_TEXT );
		}
		$result = $stmt->execute();
		$rows   = [];
		while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
			$rows[] = $row;
		}
		$db->close();

		return $rows;
	}

	private function serviceContainerId( $site, $service, $running_only = true )
	{
		$cmd = sprintf(
			'docker ps %s -q -f %s -f %s',
			$running_only ? '' : '-a',
			escapeshellarg( 'label=io.easyengine.site=' . $site ),
			escapeshellarg( 'label=com.docker.compose.service=' . $service )
		);

		$result = self::run( $cmd );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Unable to list site containers.\n" . $this->describe( $result ) );
		}

		return implode( ' ', $this->lines( $result->stdout ) );
	}

	private function requireServiceContainer( $site, $service )
	{
		$id = $this->serviceContainerId( $site, $service );
		if ( '' === $id || false !== strpos( $id, ' ' ) ) {
			throw new Exception( "Expected exactly one running $service container for $site, found '$id'.\n" . $this->siteContainersSummary( $site ) );
		}

		return $id;
	}

	private function execInService( $site, $service, $command )
	{
		$id = $this->requireServiceContainer( $site, $service );

		return self::run( sprintf( 'docker exec %s sh -c %s', $id, escapeshellarg( $command ) ) );
	}

	private function siteContainersSummary( $site )
	{
		return self::run( 'docker ps -a -f ' . escapeshellarg( 'label=io.easyengine.site=' . $site ) . ' --format ' . escapeshellarg( '{{.Names}} {{.Status}} {{.Image}}' ) )->stdout;
	}

	private function siteVolumes( $site )
	{
		$result = self::run( 'docker volume ls -q -f label=org.label-schema.vendor=EasyEngine -f ' . escapeshellarg( 'label=io.easyengine.site=' . $site ) );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Unable to list site volumes.\n" . $this->describe( $result ) );
		}

		return $this->lines( $result->stdout );
	}

	private function containerStartedAt( $id )
	{
		$result = self::run( 'docker inspect -f {{.State.StartedAt}} ' . $id );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Unable to inspect container $id.\n" . $this->describe( $result ) );
		}

		return trim( $result->stdout );
	}

	private function pinnedTag( $image )
	{
		$versions = json_decode( file_get_contents( EE_ROOT . '/img-versions.json' ), true );
		if ( empty( $versions[ $image ] ) ) {
			throw new Exception( "img-versions.json has no tag for $image." );
		}

		return $versions[ $image ];
	}

	private function globalRedis( $args )
	{
		return self::run( sprintf( 'docker exec %s redis-cli %s', self::GLOBAL_REDIS_CONTAINER, $args ) );
	}

	private function assertKeys( $where, $result, $pattern, $negate )
	{
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Could not scan the $where.\n" . $this->describe( $result ) );
		}
		$keys = $this->lines( $result->stdout );
		if ( empty( $keys ) !== $negate ) {
			throw new Exception( sprintf( "The %s has %d keys matching %s:\n%s", $where, count( $keys ), $pattern, implode( "\n", $keys ) ) );
		}
	}

	/**
	 * Setup steps write to global-db as root: keep them on the suite's own names.
	 */
	private static function assertSuiteDbNames( $db, $user )
	{
		if ( null !== $db && 1 !== preg_match( '/^(bh_stp_|bh_site_type_php_)[A-Za-z0-9_]+$/', $db ) ) {
			throw new Exception( "Refusing to touch the database $db: not a name of this suite." );
		}
		if ( null !== $user && 1 !== preg_match( '/^(bh_stp_[A-Za-z0-9_]+|bh-site-type-php-[A-Za-z0-9.-]+)$/', $user ) ) {
			throw new Exception( "Refusing to touch the database user $user: not a name of this suite." );
		}
	}

	private static function globalDbQuery( $sql, $throw = false )
	{
		$script = 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e ' . escapeshellarg( $sql );
		$result = self::run( sprintf( 'docker exec %s sh -c %s', self::GLOBAL_DB_CONTAINER, escapeshellarg( $script ) ) );
		if ( $throw && 0 !== $result->return_code ) {
			throw new Exception( "Global db query failed: $sql\n{$result->stderr}" );
		}

		return $result;
	}

	/**
	 * @param array $options resolve: IP to pin the URL's host to (keeps SNI), host: Host header, verify: check TLS.
	 */
	private function httpRequest( $url, array $options = [] )
	{
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HEADER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
		$verify = ! empty( $options['verify'] );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, $verify );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0 );
		if ( ! empty( $options['resolve'] ) ) {
			$parts = parse_url( $url );
			$port  = isset( $parts['port'] ) ? $parts['port'] : ( 'https' === $parts['scheme'] ? 443 : 80 );
			curl_setopt( $ch, CURLOPT_RESOLVE, [ "{$parts['host']}:$port:{$options['resolve']}" ] );
		}
		if ( ! empty( $options['host'] ) ) {
			curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Host: ' . $options['host'] ] );
		}
		$raw         = curl_exec( $ch );
		$code        = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$error       = curl_error( $ch );
		curl_close( $ch );
		$raw = false === $raw ? '' : $raw;

		return [
			'url'     => $url,
			'code'    => $code,
			'headers' => substr( $raw, 0, $header_size ),
			'body'    => (string) substr( $raw, $header_size ),
			'error'   => $error,
		];
	}

	private function requireResponse()
	{
		if ( null === $this->response ) {
			throw new Exception( 'No request was sent.' );
		}

		return $this->response;
	}

	private function responseHeader( $name )
	{
		foreach ( explode( "\n", $this->requireResponse()['headers'] ) as $line ) {
			$pos = strpos( $line, ':' );
			if ( false !== $pos && 0 === strcasecmp( trim( substr( $line, 0, $pos ) ), $name ) ) {
				return trim( substr( $line, $pos + 1 ) );
			}
		}

		return null;
	}

	// Negative HTTP checks pass only on a real response.
	private function noHttpResponse()
	{
		$r = $this->requireResponse();

		return 0 === $r['code'] || '' !== $r['error'];
	}

	private function describeResponse()
	{
		$r = $this->requireResponse();

		return sprintf( "GET %s -> %d %s\nHeaders:\n%s\nBody (first 500 bytes):\n%s", $r['url'], $r['code'], $r['error'], trim( $r['headers'] ), substr( $r['body'], 0, 500 ) );
	}

	/**
	 * @return string PEM of the certificate nginx-proxy serves for $host on 127.0.0.1:443 (SNI = $host).
	 */
	private function servedCertificate( $host )
	{
		$context = stream_context_create( [
			'ssl' => [
				'peer_name'         => $host,
				'SNI_enabled'       => true,
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'capture_peer_cert' => true,
			],
		] );
		$stream  = @stream_socket_client( 'ssl://127.0.0.1:443', $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context );
		if ( ! $stream ) {
			throw new Exception( "TLS connection for $host failed: $errstr" );
		}
		$params = stream_context_get_params( $stream );
		fclose( $stream );
		openssl_x509_export( $params['options']['ssl']['peer_certificate'], $pem );

		return $pem;
	}

	/**
	 * Removes everything this suite may leave behind, touching only names that match SITE_PATTERN / the bh_stp_ prefixes.
	 */
	private static function cleanupTestState()
	{
		// Every entry point: @BeforeSuite hooks run in declaration order, so prepareSuite() would clean up before the guard.
		self::refuseRealAcmeOnCi();
		$ee    = escapeshellarg( realpath( EE_ROOT ) . '/bin/ee' );
		$sites = [];
		foreach ( self::sqliteRows( 'SELECT site_url, alias_domains FROM sites' ) as $row ) {
			if ( 1 === preg_match( self::SITE_PATTERN, $row['site_url'] ) ) {
				$sites[] = $row['site_url'];
				continue;
			}
			// The sweeps below treat any suite-pattern name without its own site row as an orphan.
			foreach ( explode( ',', (string) $row['alias_domains'] ) as $alias ) {
				if ( 1 === preg_match( self::SITE_PATTERN, trim( $alias ) ) ) {
					throw new Exception( "Refusing to clean up: site {$row['site_url']} has the alias " . trim( $alias ) . ", which matches this suite's names." );
				}
			}
		}
		// Children (e.g. --ssl=inherit subdomains) first.
		usort( $sites, function ( $a, $b ) {
			return substr_count( $b, '.' ) - substr_count( $a, '.' );
		} );
		foreach ( $sites as $site ) {
			// --foreground: in a background process group the `docker exec -it` of a cache purge stops on SIGTTOU (with timeout itself) and hangs forever.
			self::run( sprintf( 'timeout --foreground -k 30 600 %s site delete %s --yes', $ee, escapeshellarg( $site ) ), realpath( EE_ROOT ) );
		}

		$remaining = array_column( self::sqliteRows( 'SELECT site_url FROM sites' ), 'site_url' );
		$orphan    = function ( $name ) use ( $remaining ) {
			return 1 === preg_match( self::SITE_PATTERN, $name ) && ! in_array( $name, $remaining, true );
		};

		// Orphaned containers, networks and volumes of sites that no longer exist.
		$containers = self::run( 'docker ps -a -f label=io.easyengine.site --format ' . escapeshellarg( '{{.ID}} {{.Label "io.easyengine.site"}}' ) )->stdout;
		foreach ( array_filter( explode( "\n", $containers ) ) as $line ) {
			list( $id, $label ) = array_pad( explode( ' ', trim( $line ), 2 ), 2, '' );
			if ( $orphan( $label ) ) {
				self::run( 'docker rm -f ' . escapeshellarg( $id ) );
			}
		}
		$networks = self::run( 'docker network ls --format {{.Name}}' )->stdout;
		foreach ( array_filter( explode( "\n", $networks ) ) as $network ) {
			if ( $orphan( trim( $network ) ) ) {
				self::run( 'docker network rm ' . escapeshellarg( trim( $network ) ) );
			}
		}
		$volumes = self::run( 'docker volume ls -f label=io.easyengine.site --format ' . escapeshellarg( '{{.Name}} {{.Label "io.easyengine.site"}}' ) )->stdout;
		foreach ( array_filter( explode( "\n", $volumes ) ) as $line ) {
			list( $name, $label ) = array_pad( explode( ' ', trim( $line ), 2 ), 2, '' );
			if ( $orphan( $label ) ) {
				self::run( 'docker volume rm ' . escapeshellarg( $name ) );
			}
		}

		// Site roots and proxy files left by failed creates.
		foreach ( (array) glob( EE_SITE_ROOT . '/*' ) as $dir ) {
			$name = basename( $dir );
			// Any name, so a dir left by the shell-syntax site name scenario's `$(...)` site name goes too.
			if ( $orphan( $name ) ) {
				self::run( 'rm -rf -- ' . escapeshellarg( $dir ) );
			}
		}
		$proxy_dir = EE_ROOT_DIR . '/services/nginx-proxy';
		$reload    = false;
		foreach ( [ 'conf.d', 'vhost.d', 'certs', 'htpasswd' ] as $sub ) {
			foreach ( (array) glob( "$proxy_dir/$sub/*bh-site-type-php-*" ) as $file ) {
				$site = preg_replace( '/(-redirect\.conf|\.conf|_location|\.crt|\.key|\.chain\.pem|\.dhparam\.pem)$/', '', basename( $file ) );
				if ( is_file( $file ) && $orphan( $site ) ) {
					unlink( $file );
					$reload = true;
				}
			}
		}
		if ( $reload ) {
			self::run( 'docker exec ' . self::PROXY_CONTAINER . ' sh -c ' . escapeshellarg( 'nginx -t && nginx -s reload' ) );
		}

		// Global-db databases and users created by the suite. Names still referenced by any remaining site row (another
		// site, or a suite site whose delete failed) are never dropped, whatever their prefix.
		if ( 0 === self::run( 'docker inspect ' . self::GLOBAL_DB_CONTAINER )->return_code ) {
			$in_use     = self::sqliteRows( "SELECT db_name, db_user FROM sites WHERE db_name != '' OR db_user != ''" );
			$used_dbs   = array_filter( array_column( $in_use, 'db_name' ), 'strlen' );
			$used_users = array_filter( array_column( $in_use, 'db_user' ), 'strlen' );
			$where_db   = implode( ' OR ', array_map( function ( $like ) {
				return "schema_name LIKE '$like'";
			}, self::DB_NAME_LIKE ) );
			$where_user = implode( ' OR ', array_map( function ( $like ) {
				return "user LIKE '$like'";
			}, self::DB_USER_LIKE ) );
			foreach ( array_filter( explode( "\n", self::globalDbQuery( "SELECT schema_name FROM information_schema.schemata WHERE $where_db" )->stdout ) ) as $db ) {
				$db = trim( $db );
				if ( '' !== $db && ! in_array( $db, $used_dbs, true ) && false === strpos( $db, '`' ) ) {
					self::globalDbQuery( sprintf( 'DROP DATABASE IF EXISTS `%s`', $db ) );
				}
			}
			foreach ( array_filter( explode( "\n", self::globalDbQuery( "SELECT CONCAT(user, '@', host) FROM mysql.user WHERE $where_user" )->stdout ) ) as $user_host ) {
				list( $user, $host ) = array_pad( explode( '@', trim( $user_host ), 2 ), 2, '' );
				if ( '' !== $user && ! in_array( $user, $used_users, true ) ) {
					self::globalDbQuery( sprintf( "DROP USER IF EXISTS '%s'@'%s'", addslashes( $user ), addslashes( $host ) ) );
				}
			}
		}

		// Page-cache keys of the suite's sites live for 4 hours and would let a later run's cache checks pass on old keys.
		if ( 0 === self::run( 'docker inspect ' . self::GLOBAL_REDIS_CONTAINER )->return_code ) {
			foreach ( [ 'bh-site-type-php-*', 'behat-bh-site-type-php-*' ] as $pattern ) {
				$script = sprintf( 'redis-cli --scan --pattern %s | while IFS= read -r k; do redis-cli del "$k" >/dev/null; done', escapeshellarg( $pattern ) );
				self::run( sprintf( 'docker exec %s sh -c %s', self::GLOBAL_REDIS_CONTAINER, escapeshellarg( $script ) ) );
			}
		}

		// The shell-syntax site name payload can run again when that site is deleted above.
		if ( file_exists( EE_ROOT . '/bhstpsec1' ) ) {
			unlink( EE_ROOT . '/bhstpsec1' );
		}

		// Hosts entries for this suite's names (ee's own and the ones added by `the hosts file maps ...`). A line is only
		// dropped when every host name on it belongs to the suite.
		if ( is_writable( '/etc/hosts' ) ) {
			$hosts = file_get_contents( '/etc/hosts' );
			$kept  = array_filter( explode( "\n", $hosts ), function ( $line ) {
				if ( false !== strpos( $line, self::HOSTS_MARKER ) ) {
					return false;
				}
				$fields = preg_split( '/\s+/', trim( preg_replace( '/#.*/', '', $line ) ) );
				$names  = array_slice( $fields, 1 );
				if ( empty( $names ) ) {
					return true;
				}
				foreach ( $names as $name ) {
					if ( 1 !== preg_match( self::SITE_PATTERN, $name ) ) {
						return true;
					}
				}

				return false;
			} );
			$new   = implode( "\n", $kept );
			if ( rtrim( $new, "\n" ) !== rtrim( $hosts, "\n" ) ) {
				file_put_contents( '/etc/hosts', rtrim( $new, "\n" ) . "\n" );
			}
		}
	}
}
