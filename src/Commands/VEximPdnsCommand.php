<?php

namespace VEximweb\Plugin\PDNS\Commands;

use Illuminate\Console\Command;

class VEximPdnsCommand extends Command
{
    public $signature = 'vexim-pdns';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
