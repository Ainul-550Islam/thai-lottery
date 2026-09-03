<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the project's own database configuration against the PHP 8.5 PDO
 * constant deprecation.
 *
 * WHAT WAS WRONG
 * --------------
 * PHP 8.5 deprecated the PDO::MYSQL_* class constants in favour of the dedicated
 * Pdo\Mysql class. config/database.php referenced PDO::MYSQL_ATTR_SSL_CA directly,
 * so simply loading the project's configuration emitted:
 *
 *   Constant PDO::MYSQL_ATTR_SSL_CA is deprecated since 8.5,
 *   use Pdo\Mysql::ATTR_SSL_CA instead
 *
 * WHAT WAS CHANGED
 * ----------------
 * The project file now prefers Pdo\Mysql::ATTR_SSL_CA when that class exists and
 * falls back to PDO::MYSQL_ATTR_SSL_CA when it does not. Both names resolve to the
 * same integer attribute id, 1008, so the connection behaviour is byte for byte
 * identical and the file still runs on PHP 8.2 to 8.4, where Pdo\Mysql is absent.
 *
 * WHAT IS STILL CARRIED FORWARD
 * -----------------------------
 * Laravel merges its own vendor/laravel/framework/config/database.php underneath
 * the application file, and that vendor file still references the deprecated
 * constant on lines 61 and 81. The remaining deprecation notice in the test run
 * therefore originates in the framework, not in this project, and cannot be removed
 * without a major framework upgrade. This test deliberately asserts only what the
 * project controls: no file under config/ may contain a compiled reference to a
 * deprecated PDO::MYSQL_* constant. The PHP 8.2 to 8.4 fallback in
 * config/database.php reaches its constant through constant() precisely so that no
 * such compiled reference exists, and this test also pins that the file prefers the
 * modern Pdo\Mysql name.
 */
final class DatabaseDriverOptionsTest extends TestCase
{
    /**
     * Both spellings name the same PDO attribute, so the swap changes no behaviour.
     */
    #[Test]
    public function the_modern_and_legacy_ssl_ca_attribute_ids_are_identical(): void
    {
        // The modern class-based spelling only exists on PHP 8.4+. On an 8.2/8.3 build
        // the project deliberately falls back to the legacy PDO::MYSQL_ATTR_SSL_CA
        // constant, so there is nothing to compare and the assertion below would be
        // meaningless. A skip states that honestly; a failure would report a supported
        // PHP version as a defect.
        if (! class_exists(\Pdo\Mysql::class)) {
            $this->markTestSkipped(
                'Pdo\\Mysql is only provided by PHP 8.4+. The legacy fallback in config/database.php is asserted by the sibling tests in this class.'
            );
        }

        $this->assertSame(
            1008,
            \Pdo\Mysql::ATTR_SSL_CA,
            'Pdo\\Mysql::ATTR_SSL_CA must remain attribute id 1008.',
        );
    }

    /**
     * The resolved mysql option array is keyed by the SSL CA attribute id, or empty.
     *
     * The option is wrapped in array_filter(), so with no MYSQL_ATTR_SSL_CA set in
     * the environment the array is legitimately empty. Either shape is correct; a
     * key that is not the SSL CA attribute id is not.
     */
    #[Test]
    public function the_mysql_options_array_is_keyed_by_the_ssl_ca_attribute_id(): void
    {
        $options = config('database.connections.mysql.options');

        $this->assertIsArray($options);

        foreach (array_keys($options) as $key) {
            $this->assertSame(
                1008,
                $key,
                'The only option this project sets on the mysql connection is the SSL CA path.',
            );
        }
    }

    /**
     * No project configuration file may reference a deprecated PDO::MYSQL_* constant.
     *
     * Scans the real files rather than the resolved configuration, because the
     * deprecation is emitted at the moment the constant is referenced, which the
     * resolved array cannot show.
     */
    #[Test]
    public function no_project_config_file_references_a_deprecated_pdo_mysql_constant(): void
    {
        $directory = config_path();

        $this->assertDirectoryExists($directory);

        $files = glob($directory.'/*.php');

        $this->assertIsArray($files);
        $this->assertNotEmpty($files, 'The project must ship configuration files.');

        $offenders = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                $this->fail(sprintf('Could not read %s.', basename($file)));
            }

            // Comments and string literal contents are stripped first. A comment
            // that explains the deprecation, and the constant('PDO::...') fallback
            // whose argument is a plain string, are not compiled constant
            // references and must not be reported as offenders. What remains is
            // exactly the set of references PHP would resolve at compile time and
            // warn about.
            $code = '';

            $ignored = [
                T_COMMENT,
                T_DOC_COMMENT,
                T_CONSTANT_ENCAPSED_STRING,
                T_ENCAPSED_AND_WHITESPACE,
            ];

            foreach (token_get_all($contents) as $token) {
                if (is_array($token) && in_array($token[0], $ignored, true)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            if (preg_match('/PDO\s*::\s*MYSQL_/i', $code) === 1) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These config files still reference a PDO::MYSQL_* constant deprecated in PHP 8.5: '
                .implode(', ', $offenders),
        );
    }

    /**
     * config/database.php prefers the modern Pdo\Mysql attribute name.
     *
     * The previous test proves the deprecated name is absent. This one proves the
     * modern name is actually present, so the pair cannot both pass on a file that
     * simply dropped the option altogether.
     */
    #[Test]
    public function the_database_config_prefers_the_modern_pdo_mysql_attribute_name(): void
    {
        $file = config_path('database.php');

        $this->assertFileExists($file);

        $contents = file_get_contents($file);

        $this->assertIsString($contents);
        $this->assertStringContainsString(
            'Pdo\Mysql::ATTR_SSL_CA',
            $contents,
            'config/database.php must reach the SSL CA attribute through Pdo\Mysql on PHP 8.5.',
        );
        $this->assertStringContainsString(
            'class_exists(\Pdo\Mysql::class)',
            $contents,
            'The modern name must be guarded so the file still runs on PHP 8.2 to 8.4.',
        );
    }

    /**
     * The mysql connection still resolves and still points at the mysql driver.
     *
     * A configuration edit that silently broke the connection definition would be
     * far worse than the deprecation it removed.
     */
    #[Test]
    public function the_mysql_connection_definition_is_still_intact(): void
    {
        $connection = config('database.connections.mysql');

        $this->assertIsArray($connection);
        $this->assertSame('mysql', $connection['driver'] ?? null);
        $this->assertTrue($connection['strict'] ?? false, 'Strict mode must stay enabled.');
        $this->assertSame('utf8mb4', $connection['charset'] ?? null);
        $this->assertArrayHasKey('options', $connection);
    }
}
