<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('mrcache.outbox.table', 'mrcache_outbox'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->text('dependencies');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('created_at');
            $table->index(['attempts', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mrcache.outbox.table', 'mrcache_outbox'));
    }
};
