<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('work_entries', function (Blueprint $table) {
            // Performance indexes - sadece bunlar gerekli
            $table->index(['user_id', 'date'], 'idx_user_date');
            $table->index('date', 'idx_date');
            $table->index('service_type', 'idx_service_type');
            $table->index(['user_id', 'created_at'], 'idx_user_created');
        });
    }

    public function down()
    {
        Schema::table('work_entries', function (Blueprint $table) {
            $table->dropIndex('idx_user_date');
            $table->dropIndex('idx_date');
            $table->dropIndex('idx_service_type');
            $table->dropIndex('idx_user_created');
        });
    }
};
