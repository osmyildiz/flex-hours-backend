<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->decimal('hours_worked', 5, 2);
            $table->decimal('total_hours', 5, 2)->nullable();
            $table->decimal('earnings', 8, 2);
            $table->decimal('miles', 8, 2)->nullable();
            $table->decimal('gas_cost', 6, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_entries');
    }
};
