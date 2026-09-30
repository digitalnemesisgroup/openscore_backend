<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $safeAddIndex = function ($table, $columns, $name = null) {
            try {
                Schema::table($table, function (Blueprint $t) use ($columns, $name) {
                    $t->index($columns, $name);
                });
            } catch (QueryException $e) {
                // Ignore MySQL 1061 duplicate key / index name errors gracefully
                if ($e->getCode() !== '42000' && !str_contains($e->getMessage(), 'Duplicate key name')) {
                    throw $e;
                }
            }
        };

        // loan_applications indexes (excluding user_id & application_number which are already indexed in table creation)
        $safeAddIndex('loan_applications', 'status');
        $safeAddIndex('loan_applications', 'final_decision');
        $safeAddIndex('loan_applications', 'disbursement_status');
        $safeAddIndex('loan_applications', 'loan_type');
        $safeAddIndex('loan_applications', 'reapply_locked_until');
        $safeAddIndex('loan_applications', 'created_at');
        $safeAddIndex('loan_applications', ['user_id', 'status']);
        $safeAddIndex('loan_applications', ['status', 'final_decision']);

        // users indexes
        $safeAddIndex('users', 'mobile');
        $safeAddIndex('users', 'role');
        $safeAddIndex('users', 'created_at');

        // smtp_pools indexes
        $safeAddIndex('smtp_pools', ['purpose', 'is_active', 'last_dispatched_at']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['final_decision']);
            $table->dropIndex(['disbursement_status']);
            $table->dropIndex(['loan_type']);
            $table->dropIndex(['reapply_locked_until']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['status', 'final_decision']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['mobile']);
            $table->dropIndex(['role']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('smtp_pools', function (Blueprint $table) {
            $table->dropIndex(['purpose', 'is_active', 'last_dispatched_at']);
        });
    }
};
