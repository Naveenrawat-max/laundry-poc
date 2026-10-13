<?php
/** Builds the 13 tables by running db/schema.sql as it is. That file stays the one description of the database. */
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        run_sql_file(base_path('../db/schema.sql'));
    }
};
