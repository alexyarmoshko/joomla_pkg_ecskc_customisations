<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Unit\State;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\StateFile;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntent;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentRead;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteIntentStore;
use YakShaver\ECSKCCustomisation\Tests\Support\TempTree;

#[CoversClass(WriteIntentStore::class)]
#[CoversClass(WriteIntent::class)]
#[CoversClass(WriteIntentRead::class)]
final class WriteIntentStoreTest extends TestCase
{
    /**
     * A create, which is the case the file bytes alone cannot reconstruct.
     *
     * @var array<string, mixed>
     */
    private const EVENT = [
        'event'     => 'write',
        'target'    => 'templates/et_tennis/css/override.css',
        'operation' => 'copy',
        'pre'       => 'absent',
        'post'      => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        'objects'   => ['1', '2'],
    ];

    private TempTree $tree;

    private PackagePaths $paths;

    protected function setUp(): void
    {
        $this->tree  = TempTree::create('ecskc-intent-');
        $this->paths = new PackagePaths($this->tree->path('com_ecskccustom'));

        \mkdir($this->paths->state(), 0755, true);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private function store(): WriteIntentStore
    {
        return new WriteIntentStore($this->paths);
    }

    public function testTheIntentIsPublishedAtItsExactPathUnderState(): void
    {
        $store = $this->store();

        self::assertSame($this->paths->state() . '/write.intent', $store->path());
        self::assertTrue($store->publish(WriteIntent::begin(self::EVENT)));
        self::assertFileExists($this->paths->writeIntent());
    }

    public function testTheStoreRoundTripsACorrelationIdAndACompleteEventPayload(): void
    {
        $intent = WriteIntent::begin(self::EVENT);

        self::assertTrue($this->store()->publish($intent));

        $read = $this->store()->read();

        self::assertTrue($read->isPresent());
        self::assertTrue($read->isDetermined());
        self::assertSame($intent->id, $read->intent?->id);
        self::assertSame(
            self::EVENT,
            $read->intent?->event,
            'Recovery appends this payload, so it comes back exactly as it went in.'
        );
    }

    public function testEachIntentGetsItsOwnCorrelationId(): void
    {
        self::assertNotSame(WriteIntent::begin(self::EVENT)->id, WriteIntent::begin(self::EVENT)->id);
    }

    public function testNoIntentIsADeterminedNegative(): void
    {
        $read = $this->store()->read();

        self::assertFalse($read->isPresent());
        self::assertTrue($read->isDetermined(), 'An absent intent means no mutation is outstanding.');
        self::assertSame(WriteIntentStore::NO_INTENT, $read->reason);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedRecords(): array
    {
        return [
            'empty'            => [''],
            'truncated json'   => ['{"id":"a1b2","event":{"event":"wri'],
            'no correlation id' => ['{"event":{"event":"write"}}'],
            'no payload'       => ['{"id":"a1b2c3d4"}'],
            'empty payload'    => ['{"id":"a1b2c3d4","event":{}}'],
            'id is not a string' => ['{"id":17,"event":{"event":"write"}}'],
            'unusable id'      => ['{"id":"a b\ncd","event":{"event":"write"}}'],
            'not an object'    => ['"write"'],
        ];
    }

    #[DataProvider('malformedRecords')]
    public function testAnIntentThatDoesNotParseIsAFaultRatherThanNoIntent(string $bytes): void
    {
        \file_put_contents($this->paths->writeIntent(), $bytes);

        $read = $this->store()->read();

        self::assertFalse($read->isPresent());
        self::assertFalse(
            $read->isDetermined(),
            'A record nobody can read is not the same as a mutation that was settled.'
        );
        self::assertStringContainsString($this->paths->writeIntent(), (string) $read->reason);
        self::assertTrue($this->store()->isPresent());
    }

    public function testAnIntentThatCannotBeReadIsAFaultRatherThanNoIntent(): void
    {
        self::assertTrue($this->store()->publish(WriteIntent::begin(self::EVENT)));
        \chmod($this->paths->writeIntent(), 0000);

        if (\is_readable($this->paths->writeIntent())) {
            self::markTestSkipped('This process can read a mode 0000 file, so it is likely root.');
        }

        $read = $this->store()->read();

        self::assertFalse($read->isDetermined());
        self::assertStringContainsString('could not be read', (string) $read->reason);

        \chmod($this->paths->writeIntent(), 0644);
    }

    public function testAPublishInterruptedBeforeTheRenameLeavesThePreviousRecord(): void
    {
        $first = WriteIntent::begin(self::EVENT);
        self::assertTrue($this->store()->publish($first));

        $temporary = (new StateFile($this->paths->writeIntent()))
            ->beginPublication(WriteIntent::begin(['event' => 'freeze'])->toJson() . "\n");
        self::assertIsString($temporary);

        self::assertSame(
            $first->id,
            $this->store()->read()->intent?->id,
            'The intent path holds a complete record or the previous one, never a fragment.'
        );

        self::assertSame([], $this->store()->sweepTemporaries());
        self::assertFileDoesNotExist($temporary);
    }

    public function testAnInterruptedFirstPublishLeavesNoIntent(): void
    {
        $temporary = (new StateFile($this->paths->writeIntent()))
            ->beginPublication(WriteIntent::begin(self::EVENT)->toJson() . "\n");

        self::assertIsString($temporary);
        self::assertFalse(
            $this->store()->isPresent(),
            'A mutation whose intent never landed has no intent to settle.'
        );
    }

    public function testASettledIntentIsRemoved(): void
    {
        self::assertTrue($this->store()->publish(WriteIntent::begin(self::EVENT)));
        self::assertTrue($this->store()->remove());

        self::assertFalse($this->store()->isPresent());
        self::assertSame(WriteIntentStore::NO_INTENT, $this->store()->read()->reason);
    }

    public function testARecordWithoutAUsableCorrelationIdIsRefusedBeforeItIsWritten(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WriteIntent::of("a1b2\nc3d4", self::EVENT);
    }

    public function testAnEmptyPayloadIsRefusedBeforeItIsWritten(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WriteIntent::begin([]);
    }
}
