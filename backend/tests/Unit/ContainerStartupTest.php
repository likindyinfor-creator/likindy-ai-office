<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ContainerStartupTest extends TestCase
{
    public function test_container_startup_and_build_never_run_migrations_or_seeders(): void
    {
        $backendPath = dirname(__DIR__, 2);
        $dockerfile = file_get_contents($backendPath.'/Dockerfile');
        $entrypoint = file_get_contents($backendPath.'/.docker/render-entrypoint.sh');
        $composer = json_decode(
            file_get_contents($backendPath.'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $this->assertStringNotContainsString('artisan migrate', $dockerfile);
        $this->assertStringNotContainsString('artisan db:seed', $dockerfile);
        $this->assertStringNotContainsString('artisan migrate', $entrypoint);
        $this->assertStringNotContainsString('artisan db:seed', $entrypoint);
        $this->assertStringContainsString('queue:work', $entrypoint);

        $composerScripts = json_encode($composer['scripts'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('artisan migrate', $composerScripts);
        $this->assertStringNotContainsString('artisan db:seed', $composerScripts);
    }

    public function test_railway_explicitly_builds_the_backend_dockerfile(): void
    {
        $railwayConfig = file_get_contents(dirname(__DIR__, 3).'/railway.toml');

        $this->assertStringContainsString('builder = "DOCKERFILE"', $railwayConfig);
        $this->assertStringContainsString('dockerfilePath = "backend/Dockerfile"', $railwayConfig);
    }

    public function test_apache_build_enables_only_the_prefork_mpm(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/Dockerfile');

        $this->assertStringContainsString('a2dismod "$module"', $dockerfile);
        $this->assertStringContainsString('for module in mpm_event mpm_worker', $dockerfile);
        $this->assertStringContainsString('a2enmod mpm_prefork headers rewrite', $dockerfile);
        $this->assertStringContainsString('/etc/apache2/mods-enabled/mpm_prefork.load', $dockerfile);
        $this->assertStringContainsString('/etc/apache2/mods-enabled -maxdepth 1 -name', $dockerfile);
    }

    public function test_apache_runtime_removes_conflicting_mpms_and_asserts_prefork_only(): void
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 2).'/.docker/render-entrypoint.sh');
        $removeConflicts = strpos($entrypoint, 'rm -f "/etc/apache2/mods-enabled/${module}.load"');
        $enablePrefork = strpos($entrypoint, 'a2enmod mpm_prefork');
        $assertOnlyPrefork = strpos($entrypoint, 'if [ "$enabled_mpm_count" -ne 1 ]');
        $validateConfig = strpos($entrypoint, 'if ! apache2ctl -t');
        $readLoadedModules = strpos($entrypoint, 'loaded_modules=$(apache2ctl -M 2>&1)');
        $assertLoadedPrefork = strpos($entrypoint, 'if [ "$loaded_mpm_count" -ne 1 ]');
        $startApache = strrpos($entrypoint, 'exec apache2-foreground');

        $this->assertNotFalse($removeConflicts);
        $this->assertNotFalse($enablePrefork);
        $this->assertNotFalse($assertOnlyPrefork);
        $this->assertNotFalse($validateConfig);
        $this->assertNotFalse($readLoadedModules);
        $this->assertNotFalse($assertLoadedPrefork);
        $this->assertNotFalse($startApache);
        $this->assertLessThan($enablePrefork, $removeConflicts);
        $this->assertLessThan($assertOnlyPrefork, $enablePrefork);
        $this->assertLessThan($validateConfig, $assertOnlyPrefork);
        $this->assertLessThan($readLoadedModules, $validateConfig);
        $this->assertLessThan($assertLoadedPrefork, $readLoadedModules);
        $this->assertLessThan($startApache, $assertLoadedPrefork);
        $this->assertStringContainsString('"$enabled_mpm" != "mpm_prefork.load"', $entrypoint);
        $this->assertStringContainsString('"$loaded_mpms" != "mpm_prefork_module"', $entrypoint);
        $this->assertStringContainsString('exit 1', $entrypoint);
    }
}
