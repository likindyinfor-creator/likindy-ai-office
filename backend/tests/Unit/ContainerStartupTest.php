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
}
