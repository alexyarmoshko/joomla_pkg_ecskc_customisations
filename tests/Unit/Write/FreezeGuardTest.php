<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\Write;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\FreezeMarkers;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Write\FreezeGuard;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

#[CoversClass(FreezeGuard::class)]
final class FreezeGuardTest extends TestCase
{
    private const TARGET = 'templates/et_tennis/css/override.css';

    private TempTree $tree;

    private PackagePaths $paths;

    protected function setUp(): void
    {
        $this->tree  = TempTree::create('ecskc-guard-');
        $this->paths = new PackagePaths($this->tree->path('com_ecskccustom'));

        \mkdir(\dirname($this->paths->freezeMarker(self::TARGET)), 0755, true);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private function guard(): FreezeGuard
    {
        return new FreezeGuard(new FreezeMarkers($this->paths));
    }

    public function testTheGuardAdmitsAWriteWhenNoMarkerIsPresent(): void
    {
        self::assertNull($this->guard()->refusal(self::TARGET));
    }

    /**
     * @return array<string, array{Closure(string): void}>
     */
    public static function markerShapes(): array
    {
        return [
            'an empty file'      => [static fn (string $marker): bool => \touch($marker)],
            'an unreadable file' => [static fn (string $marker): bool => \touch($marker) && \chmod($marker, 0)],
            'a directory'        => [static fn (string $marker): bool => \mkdir($marker)],
        ];
    }

    /**
     * @param Closure(string): void $make
     */
    #[DataProvider('markerShapes')]
    public function testTheGuardRefusesAWriteWhileAnyMarkerShapeIsPresent(Closure $make): void
    {
        $make($this->paths->freezeMarker(self::TARGET));

        $refusal = $this->guard()->refusal(self::TARGET);

        self::assertNotNull($refusal);
        self::assertStringContainsString(self::TARGET, $refusal);
        self::assertStringContainsString('frozen', $refusal);
    }

    public function testTheGuardIgnoresAMarkerThatIsASymbolicLink(): void
    {
        $marker = $this->paths->freezeMarker(self::TARGET);
        \symlink($this->tree->write('outside.txt', ''), $marker);

        self::assertNull($this->guard()->refusal(self::TARGET), 'Decision 40: a link is not a freeze.');

        \unlink($marker);
        \symlink($marker . '.gone', $marker);

        self::assertNull($this->guard()->refusal(self::TARGET));
    }
}
