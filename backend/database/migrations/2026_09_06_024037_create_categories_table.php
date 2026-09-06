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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->integer('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();  // deleted_at を作る

            // MySQL の UNIQUE は NULL を重複とみなさない性質を利用し、
            // 「有効な行だけ」に一意性を効かせる（DB設計書 §1.4）。
            // 生成列は deleted_at を参照するため、softDeletes() より後に定義する。
            $table->string('name_unique_key', 100)->nullable()
                  ->storedAs('if(deleted_at is null, name, null)');
            $table->unique('name_unique_key', 'uq_categories_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
