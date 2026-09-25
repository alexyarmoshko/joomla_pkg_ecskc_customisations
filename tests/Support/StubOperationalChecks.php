<?php

declare(strict_types=1);

namespace YakShaver\ECSKCCustomisation\Tests\Support;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Operational\ExtensionRecordRead;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Operational\OperationalCheckProviderInterface;

/**
 * The operational-check provider with the raw read set by the test, and no database.
 *
 * It answers with an enabled plugin, the driver's `int(1)`, until a test says
 * otherwise, so a suite about targets sees a healthy site. It also counts reads, so
 * a test can show that a write run never asked.
 */
final class StubOperationalChecks implements OperationalCheckProviderInterface
{
    public int $reads = 0;

    public ExtensionRecordRead $read;

    public function __construct(?ExtensionRecordRead $read = null)
    {
        $this->read = $read ?? ExtensionRecordRead::value(1);
    }

    public function systemPluginEnabled(): ExtensionRecordRead
    {
        ++$this->reads;

        return $this->read;
    }
}
