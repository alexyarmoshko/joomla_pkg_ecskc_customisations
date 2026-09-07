<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\RunMarker;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\StateFile;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

#[CoversClass(RunMarker::class)]
final class RunMarkerTest extends TestCase
{
    private TempTree $tree;

    private PackagePaths $paths;

    protected function setUp(): void
    {
        $this->tree  = TempTree::create('ecskc-marker-');
        $this->paths = new PackagePaths($this->tree->path('com_ecskccustom'));

        \mkdir($this->paths->state(), 0755, true);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private function marker(): RunMarker
    {
        return new RunMarker($this->paths);
    }

    public function testTheMarkerIsPublishedAtItsExactPathUnderState(): void
    {
        $marker = $this->marker();

        self::assertSame($this->paths->state() . '/run.marker', $marker->path());
        self::assertTrue($marker->publish(1_757_275_985));
        self::assertFileExists($this->paths->marker());
    }

    public function testTheRecordIsOneIsoTimestampAndNothingElse(): void
    {
        self::assertTrue($this->marker()->publish(1_757_275_985));

        self::assertSame(
            "2025-09-07T20:13:05Z\n",
            \file_get_contents($this->paths->marker()),
            'The marker says a run began and when, never how far it got.'
        );

        $read = $this->marker()->read();
        self::assertTrue($read->isKnown());
        self::assertSame('2025-09-07T20:13:05Z', $read->value);
    }

    public function testNoMarkerIsADeterminedNegative(): void
    {
        $read = $this->marker()->read();

        self::assertFalse($read->isKnown());
        self::assertTrue($read->isDetermined(), 'An absent marker is an answer, not a fault.');
        self::assertSame(RunMarker::NO_MARKER, $read->reason);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedRecords(): array
    {
        return [
            'empty'              => [''],
            'no line terminator' => ['2025-09-07T20:13:05Z'],
            'not a timestamp'    => ["a run began\n"],
            'a second line'      => ["2025-09-07T20:13:05Z\n2025-09-07T20:14:00Z\n"],
            'local time'         => ["2025-09-07T20:13:05+02:00\n"],
            'truncated'          => ["2025-09-07T20:1\n"],
            'impossible date'    => ["2025-02-31T20:13:05Z\n"],
            'impossible month'   => ["2025-13-01T20:13:05Z\n"],
        ];
    }

    #[DataProvider('malformedRecords')]
    public function testAMarkerThatDoesNotParseIsAFaultRatherThanACleanHistory(string $bytes): void
    {
        \file_put_contents($this->paths->marker(), $bytes);

        $read = $this->marker()->read();

        self::assertFalse($read->isKnown());
        self::assertFalse(
            $read->isDetermined(),
            'The marker is the claim that a run began, so a claim nobody can read is a fault.'
        );
        self::assertStringContainsString($this->paths->marker(), (string) $read->reason);

        self::assertTrue($this->marker()->isPresent(), 'A malformed marker is still present.');
    }

    public function testAMarkerThatCannotBeReadIsAFaultRatherThanACleanHistory(): void
    {
        self::assertTrue($this->marker()->publish());
        \chmod($this->paths->marker(), 0000);

        if (\is_readable($this->paths->marker())) {
            self::markTestSkipped('This process can read a mode 0000 file, so it is likely root.');
        }

        $read = $this->marker()->read();

        self::assertFalse($read->isDetermined());
        self::assertStringContainsString('could not be read', (string) $read->reason);

        \chmod($this->paths->marker(), 0644);
    }

    public function testAPublishInterruptedBeforeTheRenameLeavesThePreviousRecord(): void
    {
        self::assertTrue($this->marker()->publish(1_757_275_985));

        // The dead process's own write, through the code a publish uses.
        $temporary = (new StateFile($this->paths->marker()))
            ->beginPublication("2025-09-08T09:00:00Z\n");
        self::assertIsString($temporary);

        $read = $this->marker()->read();
        self::assertSame(
            '2025-09-07T20:13:05Z',
            $read->value,
            'The marker path holds a complete record or the previous one, never a fragment.'
        );

        self::assertSame([], $this->marker()->sweepTemporaries());
        self::assertFileDoesNotExist(
            $temporary,
            'The next process holding the run lock removes the abandoned temporary.'
        );
        self::assertSame('2025-09-07T20:13:05Z', $this->marker()->read()->value);
    }

    public function testRepublishingReplacesTheRecordWholesale(): void
    {
        self::assertTrue($this->marker()->publish(1_757_275_985));
        self::assertTrue($this->marker()->publish(1_757_320_800));

        self::assertSame('2025-09-08T08:40:00Z', $this->marker()->read()->value);
    }

    public function testACompletedRunRemovesTheMarker(): void
    {
        self::assertTrue($this->marker()->publish());
        self::assertTrue($this->marker()->remove());

        self::assertFalse($this->marker()->isPresent());
        self::assertSame(RunMarker::NO_MARKER, $this->marker()->read()->reason);
    }
}
