<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('salary_processes') && !Schema::hasColumn('salary_processes', 'ot_coinage_breakdown')) {
            Schema::table('salary_processes', function (Blueprint $table) {
                $table->json('ot_coinage_breakdown')->nullable()->after('coinage_breakdown');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('salary_processes') && Schema::hasColumn('salary_processes', 'ot_coinage_breakdown')) {
            Schema::table('salary_processes', function (Blueprint $table) {
                $table->dropColumn('ot_coinage_breakdown');
            });
        }
    }
};

