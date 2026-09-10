<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('systems', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('icon', 50)->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // `key` は MySQL の予約語。storedAs の中は生の SQL として解釈されるためバッククォートで囲む
            $table->string('key_unique_key', 50)->nullable()
                  ->storedAs('if(deleted_at is null, `key`, null)');
            $table->unique('key_unique_key', 'uq_systems_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('systems');
    }
};
