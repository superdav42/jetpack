<?php
/**
 * Autoload Generator test suite.
 *
 * @package automattic/jetpack-autoloader
 */

use Automattic\Jetpack\Autoloader\AutoloadGenerator;
use Composer\IO\NullIO;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use PHPUnit\Framework\TestCase;

/**
 * Test suite class for the Autoload generator.
 */
class AutoloadGeneratorTest extends TestCase {

	/**
	 * The AutoloadGenerator instance being tested.
	 *
	 * @var AutoloadGenerator
	 */
	private $generator;

	/**
	 * Temporary directory for test fixtures.
	 *
	 * @var string
	 */
	private $testDir;

	/**
	 * Setup runs before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->generator = new AutoloadGenerator( new NullIO() );
		$this->testDir   = TEST_TEMP_DIR . '/autoload-gen-' . uniqid();
		mkdir( $this->testDir, 0777, true );
	}

	/**
	 * Teardown runs after each test.
	 */
	protected function tearDown(): void {
		parent::tearDown();
		$this->removeDir( $this->testDir );
	}

	/**
	 * Helper to build an expected exclude-from-classmap regex pattern.
	 *
	 * @param string $resolvedBase The resolved base directory path.
	 * @param string $suffix The path suffix (already preg_quote'd by the generator).
	 * @return string The expected regex pattern.
	 */
	private function buildExpectedPattern( $resolvedBase, $suffix ) {
		$escaped = preg_quote( str_replace( '\\', '/', $resolvedBase ), '/' );
		return $escaped . '/' . $suffix . '($|/)';
	}

	/**
	 * Data provider for parseAutoloads exclude-from-classmap tests.
	 *
	 * Each case provides:
	 *   - autoload: Root package autoload config.
	 *   - devAutoload: Root package dev autoload config.
	 *   - depAutoloads: Array of dependency configs (name, autoload, devAutoload).
	 *   - dirsToCreate: Directories to create under testDir for realpath() resolution.
	 *   - expectedSuffixes: Array of path suffixes expected in the exclude patterns.
	 *
	 * @return array Test cases.
	 */
	public static function provide_parse_autoloads_cases(): array {
		return array(
			'no exclude-from-classmap'                               => array(
				'autoload'         => array(
					'classmap' => array( 'src' ),
					'psr-4'    => array( 'Test\\' => 'src/' ),
				),
				'devAutoload'      => array(),
				'depAutoloads'     => array(),
				'dirsToCreate'     => array( 'src' ),
				'expectedSuffixes' => array(),
			),
			'exclude in both autoload and autoload-dev with overlap' => array(
				'autoload'         => array(
					'classmap'              => array( 'src' ),
					'exclude-from-classmap' => array( 'src/Excluded.php', 'common/' ),
				),
				'devAutoload'      => array(
					'exclude-from-classmap' => array( 'common/', 'tests/' ),
				),
				'depAutoloads'     => array(),
				'dirsToCreate'     => array( 'src', 'common', 'tests' ),
				// Composer does not deduplicate: common/ appears from both autoload and autoload-dev.
				'expectedSuffixes' => array( 'src/Excluded\\.php', 'common', 'common', 'tests' ),
			),
			'exclude in autoload-dev only'                           => array(
				'autoload'         => array(
					'classmap' => array( 'src' ),
				),
				'devAutoload'      => array(
					'exclude-from-classmap' => array( 'tests/fixtures/' ),
				),
				'depAutoloads'     => array(),
				'dirsToCreate'     => array( 'src', 'tests/fixtures' ),
				'expectedSuffixes' => array( 'tests/fixtures' ),
			),
			'non-root package autoload used, devAutoload ignored'    => array(
				'autoload'         => array(
					'classmap' => array( 'src' ),
				),
				'devAutoload'      => array(),
				'depAutoloads'     => array(
					array(
						'name'        => 'some-vendor/dep',
						'autoload'    => array(
							'exclude-from-classmap' => array( 'excluded/' ),
						),
						'devAutoload' => array(
							'exclude-from-classmap' => array( 'should-be-ignored/' ),
						),
					),
				),
				'dirsToCreate'     => array( 'src' ),
				// Only the dep's autoload exclusion should appear; devAutoload is ignored for non-root.
				'expectedSuffixes' => array( 'excluded' ),
			),
		);
	}

	/**
	 * Tests parseAutoloads returns correct exclude-from-classmap results.
	 *
	 * @param array $autoload Root package autoload config.
	 * @param array $devAutoload Root package dev autoload config.
	 * @param array $depAutoloads Dependency autoload configs.
	 * @param array $dirsToCreate Directories to create for the root package.
	 * @param array $expectedSuffixes Expected path suffixes in exclude patterns.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_parse_autoloads_cases' )]
	public function test_parse_autoloads_exclude_from_classmap(
		array $autoload,
		array $devAutoload,
		array $depAutoloads,
		array $dirsToCreate,
		array $expectedSuffixes
	) {
		// Create directories so realpath() can resolve them.
		foreach ( $dirsToCreate as $dir ) {
			mkdir( $this->testDir . '/' . $dir, 0777, true );
		}

		// Build root package.
		$package = new RootPackage( 'test/package', '1.0.0', '1.0.0' );
		$package->setAutoload( $autoload );
		$package->setDevAutoload( $devAutoload );

		// Build package map: root package first, then dependencies.
		$packageMap = array( array( $package, $this->testDir ) );
		foreach ( $depAutoloads as $dep ) {
			$depInstallDir = $this->testDir . '/vendor/' . $dep['name'];

			// Create the dep's install directory and any exclude-from-classmap subdirs
			// so that realpath() can resolve them during parsing.
			mkdir( $depInstallDir, 0777, true );
			if ( isset( $dep['autoload']['exclude-from-classmap'] ) ) {
				foreach ( $dep['autoload']['exclude-from-classmap'] as $excludePath ) {
					$fullPath = $depInstallDir . '/' . trim( $excludePath, '/' );
					if ( ! is_dir( $fullPath ) ) {
						mkdir( $fullPath, 0777, true );
					}
				}
			}

			$depPackage = new Package( $dep['name'], '1.0.0', '1.0.0' );
			$depPackage->setAutoload( $dep['autoload'] );
			$depPackage->setDevAutoload( isset( $dep['devAutoload'] ) ? $dep['devAutoload'] : array() );
			$packageMap[] = array( $depPackage, $depInstallDir );
		}

		$result = $this->generator->parseAutoloads( $packageMap, $package );

		// Verify the result has all expected keys.
		$this->assertArrayHasKey( 'psr-0', $result );
		$this->assertArrayHasKey( 'psr-4', $result );
		$this->assertArrayHasKey( 'classmap', $result );
		$this->assertArrayHasKey( 'files', $result );
		$this->assertArrayHasKey( 'exclude-from-classmap', $result );

		if ( empty( $expectedSuffixes ) ) {
			$this->assertSame( array(), $result['exclude-from-classmap'] );
		} else {
			$this->assertCount( count( $expectedSuffixes ), $result['exclude-from-classmap'] );

			// Build expected patterns from the resolved test directory path.
			foreach ( $result['exclude-from-classmap'] as $i => $pattern ) {
				// Each pattern should be a regex ending with ($|/).
				$this->assertStringEndsWith( '($|/)', $pattern, 'Pattern should end with ($|/)' );

				// Verify the pattern contains the expected path suffix.
				$this->assertStringContainsString(
					$expectedSuffixes[ $i ],
					$pattern,
					"Pattern $i should contain expected suffix '{$expectedSuffixes[$i]}'"
				);
			}
		}
	}

	/**
	 * Tests that the full result of parseAutoloads has deterministic values for all keys.
	 */
	public function test_parse_autoloads_full_result_structure() {
		mkdir( $this->testDir . '/src', 0777, true );

		$package = new RootPackage( 'test/package', '1.0.0', '1.0.0' );
		$package->setAutoload(
			array(
				'classmap'              => array( 'src' ),
				'psr-4'                 => array( 'Test\\' => 'src/' ),
				'exclude-from-classmap' => array( 'src/Excluded.php' ),
			)
		);
		$package->setDevAutoload( array() );

		$packageMap = array( array( $package, $this->testDir ) );
		$result     = $this->generator->parseAutoloads( $packageMap, $package );

		// Verify psr-4 structure.
		$this->assertArrayHasKey( 'Test\\', $result['psr-4'] );
		$this->assertSame( '1.0.0', $result['psr-4']['Test\\'][0]['version'] );
		$this->assertSame( $this->testDir . '/src/', $result['psr-4']['Test\\'][0]['path'] );
		$this->assertSame( 'test/package', $result['psr-4']['Test\\'][0]['package'] );

		// Verify classmap structure.
		$this->assertCount( 1, $result['classmap'] );
		$this->assertSame( '1.0.0', $result['classmap'][0]['version'] );
		$this->assertSame( $this->testDir . '/src', $result['classmap'][0]['path'] );
		$this->assertSame( 'test/package', $result['classmap'][0]['package'] );

		// Verify exclude-from-classmap.
		$this->assertCount( 1, $result['exclude-from-classmap'] );

		$resolvedDir = realpath( $this->testDir );
		$expected    = preg_quote( str_replace( '\\', '/', $resolvedDir ) ) . '/src/Excluded\\.php($|/)';
		$this->assertSame( $expected, $result['exclude-from-classmap'][0] );

		// Verify empty arrays for unused types.
		$this->assertSame( array(), $result['psr-0'] );
		$this->assertSame( array(), $result['files'] );
	}

	/**
	 * Recursively removes a directory.
	 *
	 * @param string $dir The directory to remove.
	 */
	private function removeDir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getRealPath() );
			} else {
				unlink( $item->getRealPath() );
			}
		}
		rmdir( $dir );
	}
}
