<?php

/**
 * @package     YakShaver\Component\ECSKCCustomisation
 * @subpackage  Administrator
 * @author      Yak Shaver <me@kayakshaver.com>
 * @copyright   (C) 2026 Yak Shaver https://www.kayakshaver.com
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace YakShaver\Component\ECSKCCustomisation\Administrator\Core;

use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentProviderInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Environment\EnvironmentResolver;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Package\PackagePaths;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\OperationalChecks;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\ReportedValue;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\RunReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Report\TargetReport;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\MergedSetValidator;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Rule\RuleLoader;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\State\WriteHistoryInterface;
use YakShaver\Component\ECSKCCustomisation\Administrator\Core\Walk\ObjectWalker;

/**
 * The one entry point into the core, and the only thing a Joomla adapter talks to.
 *
 * Each responsibility stays behind it and none of them knows about the others:
 * loading, validation, environment values, path policy, hashing, and the walk. The
 * runner puts them in order and turns what they produce into one report.
 *
 * It reads the rule set from the installed package directory and from nowhere else,
 * per brief 5.4. The directory comes from PackagePaths, so the installer that
 * creates these folders and the run that reads them cannot drift apart.
 *
 * ## What it does not do
 *
 * No HTML, no redirect, no Joomla message, no request input, and no database query
 * of its own. A controller calls `check()` and renders what comes back; the operator
 * sees the report and nothing the runner decided about presenting it. The one route
 * by which a database record may reach a run is the operational-check provider that
 * Phase 4 adds, and even then the runner is handed the raw value rather than issuing
 * the query.
 *
 * ## Report-only
 *
 * `check()` is the whole of Phase 1. The walk proposes operations and this returns
 * them unexecuted, so a run creates, changes and removes no file. Dry run and the
 * write operations land in Phase 2 as methods beside this one.
 */
final class Runner
{
    /**
     * A check displaces nothing, so there is no backup to name.
     *
     * The field is carried all the same. The contract requires it wherever an
     * operation preserves displaced content, and a line that dropped it in a
     * report-only run would look like a line that had nothing to say.
     */
    private const NO_BACKUP = 'a check performs no operation, so nothing is displaced and no '
        . 'backup is written';

    public function __construct(
        private readonly PackagePaths $paths,
        private readonly RuleLoader $loader,
        private readonly MergedSetValidator $validator,
        private readonly EnvironmentProviderInterface $environment,
        private readonly EnvironmentResolver $resolver,
        private readonly ObjectWalker $walker,
        private readonly WriteHistoryInterface $history,
    ) {
    }

    /**
     * Read the state of every managed path and report it. Writes nothing.
     *
     * The core owns the operational-checks section, not the adapter. Phase 1
     * defines no check, so the section is present and empty. Phase 4 injects the
     * raw-value provider and adds the single core-owned raw-value-to-outcome
     * mapping here, before the report is composed, and the report shape does not
     * move.
     */
    public function check(): RunReport
    {
        $checks       = OperationalChecks::none();
        $interruption = $this->history->interruption();

        $loaded = $this->loader->load($this->paths->rules());

        // A set that did not load cleanly must not be validated as a merged set: a
        // file the schema rejected contributes no objects, so its payloads look
        // unreferenced and its half of a chain looks broken. Report the load
        // violations alone.
        if (!$loaded->isValid()) {
            return RunReport::faulted($loaded->violations, $checks, $interruption);
        }

        $violations = $this->validator->validate($loaded->ruleSet);

        if ($violations !== []) {
            return RunReport::faulted($violations, $checks, $interruption);
        }

        $values  = $this->resolver->resolve($this->environment->raw());
        $targets = [];

        foreach ($this->walker->walk($loaded->ruleSet, $values) as $state) {
            $targets[] = new TargetReport(
                $state,
                $this->history->lastWriteFor($state->targetPath),
                ReportedValue::none(self::NO_BACKUP)
            );
        }

        // Damaged records are a fault about the package's own history, not about the
        // site, so they travel beside the targets the walk just reported. The two
        // faulted returns above do not ask: that run never reached the log.
        return RunReport::of($targets, $checks, $interruption, $this->history->recordFaults());
    }
}
