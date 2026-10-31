<?php

namespace App\Console\Commands;

use App\Support\SystemDoctor;
use Illuminate\Console\Command;

/** Post-deploy checklist (App\Support\SystemDoctor). Exit code 1 when any check fails; warnings do not fail. */
class DoctorCommand extends Command
{
    protected $signature = 'egmenu:doctor';

    protected $description = 'Check the things a human needs to verify after a deploy';

    public function handle(SystemDoctor $doctor): int
    {
        $failures = 0;
        foreach ($doctor->checks() as ['label' => $label, 'ok' => $ok, 'hint' => $hint, 'warn' => $warn]) {
            if ($ok) {
                $this->line("  <fg=green>OK</>    $label".($hint && ! str_starts_with($hint, 'run') ? " ($hint)" : ''));
            } elseif ($warn) {
                $this->line("  <fg=yellow>WARN</>  $label".($hint ? " - $hint" : ''));
            } else {
                $failures++;
                $this->line("  <fg=red>FAIL</>  $label".($hint ? " - $hint" : ''));
            }
        }

        $this->newLine();
        $this->line($failures ? "<fg=red>$failures check(s) failed</>" : '<fg=green>All good</>');

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
