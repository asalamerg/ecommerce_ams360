<?php

namespace App\Console\Commands;

use App\Support\ProductSearchIndex;
use Illuminate\Console\Command;

class ReindexProductSearch extends Command
{
    protected $signature = 'store:reindex-search';

    protected $description = 'Rebuild the searchable text of every product';

    public function handle(): int
    {
        $this->info('Indexed '.ProductSearchIndex::rebuild().' products.');

        return self::SUCCESS;
    }
}
